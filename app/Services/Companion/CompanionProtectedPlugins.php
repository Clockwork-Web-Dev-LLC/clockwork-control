<?php

namespace App\Services\Companion;

use RuntimeException;

/**
 * Plugins the control plane must never deactivate or delete.
 *
 * Connector plugins brick remote management. Checkout / security / object-cache
 * plugins take production down if toggled from a fleet action by mistake.
 */
class CompanionProtectedPlugins
{
    /**
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

    public static function contains(string $slug): bool
    {
        $normalized = strtolower(str_replace('\\', '/', trim($slug)));
        foreach (self::SLUGS as $protected) {
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
