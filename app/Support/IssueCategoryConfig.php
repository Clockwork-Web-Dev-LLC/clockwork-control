<?php

namespace App\Support;

class IssueCategoryConfig
{
    public const LEVEL_EMERGENCY = 'emergency';

    public const LEVEL_PRESSING = 'pressing';

    public const LEVEL_NOT_PRESSING = 'not_pressing';

    public const LEVEL_OFF = 'off';

    public const SETTING_KEY = 'issues.category_levels';

    public function __construct(
        protected Settings $settings
    ) {}

    /**
     * Default priority levels for all fleet issue categories.
     *
     * @return array<string, string>
     */
    public function defaults(): array
    {
        return [
            // Critical emergencies (default: emergency)
            'down_sites' => self::LEVEL_EMERGENCY,
            'scheduler_stale' => self::LEVEL_EMERGENCY,
            'malware' => self::LEVEL_EMERGENCY,
            'companion_malware' => self::LEVEL_EMERGENCY,
            'tampering' => self::LEVEL_EMERGENCY,
            'health' => self::LEVEL_EMERGENCY,
            'forms_failing' => self::LEVEL_EMERGENCY,
            'stuck_maintenance' => self::LEVEL_EMERGENCY,
            'ssl' => self::LEVEL_EMERGENCY,

            // Infrastructure & urgent issues (default: pressing)
            'hot' => self::LEVEL_PRESSING,
            'domain-expiration' => self::LEVEL_PRESSING,
            'seo-indexability' => self::LEVEL_PRESSING,
            'reboot' => self::LEVEL_PRESSING,
            'no_ssh' => self::LEVEL_PRESSING,
            'no_db' => self::LEVEL_PRESSING,
            'no_companion' => self::LEVEL_PRESSING,
            'plugins_closed' => self::LEVEL_PRESSING,

            // Routine upkeep & audits (default: not_pressing)
            'patches' => self::LEVEL_NOT_PRESSING,
            'cf' => self::LEVEL_NOT_PRESSING,
            'no_jail' => self::LEVEL_NOT_PRESSING,
            'orphans' => self::LEVEL_NOT_PRESSING,
            'plugins_outdated' => self::LEVEL_NOT_PRESSING,
            'wp_admins' => self::LEVEL_NOT_PRESSING,
        ];
    }

    /**
     * Returns the effective priority levels for all categories (defaults merged with saved settings).
     *
     * @return array<string, string>
     */
    public function levels(): array
    {
        $saved = $this->settings->get(self::SETTING_KEY, []);
        if (! is_array($saved)) {
            $saved = [];
        }

        $defaults = $this->defaults();
        $validLevels = [self::LEVEL_EMERGENCY, self::LEVEL_PRESSING, self::LEVEL_NOT_PRESSING, self::LEVEL_OFF];

        $effective = [];
        foreach ($defaults as $category => $defaultLevel) {
            $userLevel = $saved[$category] ?? null;
            $effective[$category] = in_array($userLevel, $validLevels, true) ? $userLevel : $defaultLevel;
        }

        return $effective;
    }

    /**
     * Normalize category keys between dash and underscore conventions.
     */
    public function normalizeKey(string $category): string
    {
        return match ($category) {
            'domain_expiration' => 'domain-expiration',
            'seo_indexability' => 'seo-indexability',
            'plugins-closed' => 'plugins_closed',
            default => $category,
        };
    }

    /**
     * Get the priority level for a specific category.
     */
    public function getLevel(string $category): string
    {
        $key = $this->normalizeKey($category);
        $levels = $this->levels();

        return $levels[$key] ?? ($this->defaults()[$key] ?? self::LEVEL_NOT_PRESSING);
    }

    public function isOff(string $category): bool
    {
        return $this->getLevel($category) === self::LEVEL_OFF;
    }

    public function isEmergency(string $category): bool
    {
        return $this->getLevel($category) === self::LEVEL_EMERGENCY;
    }

    public function isPressing(string $category): bool
    {
        return $this->getLevel($category) === self::LEVEL_PRESSING;
    }

    public function isNotPressing(string $category): bool
    {
        return $this->getLevel($category) === self::LEVEL_NOT_PRESSING;
    }

    public function isUrgent(string $category): bool
    {
        $level = $this->getLevel($category);

        return $level === self::LEVEL_EMERGENCY || $level === self::LEVEL_PRESSING;
    }

    /**
     * Set a single category's level.
     */
    public function setLevel(string $category, string $level): void
    {
        $key = $this->normalizeKey($category);
        $validLevels = [self::LEVEL_EMERGENCY, self::LEVEL_PRESSING, self::LEVEL_NOT_PRESSING, self::LEVEL_OFF];
        if (! in_array($level, $validLevels, true)) {
            throw new \InvalidArgumentException("Invalid issue category level: {$level}");
        }

        $saved = $this->settings->get(self::SETTING_KEY, []);
        if (! is_array($saved)) {
            $saved = [];
        }

        $saved[$key] = $level;
        $this->settings->put(self::SETTING_KEY, $saved);
    }

    /**
     * Save multiple category levels at once.
     *
     * @param  array<string, string>  $newLevels
     */
    public function saveLevels(array $newLevels): void
    {
        $validLevels = [self::LEVEL_EMERGENCY, self::LEVEL_PRESSING, self::LEVEL_NOT_PRESSING, self::LEVEL_OFF];
        $defaults = $this->defaults();

        $saved = $this->settings->get(self::SETTING_KEY, []);
        if (! is_array($saved)) {
            $saved = [];
        }

        foreach ($newLevels as $cat => $lvl) {
            $key = $this->normalizeKey($cat);
            if (array_key_exists($key, $defaults) && in_array($lvl, $validLevels, true)) {
                $saved[$key] = $lvl;
            }
        }

        $this->settings->put(self::SETTING_KEY, $saved);
    }

    /**
     * Reset category levels to defaults.
     */
    public function resetToDefaults(): void
    {
        $this->settings->put(self::SETTING_KEY, $this->defaults());
    }
}
