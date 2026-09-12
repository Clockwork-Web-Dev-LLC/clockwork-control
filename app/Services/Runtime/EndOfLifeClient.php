<?php

namespace App\Services\Runtime;

use App\Support\Settings;
use Illuminate\Support\Facades\Http;

/**
 * Pulls software lifecycle / EOL data from the endoflife.date v1 public API.
 *
 * Endpoints:
 * - https://endoflife.date/api/v1/products/php
 * - https://endoflife.date/api/v1/products/wordpress
 */
class EndOfLifeClient
{
    public const PHP_URL = 'https://endoflife.date/api/v1/products/php';

    public const WORDPRESS_URL = 'https://endoflife.date/api/v1/products/wordpress';

    private const TIMEOUT_SECONDS = 15;

    private const USER_AGENT = 'Clockwork-Monitoring/1.0 (+https://clockworkcontrol.com)';

    /**
     * @return array<string, array{name: string, label: string, release_date: ?string, is_eoas: bool, eoas_from: ?string, is_eol: bool, eol_from: ?string, is_maintained: bool}>|null
     */
    public function fetchProduct(string $url): ?array
    {
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withUserAgent(self::USER_AGENT)
            ->retry(2, 500, throw: false)
            ->get($url);

        if ($response->failed()) {
            return null;
        }

        $data = $response->json();
        if (! is_array($data) || ! isset($data['result']['releases']) || ! is_array($data['result']['releases'])) {
            return null;
        }

        $cycles = [];
        foreach ($data['result']['releases'] as $rel) {
            if (! is_array($rel)) {
                continue;
            }
            $name = trim((string) ($rel['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $cycles[$name] = [
                'name' => $name,
                'label' => (string) ($rel['label'] ?? $name),
                'release_date' => ! empty($rel['releaseDate']) ? (string) $rel['releaseDate'] : null,
                'is_eoas' => (bool) ($rel['isEoas'] ?? false),
                'eoas_from' => ! empty($rel['eoasFrom']) ? (string) $rel['eoasFrom'] : null,
                'is_eol' => (bool) ($rel['isEol'] ?? false),
                'eol_from' => ! empty($rel['eolFrom']) ? (string) $rel['eolFrom'] : null,
                'is_maintained' => (bool) ($rel['isMaintained'] ?? false),
            ];
        }

        return $cycles;
    }

    /**
     * Sync lifecycle cycles into app_settings via Settings facade.
     *
     * @return array{ok: bool, php_count: int, wp_count: int, error: ?string}
     */
    public function refresh(Settings $settings): array
    {
        $php = $this->fetchProduct(self::PHP_URL);
        $wp = $this->fetchProduct(self::WORDPRESS_URL);

        if ($php === null && $wp === null) {
            return [
                'ok' => false,
                'php_count' => 0,
                'wp_count' => 0,
                'error' => 'Failed to fetch runtime EOL data from endoflife.date for both PHP and WordPress',
            ];
        }

        if ($php !== null) {
            $settings->put('runtime_eol.php_cycles', $php);
        }

        if ($wp !== null) {
            $settings->put('runtime_eol.wordpress_cycles', $wp);
        }

        // Only bump the freshness marker when every product fetched successfully;
        // a partial refresh must not mask staleness of the product that failed.
        if ($php !== null && $wp !== null) {
            $settings->put('runtime_eol.fetched_at', now()->toIso8601String());
        }

        return [
            'ok' => true,
            'php_count' => $php !== null ? count($php) : 0,
            'wp_count' => $wp !== null ? count($wp) : 0,
            'error' => null,
        ];
    }
}
