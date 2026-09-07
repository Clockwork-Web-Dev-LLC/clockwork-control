<?php

namespace Modules\CodeSnippets\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property ?string $description
 * @property string $code
 * @property bool $is_preset
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class CodeSnippet extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'code',
        'is_preset',
    ];

    protected function casts(): array
    {
        return [
            'is_preset' => 'boolean',
        ];
    }

    /**
     * Default standard presets for WordPress maintenance.
     *
     * @return array<int, array{name: string, description: string, code: string}>
     */
    public static function defaultPresets(): array
    {
        return [
            [
                'name' => 'Flush Object Cache & Transients',
                'description' => 'Flushes external object cache (Redis/Memcached) and purges all expired and active transients from the options table.',
                'code' => <<<'PHP'
$flushedCache = function_exists('wp_cache_flush') ? wp_cache_flush() : false;
global $wpdb;
$expiredCount = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_%' OR option_name LIKE '_site_transient_timeout_%'");
$transientCount = $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
return [
    'object_cache_flushed' => $flushedCache,
    'transients_deleted' => (int) $transientCount,
    'timeouts_deleted' => (int) $expiredCount,
];
PHP
            ],
            [
                'name' => 'Flush Rewrite Rules (Permalinks)',
                'description' => 'Regenerates rewrite rules and flushes permalink structures without modifying .htaccess.',
                'code' => <<<'PHP'
global $wp_rewrite;
if (isset($wp_rewrite)) {
    $wp_rewrite->flush_rules(false);
    return ['ok' => true, 'message' => 'Rewrite rules flushed successfully (soft flush).'];
}
return ['ok' => false, 'error' => 'wp_rewrite global unavailable.'];
PHP
            ],
            [
                'name' => 'PHP & Server Limits Snapshot',
                'description' => 'Reads current PHP runtime configuration, memory limits, and MySQL version.',
                'code' => <<<'PHP'
global $wpdb;
return [
    'php_version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'wp_memory_limit' => defined('WP_MEMORY_LIMIT') ? WP_MEMORY_LIMIT : null,
    'wp_max_memory_limit' => defined('WP_MAX_MEMORY_LIMIT') ? WP_MAX_MEMORY_LIMIT : null,
    'max_execution_time' => ini_get('max_execution_time'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'post_max_size' => ini_get('post_max_size'),
    'db_version' => $wpdb->db_version(),
    'site_url' => get_site_url(),
    'home_url' => get_home_url(),
];
PHP
            ],
            [
                'name' => 'Count Users by Role',
                'description' => 'Lists total registered users breakdown by capability role.',
                'code' => <<<'PHP'
$result = count_users();
return [
    'total_users' => (int) ($result['total_users'] ?? 0),
    'roles' => $result['avail_roles'] ?? [],
];
PHP
            ],
            [
                'name' => 'List Next 10 WP-Cron Events',
                'description' => 'Inspects wp_next_scheduled timestamps and next pending cron hooks.',
                'code' => <<<'PHP'
$crons = _get_cron_array();
if (! is_array($crons)) {
    return ['events' => [], 'count' => 0];
}
$list = [];
foreach ($crons as $timestamp => $hooks) {
    foreach ($hooks as $hook => $data) {
        $list[] = [
            'hook' => $hook,
            'time' => date('Y-m-d H:i:s', $timestamp),
            'due_in_seconds' => $timestamp - time(),
        ];
        if (count($list) >= 10) {
            break 2;
        }
    }
}
return ['events' => $list, 'total_pending' => count($crons)];
PHP
            ],
        ];
    }
}
