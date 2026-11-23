<?php

namespace Modules\Seo\Ai;

use App\Support\Settings;

/** How often prompts are asked, and of which AI engines. */
class AiVisibilitySettings
{
    public const FREQUENCIES = ['off' => null, 'weekly' => 7, 'daily' => 1];

    public const MAX_PROMPTS_PER_SITE = 50;

    public const KEY = 'clockwork.seo.ai_visibility';

    public function __construct(private readonly Settings $settings) {}

    public function frequency(): string
    {
        $value = (string) ($this->stored()['frequency'] ?? config('clockwork.seo.ai_visibility.frequency', 'weekly'));

        return array_key_exists($value, self::FREQUENCIES) ? $value : 'weekly';
    }

    public function intervalDays(): ?int
    {
        return self::FREQUENCIES[$this->frequency()];
    }

    /** @return list<string> */
    public function engines(): array
    {
        $stored = $this->stored()['engines'] ?? null;
        $engines = is_array($stored) ? array_values(array_intersect(array_keys(AiEngines::ALL), $stored)) : array_keys(AiEngines::ALL);

        return $engines;
    }

    /** @param  list<string>  $engines */
    public function save(string $frequency, array $engines): void
    {
        $this->settings->put(self::KEY, [
            'frequency' => array_key_exists($frequency, self::FREQUENCIES) ? $frequency : 'weekly',
            'engines' => array_values(array_intersect(array_keys(AiEngines::ALL), $engines)),
        ]);
    }

    /** What one prompt costs each time it is asked, across the chosen engines. */
    public function costPerRound(): float
    {
        return round(array_sum(array_map([AiEngines::class, 'price'], $this->engines())), 4);
    }

    /** What a month of tracking costs for this many prompts. */
    public function monthlyEstimate(int $prompts): float
    {
        $interval = $this->intervalDays();

        return $interval === null ? 0.0 : round($prompts * (30.4 / $interval) * $this->costPerRound(), 2);
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        return (array) $this->settings->get(self::KEY, []);
    }
}
