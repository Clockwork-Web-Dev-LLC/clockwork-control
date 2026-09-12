<?php

namespace App\Services\Security;

use App\Models\PluginDirectoryStatus;
use App\Models\Site;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

class PluginDirectoryClient
{
    public const BASE_URL = 'https://api.wordpress.org/plugins/info/1.2/';

    public const PER_REQUEST_TIMEOUT = 10;

    public const DEFAULT_REQUEST_DELAY_MS = 100;

    public static int $requestDelayMs = self::DEFAULT_REQUEST_DELAY_MS;

    /**
     * Collect unique plugin directory-slugs across all active, non-archived fleet sites.
     *
     * @return list<string>
     */
    public function discoverSlugs(): array
    {
        $slugs = [];

        Site::query()
            ->where('is_inactive', false)
            ->whereNotNull('companion_snapshot')
            ->select('id', 'companion_snapshot')
            ->orderBy('id')
            ->chunk(50, function ($sites) use (&$slugs) {
                foreach ($sites as $site) {
                    $snap = is_array($site->companion_snapshot)
                        ? $site->companion_snapshot
                        : json_decode((string) $site->companion_snapshot, true);

                    if (! is_array($snap)) {
                        continue;
                    }

                    $plugins = $snap['plugins']['plugins'] ?? null;
                    if (! is_array($plugins)) {
                        continue;
                    }

                    foreach ($plugins as $p) {
                        if (! is_array($p) || empty($p['slug'])) {
                            continue;
                        }

                        $dir = strtok((string) $p['slug'], '/');
                        if ($dir !== false) {
                            $slugs[$dir] = true;
                        }
                    }
                }
            });

        ksort($slugs);

        return array_keys($slugs);
    }

    /**
     * Fetch status for a single plugin slug from the official WordPress.org API.
     *
     * WordPress.org serves BOTH closed plugins and genuinely unknown slugs with
     * HTTP 404, so classification must be driven by the JSON body, never the
     * status code alone: {"error":"closed",...} means closed/zombieware, while
     * any other error body (e.g. "Plugin not found.") means not hosted on wp.org.
     *
     * @return array{slug: string, status: string, reason: ?string, closed_date: ?string}
     */
    public function fetchSlugStatus(string $slug): array
    {
        try {
            $response = Http::timeout(self::PER_REQUEST_TIMEOUT)
                // Only retry transient failures (connection errors, 5xx). A 404
                // is a definitive answer (closed or not hosted) — never retry it.
                ->retry(2, 500, function (Throwable $exception): bool {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response->serverError());
                }, throw: false)
                ->withHeaders([
                    'User-Agent' => 'Clockwork-Monitoring/1.0 (+plugin-directory-check)',
                ])
                ->get(self::BASE_URL, [
                    'action' => 'plugin_information',
                    'request' => ['slug' => $slug],
                ]);
        } catch (Throwable) {
            return [
                'slug' => $slug,
                'status' => PluginDirectoryStatus::STATUS_ERROR,
                'reason' => null,
                'closed_date' => null,
            ];
        }

        if ($response->serverError()) {
            return [
                'slug' => $slug,
                'status' => PluginDirectoryStatus::STATUS_ERROR,
                'reason' => null,
                'closed_date' => null,
            ];
        }

        $body = $response->json();

        if (is_array($body) && isset($body['error'])) {
            $err = strtolower(trim((string) $body['error']));
            if ($err === 'closed') {
                $reason = isset($body['description']) ? trim((string) $body['description']) : null;
                if ($reason === null || $reason === '') {
                    $reason = isset($body['reason_text']) ? trim((string) $body['reason_text']) : null;
                }

                return [
                    'slug' => $slug,
                    'status' => PluginDirectoryStatus::STATUS_CLOSED,
                    'reason' => $reason,
                    'closed_date' => isset($body['closed_date']) ? trim((string) $body['closed_date']) : null,
                ];
            }

            // Other error strings (e.g. "Plugin not found.") mean not hosted on wp.org
            return [
                'slug' => $slug,
                'status' => PluginDirectoryStatus::STATUS_NOT_FOUND,
                'reason' => null,
                'closed_date' => null,
            ];
        }

        if ($response->successful() && is_array($body) && (isset($body['slug']) || isset($body['name']))) {
            return [
                'slug' => $slug,
                'status' => PluginDirectoryStatus::STATUS_OPEN,
                'reason' => null,
                'closed_date' => null,
            ];
        }

        // Unparseable body or an unexpected status code with no error payload.
        return [
            'slug' => $slug,
            'status' => PluginDirectoryStatus::STATUS_ERROR,
            'reason' => null,
            'closed_date' => null,
        ];
    }

    /**
     * Walk unique slugs, query WordPress.org API, and persist statuses.
     *
     * @param  callable(string $slug, array<string, mixed> $status, int $idx, int $total): void|null  $onProgress
     * @param  list<string>|null  $slugs
     * @return array{total: int, closed: int, open: int, not_found: int, errors: int}
     */
    public function refresh(?callable $onProgress = null, ?array $slugs = null): array
    {
        $slugs ??= $this->discoverSlugs();
        $total = count($slugs);

        $counts = [
            'total' => $total,
            'closed' => 0,
            'open' => 0,
            'not_found' => 0,
            'errors' => 0,
        ];

        if ($total === 0) {
            return $counts;
        }

        foreach (array_values($slugs) as $i => $slug) {
            $result = $this->fetchSlugStatus($slug);
            $this->persistSlugStatus($result);

            match ($result['status']) {
                PluginDirectoryStatus::STATUS_CLOSED => $counts['closed']++,
                PluginDirectoryStatus::STATUS_OPEN => $counts['open']++,
                PluginDirectoryStatus::STATUS_NOT_FOUND => $counts['not_found']++,
                default => $counts['errors']++,
            };

            if ($onProgress) {
                $onProgress($slug, $result, $i, $total);
            }

            if (static::$requestDelayMs > 0) {
                usleep(static::$requestDelayMs * 1000);
            }
        }

        return $counts;
    }

    /**
     * Upsert slug status, preserving any prior definitive status on transient error.
     *
     * @param  array{slug: string, status: string, reason: ?string, closed_date: ?string}  $data
     */
    public function persistSlugStatus(array $data): PluginDirectoryStatus
    {
        $existing = PluginDirectoryStatus::where('slug', $data['slug'])->first();

        if ($existing) {
            // STATUS_ERROR is transient (connection failure / 5xx / garbage body) —
            // it must never clobber a definitive, body-parsed answer (open, closed,
            // or not_found). We also leave checked_at untouched: it records when the
            // stored status was last VERIFIED, and a failed check verified nothing.
            if ($data['status'] === PluginDirectoryStatus::STATUS_ERROR && ! $existing->isError()) {
                return $existing;
            }

            $existing->forceFill([
                'status' => $data['status'],
                'reason' => $data['reason'],
                'closed_date' => $data['closed_date'],
                'checked_at' => now(),
            ])->save();

            return $existing;
        }

        return PluginDirectoryStatus::create([
            'slug' => $data['slug'],
            'status' => $data['status'],
            'reason' => $data['reason'],
            'closed_date' => $data['closed_date'],
            'checked_at' => now(),
        ]);
    }
}
