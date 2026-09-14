<?php

namespace App\Services\Companion;

use App\Support\Settings;
use RuntimeException;

/**
 * Plugins the control plane must never deactivate or delete.
 *
 * Connector slugs are always protected. The rest of the list is stored in
 * Settings (`companion.protected_plugins`) so operators can add checkout /
 * security / cache plugins without a deploy.
 */
class CompanionProtectedPlugins
{
    public const SETTING_KEY = 'companion.protected_plugins';

    /**
     * @var list<string>
     */
    public const CONNECTOR_SLUGS = [
        'clockwork-companion/clockwork-companion.php',
        'clockwork-companion.php',
        'clockwork-renegade/clockwork-renegade.php',
        'clockwork-renegade.php',
    ];

    /**
     * @var list<string>
     */
    public const DEFAULT_SLUGS = [
        'woocommerce/woocommerce.php',
        'wordfence/wordfence.php',
        'wordfence-login-security/wordfence-login-security.php',
        'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php',
        'redis-cache/redis-cache.php',
        'object-cache-pro/object-cache-pro.php',
        'nginx-helper/nginx-helper.php',
        'litespeed-cache/litespeed-cache.php',
        'wp-super-cache/wp-cache.php',
        'sucuri-scanner/sucuri.php',
    ];

    /**
     * Historical default union. Runtime callers should use slugs().
     *
     * @var list<string>
     */
    public const SLUGS = [
        'clockwork-companion/clockwork-companion.php',
        'clockwork-companion.php',
        'clockwork-renegade/clockwork-renegade.php',
        'clockwork-renegade.php',
        'woocommerce/woocommerce.php',
        'wordfence/wordfence.php',
        'wordfence-login-security/wordfence-login-security.php',
        'limit-login-attempts-reloaded/limit-login-attempts-reloaded.php',
        'redis-cache/redis-cache.php',
        'object-cache-pro/object-cache-pro.php',
        'nginx-helper/nginx-helper.php',
        'litespeed-cache/litespeed-cache.php',
        'wp-super-cache/wp-cache.php',
        'sucuri-scanner/sucuri.php',
    ];

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        $merged = array_merge(self::CONNECTOR_SLUGS, self::configuredOperationalSlugs());

        return self::normalizeList($merged);
    }

    /**
     * @return list<string>
     */
    public static function configuredOperationalSlugs(): array
    {
        $raw = null;
        if (function_exists('app') && app()->bound(Settings::class)) {
            $raw = app(Settings::class)->get(self::SETTING_KEY, null);
        }

        if ($raw === null) {
            return self::DEFAULT_SLUGS;
        }

        if (! is_array($raw)) {
            return self::DEFAULT_SLUGS;
        }

        return self::normalizeList($raw);
    }

    /**
     * @param  list<string>|string  $input
     * @return list<string>
     */
    public static function normalizeList(array|string $input): array
    {
        $lines = is_array($input) ? $input : preg_split('/[\r\n,]+/', $input) ?: [];
        $out = [];
        foreach ($lines as $line) {
            $slug = strtolower(str_replace('\\', '/', trim((string) $line)));
            if ($slug === '' || str_starts_with($slug, '#')) {
                continue;
            }
            if (! preg_match('~^(?:[a-z0-9][a-z0-9._-]*/)?[a-z0-9][a-z0-9._-]*\.php$~', $slug)) {
                continue;
            }
            $out[] = $slug;
        }

        return array_values(array_unique($out));
    }

    public static function contains(string $slug): bool
    {
        $normalized = strtolower(str_replace('\\', '/', trim($slug)));
        foreach (self::slugs() as $protected) {
            if (strcasecmp($normalized, $protected) === 0) {
                return true;
            }
        }

        $base = basename($normalized);

        return in_array($base, [
            'clockwork-companion.php',
            'clockwork-renegade.php',
        ], true);
    }

    public static function guardDestructive(string $slug, string $action): void
    {
        if (! in_array($action, ['deactivate', 'delete'], true)) {
            return;
        }

        if (! self::contains($slug)) {
            return;
        }

        throw new RuntimeException("Refusing to {$action} protected plugin '{$slug}'.");
    }
}
