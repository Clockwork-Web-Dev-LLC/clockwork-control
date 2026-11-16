<?php

namespace Modules\Seo\DataForSeo;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Says no before money is spent.
 *
 * Three limits, checked against the ledger for the current calendar month:
 *  - a cap per feature (rank tracking, site audit, ...);
 *  - a cap on everything together;
 *  - for a non-admin person asking through Claude, their own allowance.
 *
 * A scheduled job that hits its cap must fail loudly, not skip quietly:
 * a hole in rank history can never be filled afterwards.
 */
class BudgetGuard
{
    public const DEFAULT_TOTAL_CAP = 200.0;

    public const DEFAULT_PERSON_ALLOWANCE = 10.0;

    public function __construct(private readonly Settings $settings) {}

    /**
     * @return ?string null when the spend is allowed, otherwise why not, in words a person can act on
     */
    public function refusal(string $feature, float $estimateUsd, ?User $user = null): ?string
    {
        if (! Feature::exists($feature)) {
            return "Unknown spending category \"{$feature}\".";
        }

        $estimateUsd = max(0.0, $estimateUsd);

        $featureSpent = $this->spentThisMonth($feature);
        $featureCap = $this->featureCap($feature);
        if ($featureSpent + $estimateUsd > $featureCap) {
            return sprintf(
                '%s has spent $%.2f of its $%.2f cap this month, and this would add about $%.2f. Raise the cap on /seo/settings or wait until next month.',
                Feature::label($feature), $featureSpent, $featureCap, $estimateUsd,
            );
        }

        $totalSpent = $this->spentThisMonth();
        $totalCap = $this->totalCap();
        if ($totalSpent + $estimateUsd > $totalCap) {
            return sprintf(
                'DataForSEO spending is at $%.2f of the $%.2f monthly cap, and this would add about $%.2f. Raise the cap on /seo/settings or wait until next month.',
                $totalSpent, $totalCap, $estimateUsd,
            );
        }

        if ($user !== null && ! $user->isAdmin()) {
            $personSpent = $this->spentThisMonth(null, $user->id);
            $allowance = $this->personAllowance();
            if ($personSpent + $estimateUsd > $allowance) {
                return sprintf(
                    'You have used $%.2f of your $%.2f monthly allowance, and this would add about $%.2f. An admin can run it, or raise the allowance on /seo/settings.',
                    $personSpent, $allowance, $estimateUsd,
                );
            }
        }

        return null;
    }

    public function spentThisMonth(?string $feature = null, ?int $userId = null): float
    {
        return (float) DB::table('seo_api_usage')
            ->where('provider', DataForSeoClient::PROVIDER)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->when($feature !== null, fn ($query) => $query->where('feature', $feature))
            ->when($userId !== null, fn ($query) => $query->where('user_id', $userId))
            ->sum('cost_usd');
    }

    public function featureCap(string $feature): float
    {
        $caps = (array) $this->settings->get('clockwork.seo.dataforseo.caps', []);

        return max(0.0, (float) ($caps[$feature] ?? Feature::ALL[$feature]['default_cap'] ?? 0.0));
    }

    public function totalCap(): float
    {
        return max(0.0, (float) $this->settings->get('clockwork.seo.dataforseo.total_cap', self::DEFAULT_TOTAL_CAP));
    }

    public function personAllowance(): float
    {
        return max(0.0, (float) $this->settings->get('clockwork.seo.dataforseo.person_allowance', self::DEFAULT_PERSON_ALLOWANCE));
    }

    /**
     * @param  array{caps?: array<string, float|int|string>, total_cap?: float|int|string, person_allowance?: float|int|string}  $values
     */
    public function save(array $values): void
    {
        $caps = [];
        foreach (array_keys(Feature::ALL) as $feature) {
            if (isset($values['caps'][$feature]) && is_numeric($values['caps'][$feature])) {
                $caps[$feature] = max(0.0, (float) $values['caps'][$feature]);
            }
        }

        $this->settings->put('clockwork.seo.dataforseo.caps', $caps);
        if (isset($values['total_cap'])) {
            $this->settings->put('clockwork.seo.dataforseo.total_cap', max(0.0, (float) $values['total_cap']));
        }
        if (isset($values['person_allowance'])) {
            $this->settings->put('clockwork.seo.dataforseo.person_allowance', max(0.0, (float) $values['person_allowance']));
        }
    }

    /**
     * Month-to-date spend and cap for every feature, for the settings page
     * and for Claude.
     *
     * @return list<array{feature: string, label: string, spent: float, cap: float}>
     */
    public function summary(): array
    {
        $spent = DB::table('seo_api_usage')
            ->where('provider', DataForSeoClient::PROVIDER)
            ->where('created_at', '>=', Carbon::now()->startOfMonth())
            ->groupBy('feature')
            ->selectRaw('feature, SUM(cost_usd) AS spent')
            ->pluck('spent', 'feature');

        $rows = [];
        foreach (Feature::ALL as $feature => $meta) {
            $rows[] = [
                'feature' => $feature,
                'label' => $meta['label'],
                'spent' => round((float) ($spent[$feature] ?? 0), 4),
                'cap' => $this->featureCap($feature),
            ];
        }

        return $rows;
    }
}
