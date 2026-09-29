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
     * Safe patterns eligible for Tier 1 (Service reloads, cache clears, status checks).
     */
    protected const SAFE_PATTERNS = [
        '/^sudo\s+systemctl\s+(reload|restart|status)\s+([a-zA-Z0-9_\-\.]+)/i',
        '/^sudo\s+service\s+([a-zA-Z0-9_\-\.]+)\s+(reload|restart|status)/i',
        '/^sudo\s+nginx\s+-t/i',
        '/^sudo\s+kill\s+(-15|-TERM)\s+\d+$/i',
        '/^rm\s+(-f)?\s+([a-zA-Z0-9_\-\.\/]+)\/\.maintenance$/i',
        '/^wp\s+(cache\s+flush|transient\s+delete)/i',
        '/^sudo\s+logrotate/i',
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

        // Check for safe patterns
        foreach (self::SAFE_PATTERNS as $pattern) {
            if (preg_match($pattern, $trimmed)) {
                return [
                    'allowed' => true,
                    'tier' => self::TIER_1_SAFE,
                    'reason' => 'Command matches approved safe pattern.',
                ];
            }
        }

        // Cautious actions (e.g. kill -9, custom app commands)
        if (preg_match('/^sudo\s+kill\s+(-9|-KILL)\s+\d+$/i', $trimmed)) {
            return [
                'allowed' => true,
                'tier' => self::TIER_2_CAUTIOUS,
                'reason' => 'Force-killing process with SIGKILL requires caution.',
            ];
        }

        // Default to Tier 2 (Allowed with caution/human confirmation)
        return [
            'allowed' => true,
            'tier' => self::TIER_2_CAUTIOUS,
            'reason' => 'Standard non-destructive command.',
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
