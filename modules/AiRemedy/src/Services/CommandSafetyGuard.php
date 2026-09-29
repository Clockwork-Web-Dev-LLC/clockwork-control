<?php

namespace Modules\AiRemedy\Services;

class CommandSafetyGuard
{
    public const TIER_1_SAFE = 'tier_1_safe';

    public const TIER_2_CAUTIOUS = 'tier_2_cautious';

    public const TIER_3_PROHIBITED = 'tier_3_prohibited';

    /**
     * Dangerous shell patterns that MUST NEVER be executed by AI.
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
     * Strict end-to-end anchored patterns eligible for Tier 1 Safe auto-execution.
     */
    protected const SAFE_PATTERNS = [
        '/^sudo\s+systemctl\s+(reload|restart|status)\s+([a-zA-Z0-9_\-\.]+)$/i',
        '/^sudo\s+service\s+([a-zA-Z0-9_\-\.]+)\s+(reload|restart|status)$/i',
        '/^sudo\s+nginx\s+-t$/i',
        '/^sudo\s+kill\s+(-15|-TERM)\s+\d+$/i',
        '/^rm\s+(-f\s+)?([a-zA-Z0-9_\-\.\/]+)\/\.maintenance$/i',
        '/^wp\s+(cache\s+flush|transient\s+delete(\s+--all)?)((\s+--path=|\s+--path=[\'"][a-zA-Z0-9_\-\.\/]+[\'"]|[a-zA-Z0-9_\-\.\/]+)?)$/i',
        '/^sudo\s+logrotate(\s+-f)?(\s+\/etc\/logrotate\.d\/[a-zA-Z0-9_\-]+)?$/i',
    ];

    /**
     * Strict end-to-end anchored patterns for Tier 2 Cautious commands (operator review required).
     */
    protected const CAUTIOUS_PATTERNS = [
        '/^sudo\s+kill\s+(-9|-KILL)\s+\d+$/i',
        '/^wp\s+plugin\s+(activate|deactivate|status)(\s+[a-zA-Z0-9_\-]+)?((\s+--path=|\s+--path=[\'"][a-zA-Z0-9_\-\.\/]+[\'"]|[a-zA-Z0-9_\-\.\/]+)?)$/i',
        '/^sudo\s+systemctl\s+(stop|start)\s+([a-zA-Z0-9_\-\.]+)$/i',
    ];

    /**
     * Evaluate a single bash command.
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

        // Check against strictly prohibited patterns
        foreach (self::DANGEROUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return [
                    'allowed' => false,
                    'tier' => self::TIER_3_PROHIBITED,
                    'reason' => 'Command contains prohibited destructive shell tokens.',
                ];
            }
        }

        // Check against Tier 1 Safe patterns (strictly anchored)
        foreach (self::SAFE_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return [
                    'allowed' => true,
                    'tier' => self::TIER_1_SAFE,
                    'reason' => 'Command matches approved safe pattern.',
                ];
            }
        }

        // Check against Tier 2 Cautious patterns (strictly anchored)
        foreach (self::CAUTIOUS_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return [
                    'allowed' => true,
                    'tier' => self::TIER_2_CAUTIOUS,
                    'reason' => 'Command matches cautious pattern requiring human confirmation.',
                ];
            }
        }

        // Default-deny: Any command not on the approved allowlist is prohibited
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
