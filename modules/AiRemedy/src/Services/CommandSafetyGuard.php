<?php

namespace Modules\AiRemedy\Services;

use App\Support\Settings;

class CommandSafetyGuard
{
    public const TIER_1_SAFE = 'tier_1_safe';

    public const TIER_2_CAUTIOUS = 'tier_2_cautious';

    public const TIER_3_PROHIBITED = 'tier_3_prohibited';

    /**
     * Dangerous shell patterns that MUST NEVER be executed by AI.
     * Permanent, non-negotiable security guardrails.
     */
    protected const DANGEROUS_PATTERNS = [
        '/\brm\s+(-[a-zA-Z]*r[a-zA-Z]*f?|-f[a-zA-Z]*r[a-zA-Z]*)\s+(\/|\*|~\/)/i',
        '/\brm\s+(-[a-zA-Z]*r[a-zA-Z]*)\s+\/(etc|boot|bin|sbin|usr|var|dev)\b/i',
        '/\b(curl|wget)\b[^\n|]+(\|\s*(bash|sh|zsh))/i',
        '/\bdd\s+if=/i',
        '/\bmkfs\b/i',
        '/\bfdisk\b/i',
        '/\bchmod\s+(-[a-zA-Z]*R)?\s*777\s+(\/|~\/)/i',
        '/\b(drop\s+database|drop\s+table|truncate\s+table)\b/i',
        '/\b(>\s*\/etc\/sudoers|>\s*\/etc\/passwd|>\s*\/etc\/shadow)/i',
        '/:\(\)\s*\{\s*:\|:&\s*\};:/', // Fork bomb
    ];

    /**
     * Standard configurable remediation actions that operators can organize across tiers.
     *
     * @var array<string, array{id: string, label: string, command_example: string, description: string, pattern: string, default_tier: string}>
     */
    public const ACTIONS = [
        'process_renice' => [
            'id' => 'process_renice',
            'label' => 'Deprioritize CPU Priority (renice)',
            'command_example' => 'sudo renice -n 19 -p <PID>',
            'description' => 'Lowers process CPU priority so heavy background tasks yield cycles to web & DB traffic.',
            'pattern' => '/^sudo\s+renice\s+(-n\s+)?(\+?\d+)\s+(-p\s+)?\d+$/i',
            'default_tier' => self::TIER_1_SAFE,
        ],
        'process_ionice' => [
            'id' => 'process_ionice',
            'label' => 'Deprioritize Disk I/O (ionice)',
            'command_example' => 'sudo ionice -c 3 -p <PID>',
            'description' => 'Sets disk I/O scheduling to Idle class 3, preventing disk bandwidth saturation.',
            'pattern' => '/^sudo\s+ionice\s+(-c\s+[123])\s+(-p\s+)?\d+$/i',
            'default_tier' => self::TIER_1_SAFE,
        ],
        'reload_web_services' => [
            'id' => 'reload_web_services',
            'label' => 'Reload Web & PHP Services',
            'command_example' => 'sudo systemctl reload nginx / php8.x-fpm',
            'description' => 'Gracefully reloads worker processes without dropping active HTTP connections.',
            'pattern' => '/^sudo\s+(systemctl\s+(reload|status)\s+([a-zA-Z0-9_\-\.]+)|service\s+([a-zA-Z0-9_\-\.]+)\s+(reload|status))$/i',
            'default_tier' => self::TIER_1_SAFE,
        ],
        'restart_web_services' => [
            'id' => 'restart_web_services',
            'label' => 'Restart Web & PHP Services',
            'command_example' => 'sudo systemctl restart nginx / php8.x-fpm',
            'description' => 'Full restart of web server or PHP-FPM fastcgi pool manager.',
            'pattern' => '/^sudo\s+(systemctl\s+restart\s+(nginx|apache2|php[0-9\.]*-fpm)|service\s+(nginx|apache2|php[0-9\.]*-fpm)\s+restart)$/i',
            'default_tier' => self::TIER_1_SAFE,
        ],
        'nginx_config_test' => [
            'id' => 'nginx_config_test',
            'label' => 'Test Nginx Configuration Syntax',
            'command_example' => 'sudo nginx -t',
            'description' => 'Non-mutating syntax preflight check of Nginx vhosts and SSL configs.',
            'pattern' => '/^sudo\s+nginx\s+-t$/i',
            'default_tier' => self::TIER_1_SAFE,
        ],
        'clear_maintenance_flag' => [
            'id' => 'clear_maintenance_flag',
            'label' => 'Clear WordPress .maintenance Flag',
            'command_example' => 'rm -f /path/to/site/.maintenance',
            'description' => 'Deletes stuck maintenance lock file left behind by crashed updates.',
            'pattern' => '/^rm\s+(-f\s+)?([a-zA-Z0-9_\-\.\/]+)\/\.maintenance$/i',
            'default_tier' => self::TIER_1_SAFE,
        ],
        'wp_cache_flush' => [
            'id' => 'wp_cache_flush',
            'label' => 'Flush WordPress Object Cache & Transients',
            'command_example' => 'wp cache flush / wp transient delete --all',
            'description' => 'Purges Redis, Memcached, and transient cache tables via WP-CLI.',
            'pattern' => '/^(sudo\s+-u\s+[a-zA-Z0-9_\-\.]+\s+)?wp\s+(cache\s+flush|transient\s+delete(\s+--all)?)((\s+--path=[\'"]?[a-zA-Z0-9_\-\.\/]+[\'"]?|\s+--allow-root))*$/i',
            'default_tier' => self::TIER_1_SAFE,
        ],
        'logrotate_flush' => [
            'id' => 'logrotate_flush',
            'label' => 'Trigger Emergency Log Rotation',
            'command_example' => 'sudo logrotate -f /etc/logrotate.d/nginx',
            'description' => 'Forces rotation and compression of runaway log files filling disk space.',
            'pattern' => '/^sudo\s+logrotate(\s+-f)?(\s+\/etc\/logrotate\.d\/[a-zA-Z0-9_\-]+)?$/i',
            'default_tier' => self::TIER_1_SAFE,
        ],
        'graceful_kill' => [
            'id' => 'graceful_kill',
            'label' => 'Graceful Process Termination (kill -15 / SIGTERM)',
            'command_example' => 'sudo kill -15 <PID>',
            'description' => 'Requests a runaway or looping worker process to shut down cleanly.',
            'pattern' => '/^sudo\s+kill\s+(-15|-TERM)\s+\d+$/i',
            'default_tier' => self::TIER_1_SAFE,
        ],
        'restart_database' => [
            'id' => 'restart_database',
            'label' => 'Restart Database (MySQL / MariaDB)',
            'command_example' => 'sudo systemctl restart mysql',
            'description' => 'Restarts the database daemon. May briefly disrupt in-flight SQL queries.',
            'pattern' => '/^sudo\s+(systemctl\s+restart\s+(mysql|mariadb)|service\s+(mysql|mariadb)\s+restart)$/i',
            'default_tier' => self::TIER_2_CAUTIOUS,
        ],
        'force_kill' => [
            'id' => 'force_kill',
            'label' => 'Forced Process Kill (kill -9 / SIGKILL)',
            'command_example' => 'sudo kill -9 <PID>',
            'description' => 'Immediately terminates an unkillable hung or zombie process.',
            'pattern' => '/^sudo\s+kill\s+(-9|-KILL)\s+\d+$/i',
            'default_tier' => self::TIER_2_CAUTIOUS,
        ],
        'wp_plugin_toggle' => [
            'id' => 'wp_plugin_toggle',
            'label' => 'Deactivate Crashing WordPress Plugin',
            'command_example' => 'wp plugin deactivate <slug>',
            'description' => 'Deactivates an active plugin causing fatal PHP errors or memory exhaustion.',
            'pattern' => '/^(sudo\s+-u\s+[a-zA-Z0-9_\-\.]+\s+)?wp\s+plugin\s+(activate|deactivate|status)(\s+[a-zA-Z0-9_\-]+)?((\s+--path=[\'"]?[a-zA-Z0-9_\-\.\/]+[\'"]?|\s+--allow-root))*$/i',
            'default_tier' => self::TIER_2_CAUTIOUS,
        ],
        'service_stop_start' => [
            'id' => 'service_stop_start',
            'label' => 'Stop or Start System Services',
            'command_example' => 'sudo systemctl stop <service>',
            'description' => 'Stops or starts system daemons directly.',
            'pattern' => '/^sudo\s+(systemctl\s+(stop|start)\s+([a-zA-Z0-9_\-\.]+)|service\s+([a-zA-Z0-9_\-\.]+)\s+(stop|start))$/i',
            'default_tier' => self::TIER_2_CAUTIOUS,
        ],
    ];

    /**
     * Unmovable security guardrails permanently locked in Tier 3 Prohibited.
     *
     * @var array<int, array{label: string, pattern: string, reason: string}>
     */
    public const LOCKED_PROHIBITED = [
        [
            'label' => 'Destructive Filesystem Deletion',
            'pattern' => 'rm -rf / or deletion of system directories (/etc, /boot, /bin, /var)',
            'reason' => 'Permanent security guardrail',
        ],
        [
            'label' => 'Storage Format & Partition Manipulation',
            'pattern' => 'mkfs, fdisk, dd if=, wiping block devices',
            'reason' => 'Permanent security guardrail',
        ],
        [
            'label' => 'Database Destruction',
            'pattern' => 'DROP DATABASE, DROP TABLE, TRUNCATE TABLE',
            'reason' => 'Permanent security guardrail',
        ],
        [
            'label' => 'Remote Shell Execution & Piping',
            'pattern' => 'curl ... | bash, wget ... | sh, subshell pipelines (; & | ` $)',
            'reason' => 'Permanent security guardrail',
        ],
        [
            'label' => 'System Privilege Compromise',
            'pattern' => 'chmod 777, writing directly to /etc/sudoers, /etc/passwd',
            'reason' => 'Permanent security guardrail',
        ],
    ];

    public function __construct(
        protected ?Settings $settings = null,
    ) {
        $this->settings ??= app(Settings::class);
    }

    /**
     * Get the configured tier rules saved by the operator.
     *
     * @return array<string, string>
     */
    public function getTierRules(): array
    {
        $rules = $this->settings?->get('clockwork.ai_remedy.safety_tier_rules');

        if (is_array($rules)) {
            return $rules;
        }

        if (is_string($rules) && $rules !== '') {
            $decoded = json_decode($rules, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return [];
    }

    /**
     * Return all configurable remediation actions with their effective tier.
     *
     * @return array<string, array{id: string, label: string, command_example: string, description: string, pattern: string, default_tier: string, tier: string}>
     */
    public function getActionsCatalog(): array
    {
        $customRules = $this->getTierRules();
        $catalog = [];

        foreach (self::ACTIONS as $key => $action) {
            $catalog[$key] = array_merge($action, [
                'tier' => $customRules[$key] ?? $action['default_tier'],
            ]);
        }

        return $catalog;
    }

    /**
     * Evaluate a single bash command against permanent security guardrails and agency tier policy.
     *
     * @return array{allowed: bool, tier: string, reason: string}
     */
    public function evaluate(string $command): array
    {
        $trimmed = trim($command);

        if ($trimmed === '') {
            return ['allowed' => false, 'tier' => self::TIER_3_PROHIBITED, 'reason' => 'Empty command'];
        }

        // Check for path traversal attempts
        if (str_contains($trimmed, '..')) {
            return [
                'allowed' => false,
                'tier' => self::TIER_3_PROHIBITED,
                'reason' => 'Command contains directory traversal (..).',
            ];
        }

        // Check for shell chaining, subshells, backticks, redirection, and pipes
        if (preg_match('/[;&|`$><()]/', $trimmed) || str_contains($trimmed, "\n") || str_contains($trimmed, "\r")) {
            return [
                'allowed' => false,
                'tier' => self::TIER_3_PROHIBITED,
                'reason' => 'Command contains prohibited shell chaining, piping, redirection, or subshell characters.',
            ];
        }

        // Check against permanent strictly prohibited patterns
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return [
                    'allowed' => false,
                    'tier' => self::TIER_3_PROHIBITED,
                    'reason' => 'Command contains prohibited destructive shell tokens.',
                ];
            }
        }

        // Check against configurable actions catalog and resolve effective tier
        $customRules = $this->getTierRules();

        foreach (self::ACTIONS as $key => $action) {
            if (preg_match($action['pattern'], $trimmed)) {
                $effectiveTier = $customRules[$key] ?? $action['default_tier'];

                return match ($effectiveTier) {
                    self::TIER_1_SAFE => [
                        'allowed' => true,
                        'tier' => self::TIER_1_SAFE,
                        'reason' => "Command matches {$action['label']} (Tier 1 Safe).",
                    ],
                    self::TIER_2_CAUTIOUS => [
                        'allowed' => true,
                        'tier' => self::TIER_2_CAUTIOUS,
                        'reason' => "Command matches {$action['label']} (Tier 2 Cautious — requires approval).",
                    ],
                    default => [
                        'allowed' => false,
                        'tier' => self::TIER_3_PROHIBITED,
                        'reason' => "Command matches {$action['label']} (Configured as Tier 3 Prohibited).",
                    ],
                };
            }
        }

        // Default-deny: Any command not matching an action on the approved allowlist is prohibited
        return [
            'allowed' => false,
            'tier' => self::TIER_3_PROHIBITED,
            'reason' => 'Command is not on the approved administrative allowlist.',
        ];
    }

    /**
     * Evaluate an array of commands.
     *
     * @param  array<int, string>  $commands
     * @return array{allowed: bool, highest_tier: string, rejected_commands: array<int, string>}
     */
    public function evaluateBatch(array $commands): array
    {
        $rejected = [];
        $highestTier = self::TIER_1_SAFE;

        foreach ($commands as $cmd) {
            $result = $this->evaluate($cmd);
            if (! $result['allowed']) {
                $rejected[] = $cmd;
                $highestTier = self::TIER_3_PROHIBITED;
            } elseif ($result['tier'] === self::TIER_2_CAUTIOUS && $highestTier !== self::TIER_3_PROHIBITED) {
                $highestTier = self::TIER_2_CAUTIOUS;
            }
        }

        return [
            'allowed' => empty($rejected),
            'highest_tier' => $highestTier,
            'rejected_commands' => $rejected,
        ];
    }
}
