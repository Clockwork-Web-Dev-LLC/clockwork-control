<?php

namespace App\Console\Commands;

use App\Models\Site;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\Pressable\PressableClient;

#[Signature('clockwork:import-pressable {--dry-run : Report what would be imported without modifying the database}')]
#[Description('Import (or refresh) sites from the Pressable API. No server rows — Pressable has no server concept. Idempotent.')]
class ImportPressable extends Command
{
    public function handle(PressableClient $pressable): int
    {
        if (! $pressable->isConfigured()) {
            $this->error('CLOCKWORK_PRESSABLE_CLIENT_ID / CLOCKWORK_PRESSABLE_CLIENT_SECRET are not set in .env.');

            return self::FAILURE;
        }

        if ($pressable->isViewOnly()) {
            $this->components->info('Running in View-Only Mode: No remote modifications or installations will be performed.');
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN: Simulating import without modifying database.');
        }

        $this->info('Fetching Pressable sites…');
        $psSites = $pressable->sites();
        $this->line('  '.count($psSites).' sites');

        $stats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_no_domain' => 0, 'skipped_active_spinupwp' => 0, 'pressable_id_nulled' => 0];

        try {
            DB::transaction(function () use ($psSites, &$stats, $dryRun) {
                foreach ($psSites as $row) {
                    $this->upsertSite($row, $stats);
                }

                // Sweep: any local Pressable row whose pressable_site_id is NOT in
                // the API's response has been deleted/moved off Pressable. Null
                // its tracking id rather than silently leaving stale data — mirrors
                // clockwork:import-spinupwp's spinupwp_id-nulling sweep.
                $liveIds = collect($psSites)->pluck('id')->map(fn ($id) => (string) $id)->all();
                $stats['pressable_id_nulled'] = Site::query()
                    ->withoutGlobalScopes()
                    ->whereNotNull('pressable_site_id')
                    ->whereNotIn('pressable_site_id', $liveIds)
                    ->update(['pressable_site_id' => null, 'updated_at' => now()]);

                if ($dryRun) {
                    throw new \RuntimeException('DRY_RUN_ROLLBACK');
                }
            });
        } catch (\RuntimeException $e) {
            if ($e->getMessage() !== 'DRY_RUN_ROLLBACK') {
                throw $e;
            }
        }

        $this->newLine();
        $prefix = $dryRun ? '[DRY RUN] ' : '';
        $this->info($prefix.'Sites: '.json_encode($stats));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $stats
     */
    protected function upsertSite(array $row, array &$stats): void
    {
        $domain = $row['url'] ?? null;
        if (! $domain) {
            $stats['skipped_no_domain']++;

            return;
        }

        $isLive = ($row['state'] ?? null) === 'live';

        $attributes = [
            'pressable_site_id' => isset($row['id']) ? (string) $row['id'] : null,
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'server_id' => null,
            'is_wordpress' => true,
        ];

        // Bypass the notArchived global scope — if the operator archived a
        // domain and Pressable still reports it, update the archived row in
        // place rather than fail the unique-domain constraint by inserting
        // a duplicate. Matches clockwork:import-spinupwp's precedent.
        $site = Site::withoutGlobalScopes()->firstWhere('domain', $domain);

        // A domain can legitimately appear in both platforms' listings at
        // once (mid-migration, a staging clone, client experimentation).
        // If the existing row is an active (non-archived), currently-tracked
        // SpinupWP site, do NOT silently repurpose it as Pressable — that
        // nulls its server_id and breaks SSH-based monitoring for a site
        // that's still really hosted there. Skip and flag for manual review
        // instead. (Found the hard way: siteclient.example, still live on
        // web51, got its server_id wiped by an earlier, unguarded version
        // of this import.)
        if ($site && $site->isSpinupWp() && $site->spinupwp_id !== null && $site->archived_at === null) {
            $this->warn("  Skipping {$domain}: still an active SpinupWP site (spinupwp_id={$site->spinupwp_id}) — not overwriting.");
            $stats['skipped_active_spinupwp']++;

            return;
        }

        $site ??= new Site(['domain' => $domain]);
        $existed = $site->exists;

        // New staging/sandbox sites should never fire uptime alerts.
        // Pressable tells us this directly via `state` — no domain-pattern
        // guessing needed. Only set on creation — don't override a manual
        // re-enable on an existing site.
        if (! $existed && ! $isLive) {
            $attributes['uptime_monitoring_enabled'] = false;
        }

        // The auto_updates_paused column defaults to true at the schema
        // level (2026_05_09 opt-in flip) — every brand-new site otherwise
        // silently inherits "paused" with no reason recorded, which is how
        // 12 freshly-onboarded canary sites ended up excluded from nightly
        // auto-updates without anyone deciding that. New sites start
        // unpaused instead; only set on creation — never overrides a
        // deliberate manual pause on an existing site.
        if (! $existed) {
            $attributes['auto_updates_paused'] = false;
        }

        $site->fill($attributes);
        $site->save();

        if (! $existed) {
            $stats['created']++;
        } elseif ($site->wasChanged()) {
            $stats['updated']++;
        } else {
            $stats['unchanged']++;
        }
    }
}
