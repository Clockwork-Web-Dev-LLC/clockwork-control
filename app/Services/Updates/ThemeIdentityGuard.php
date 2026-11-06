<?php

namespace App\Services\Updates;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Tells whether an installed theme is the theme an update would install.
 *
 * WordPress asks wordpress.org about every installed theme by folder name
 * (slug). A custom theme whose folder shares its name with an unrelated
 * directory theme is offered that theme's releases as "updates", and
 * applying one replaces the custom theme wholesale. That is how the nightly
 * loop once overwrote a custom child theme with an unrelated wordpress.org
 * theme of the same folder name and took the site down.
 *
 * The check, in order:
 *   1. wordpress.org lists a theme with this slug: the installed theme must
 *      carry the same name (case, punctuation, entities and a trailing
 *      "Theme" ignored). Another name means a different theme.
 *   2. Not listed (a premium theme's own updater makes the offer) or the
 *      directory could not be reached: refuse a child theme, which never
 *      gets updates of its own. The snapshot has no parent field today
 *      (themes.items carries name, slug, version, new_version, active,
 *      auto_update, update_available), so a "template"/"parent" field is
 *      honoured when a Companion sends one, and otherwise a name with the
 *      word "child" in it marks a child theme.
 *
 * Directory answers are kept for the life of the instance, so one nightly
 * run asks about each slug once however many sites have it.
 */
class ThemeIdentityGuard
{
    /** plugin_update_jobs.reason_code of an update skipped by this guard. */
    public const REASON_CODE = 'identity_mismatch';

    public const DIRECTORY_URL = 'https://api.wordpress.org/themes/info/1.2/';

    private const TIMEOUT_SECONDS = 8;

    private const LISTED = 'listed';

    private const NOT_LISTED = 'not_listed';

    private const UNKNOWN = 'unknown';

    /** @var array<string, array{status: string, name: ?string, version: ?string}> */
    private array $listings = [];

    /**
     * Why updating this theme would put a different theme in its place, or
     * null when it looks like the theme on offer.
     *
     * @param  array<string, mixed>  $theme  one companion_snapshot themes.items entry
     */
    public function mismatchReason(array $theme): ?string
    {
        $slug = trim((string) ($theme['slug'] ?? ''));
        if ($slug === '') {
            return null;
        }

        $name = self::displayName($theme['name'] ?? '');
        $installed = $name !== '' ? "\"{$name}\"" : 'with no name';
        $offered = trim((string) ($theme['new_version'] ?? ''));
        $offeredLabel = $offered !== '' ? $offered : 'the offered';

        $listing = $this->listing($slug);
        if ($listing['status'] === self::LISTED) {
            $listedName = self::displayName($listing['name'] ?? '');
            if (self::sameName($name, $listedName)) {
                return null;
            }

            return sprintf(
                'The installed theme is %s, but the wordpress.org theme in the folder "%s" is "%s"%s. Updating to %s would replace the installed theme with that unrelated theme.',
                $installed,
                $slug,
                $listedName,
                $listing['version'] ? " (latest {$listing['version']})" : '',
                $offeredLabel,
            );
        }

        $parent = trim((string) ($theme['template'] ?? $theme['parent'] ?? ''));
        if ($parent !== '' && $parent !== $slug) {
            return sprintf(
                'The installed theme %s is a child theme of "%s". A child theme gets no updates of its own, so the %s package is a different theme that shares the folder name "%s".',
                $installed,
                $parent,
                $offeredLabel,
                $slug,
            );
        }

        if (preg_match('/\bchild\b/i', $name) === 1) {
            return sprintf(
                'The installed theme %s is named as a child theme. A child theme gets no updates of its own, so the %s package is most likely a different theme that shares the folder name "%s".',
                $installed,
                $offeredLabel,
                $slug,
            );
        }

        return null;
    }

    /**
     * Names compared the way a directory slug is derived from a theme name:
     * entities decoded, lower case, a trailing word "theme" and everything
     * but letters and digits dropped. "Twenty Twenty-Four" and
     * "twenty twenty four" match; "Studio Child Theme" and "Studio" don't.
     */
    public static function sameName(string $a, string $b): bool
    {
        $normalize = function (string $name): string {
            $n = mb_strtolower(self::displayName($name));
            $n = (string) preg_replace('/\s+theme\s*$/u', '', $n);

            return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $n);
        };

        $na = $normalize($a);

        return $na !== '' && $na === $normalize($b);
    }

    private static function displayName(mixed $name): string
    {
        return trim(html_entity_decode((string) $name, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * @return array{status: string, name: ?string, version: ?string}
     */
    private function listing(string $slug): array
    {
        return $this->listings[$slug] ??= $this->fetchListing($slug);
    }

    /**
     * wordpress.org answers an unknown slug with an error body (usually with
     * HTTP 404), so the body decides, not the status code alone.
     *
     * @return array{status: string, name: ?string, version: ?string}
     */
    private function fetchListing(string $slug): array
    {
        $unknown = ['status' => self::UNKNOWN, 'name' => null, 'version' => null];

        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->retry(2, 500, function (Throwable $exception): bool {
                    return $exception instanceof ConnectionException
                        || ($exception instanceof RequestException && $exception->response->serverError());
                }, throw: false)
                ->withHeaders(['User-Agent' => 'Clockwork-Monitoring/1.0 (+theme-identity-check)'])
                ->get(self::DIRECTORY_URL, [
                    'action' => 'theme_information',
                    'request' => ['slug' => $slug],
                ]);
        } catch (Throwable) {
            return $unknown;
        }

        if ($response->serverError()) {
            return $unknown;
        }

        $body = $response->json();
        if (is_array($body) && isset($body['error'])) {
            return ['status' => self::NOT_LISTED, 'name' => null, 'version' => null];
        }

        if ($response->successful() && is_array($body) && is_string($body['name'] ?? null) && trim($body['name']) !== '') {
            return [
                'status' => self::LISTED,
                'name' => $body['name'],
                'version' => is_scalar($body['version'] ?? null) ? (string) $body['version'] : null,
            ];
        }

        if ($response->status() === 404) {
            return ['status' => self::NOT_LISTED, 'name' => null, 'version' => null];
        }

        return $unknown;
    }
}
