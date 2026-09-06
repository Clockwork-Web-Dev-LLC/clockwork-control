<?php

namespace Modules\ContactForms\Commands;

use App\Models\ContactFormTest;
use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Reconcile per-site client-self-service form-test subscriptions from
 * Companion into the agency's contact_form_tests table.
 */
class SyncCompanionFormSubscriptions extends Command
{
    protected $signature = 'clockwork:sync-companion-form-subscriptions
        {--site= : Limit to a specific site ID or domain}
        {--dry-run : Report planned changes without writing}';

    protected $description = 'Pull client-chosen form-test subscriptions from each care-plan site\'s Companion and reconcile contact_form_tests rows with created_by=client.';

    public function handle(): int
    {
        $sites = $this->targetSites();

        if ($sites->isEmpty()) {
            $this->warn('No care-plan sites with Companion installed found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $totalAdded = 0;
        $totalRemoved = 0;
        $failed = 0;

        foreach ($sites as $site) {
            $this->line("→ {$site->domain}");
            try {
                $result = $this->syncSite($site, $dryRun);
                $totalAdded += $result['added'];
                $totalRemoved += $result['removed'];
                $this->info("  +{$result['added']} added, -{$result['removed']} removed");
            } catch (Throwable $e) {
                $this->error("  ✗ {$e->getMessage()}");
                $failed++;
            }
        }

        $this->line('');
        $prefix = $dryRun ? '[DRY-RUN] ' : '';
        $this->info("{$prefix}Done. added={$totalAdded}, removed={$totalRemoved}, failed={$failed}");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @return Collection<int, Site> */
    private function targetSites()
    {
        $q = Site::query()
            ->where('companion_installed', true)
            ->where('care_plan_enabled', true);

        if ($siteOpt = $this->option('site')) {
            $q->where(function ($q) use ($siteOpt) {
                $q->where('id', is_numeric($siteOpt) ? (int) $siteOpt : 0)
                    ->orWhere('domain', $siteOpt);
            });
        }

        return $q->orderBy('domain')->get();
    }

    /**
     * @return array{added: int, removed: int}
     */
    private function syncSite(Site $site, bool $dryRun): array
    {
        $payload = (new ClockworkCompanionClient($site))->formSubscriptions();
        $subs = collect($payload['subscriptions'] ?? [])
            ->filter(fn ($s) => is_array($s) && ! empty($s['form_id']))
            ->keyBy(fn ($s) => (string) $s['form_id']);

        // Existing client-provenance rows on this site, keyed by form_id.
        $existingClient = $site->contactFormTests()
            ->where('created_by', ContactFormTest::CREATED_BY_CLIENT)
            ->get()
            ->keyBy('form_id');

        // Form IDs the agency already owns — we won't double-create or remove
        // these, but a Companion subscription on the same form_id still gets
        // honored (the agency row's existence means "we're already testing
        // this form for them," so the subscription is implicitly served).
        $agencyOwnedIds = $site->contactFormTests()
            ->where('created_by', ContactFormTest::CREATED_BY_AGENCY)
            ->pluck('form_id')
            ->all();

        $added = 0;
        $removed = 0;

        // Additions: subscriptions present in Companion but no client row here yet.
        foreach ($subs as $formId => $sub) {
            if ($existingClient->has($formId)) {
                continue;
            }
            if (in_array($formId, $agencyOwnedIds, true)) {
                // Agency already covers this — silently honored.
                continue;
            }
            if ($site->contactFormTests()->count() >= ContactFormTest::MAX_PER_SITE) {
                // Site is at the cap (somehow). Companion side enforces too,
                // but races + manual DB pokes can still get us here. Skip
                // gracefully rather than blowing up.
                $this->warn("    skipped form #{$formId} — site at {$site->contactFormTests()->count()}/".ContactFormTest::MAX_PER_SITE);

                continue;
            }
            if (! $dryRun) {
                $site->contactFormTests()->create([
                    'slot' => $this->nextSlot($site),
                    'form_id' => (string) $formId,
                    'form_plugin' => (string) ($sub['plugin'] ?? $site->contact_form_plugin ?? ''),
                    'frequency' => (string) ($sub['frequency'] ?? ContactFormTest::FREQUENCY_WEEKLY),
                    'created_by' => ContactFormTest::CREATED_BY_CLIENT,
                    'enabled' => (bool) ($sub['enabled'] ?? true),
                    'state' => ContactFormTest::STATE_PENDING,
                ]);
            }
            $added++;
        }

        // Removals: client rows here but not in Companion subscriptions anymore.
        foreach ($existingClient as $formId => $row) {
            if ($subs->has($formId)) {
                continue;
            }
            if (! $dryRun) {
                $row->delete();
            }
            $removed++;
        }

        return ['added' => $added, 'removed' => $removed];
    }

    /**
     * Pick the lowest free slot 1..MAX_PER_SITE. Re-counted each iteration
     * so multiple inserts in one site don't all grab the same slot.
     */
    private function nextSlot(Site $site): int
    {
        $taken = $site->contactFormTests()->pluck('slot')->all();
        for ($i = 1; $i <= ContactFormTest::MAX_PER_SITE; $i++) {
            if (! in_array($i, $taken, true)) {
                return $i;
            }
        }

        return 1; // Shouldn't reach here — caller checks the cap.
    }
}
