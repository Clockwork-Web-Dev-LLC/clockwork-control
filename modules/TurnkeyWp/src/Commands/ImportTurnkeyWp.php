<?php

namespace Modules\TurnkeyWp\Commands;

use App\Models\Server;
use App\Models\ServerUpdateSnapshot;
use App\Models\Site;
use App\Models\SiteIngestExclusion;
use App\Support\SiteIngestExclusionSet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\TurnkeyWp\TurnkeyWpClient;

/**
 * Pulls servers and sites from TurnkeyWP into Clockwork Control.
 *
 * Ownership rules, because the SpinupWP migration means the same server or
 * domain can be live on both hosts at once:
 *
 * - Servers are matched by turnkeywp_server_id, then by (hostname, ssh_port).
 *   A server that SpinupWP still tracks (spinupwp_id set — a box TurnkeyWP
 *   adopted) only gets turnkeywp_server_id; its SSH user, update flags and
 *   everything else stay with the existing import.
 * - Sites are matched by turnkeywp_site_id, then by domain (and its www/root
 *   variant on the same server). A domain row that another host still tracks
 *   (spinupwp_id, pressable_site_id, … set) is left alone until that host
 *   stops listing it; once SpinupWP's import nulls its spinupwp_id, the next
 *   run here adopts the row and keeps its history.
 * - When TurnkeyWP lists one domain twice (Move Site keeps the old copy until
 *   it's deleted), the row stays on the copy it already follows.
 * - Staging sites (temporary sslip.io-style domains) are skipped unless
 *   --include-staging is passed.
 * - Listings are filtered by the token's team role, so "not listed" may mean
 *   "not visible". Site ids are only cleared on servers this run listed, and
 *   never on a partial or empty listing. Server ids are never cleared, only
 *   reported.
 *
 * Fields the TurnkeyWP site payload currently fills with placeholders
 * (wp_*_update flags, the `backups` block, certificate_renews) are not
 * imported.
 */
#[Signature('clockwork:import-turnkeywp
    {--dry-run : Report what would be imported without modifying the database}
    {--include-staging : Also import TurnkeyWP staging sites (temporary domains)}
    {--adopt=* : Take over these domains now even though SpinupWP still lists them (e.g. right after DNS cutover)}')]
#[Description('Import (or refresh) servers and sites from the TurnkeyWP API. Never takes over a site another host still tracks. Idempotent.')]
class ImportTurnkeyWp extends Command
{
    /** Provider tracking columns that mean "another host owns this site". */
    private const OTHER_HOST_ID_COLUMNS = [
        'spinupwp_id',
        'pressable_site_id',
        'wpengine_install_name',
        'kinsta_environment_id',
        'cloudways_app_id',
        'gridpane_site_id',
    ];

    public function handle(TurnkeyWpClient $turnkey): int
    {
        if (! $turnkey->isConfigured()) {
            $this->error('TURNKEYWP_TOKEN is not configured in .env or Settings.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN: Database changes will be rolled back.');
        }

        try {
            $this->info('Fetching TurnkeyWP servers…');
            $tkServers = $turnkey->servers();
            $serversPartial = $turnkey->wasPartial();
            $this->line('  '.count($tkServers).' servers');

            $this->info('Fetching TurnkeyWP sites…');
            $tkSites = $turnkey->sites();
            $sitesPartial = $turnkey->wasPartial();
            $this->line('  '.count($tkSites).' sites');
        } catch (\Throwable $e) {
            $this->error('Failed fetching from TurnkeyWP: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($serversPartial || $sitesPartial) {
            $this->warn('  A listing stopped partway through pagination; records missing from it will not be swept this run.');
        }

        $serverStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'linked_existing' => 0, 'skipped_no_ip' => 0, 'not_listed' => 0];
        $siteStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'adopted' => 0, 'skipped_other_host' => 0, 'skipped_duplicate_domain' => 0, 'skipped_staging' => 0, 'skipped_not_deployed' => 0, 'skipped_no_server' => 0, 'skipped_excluded' => 0, 'turnkeywp_id_nulled' => 0];

        try {
            DB::transaction(function () use ($tkServers, $tkSites, $serversPartial, $sitesPartial, &$serverStats, &$siteStats, $dryRun) {
                $exclusions = SiteIngestExclusion::compile();

                foreach ($tkServers as $row) {
                    $this->upsertServer($row, $serverStats);
                }

                $serverIdByTurnkeyId = Server::whereNotNull('turnkeywp_server_id')->pluck('id', 'turnkeywp_server_id');

                $listedSites = [];
                foreach ($tkSites as $row) {
                    if (isset($row['id'])) {
                        $listedSites[(string) $row['id']] = $row;
                    }
                }

                foreach ($tkSites as $row) {
                    $this->upsertSite($row, $serverIdByTurnkeyId->all(), $listedSites, $siteStats, $exclusions);
                }

                $liveServerIds = collect($tkServers)->pluck('id')->map(fn ($id) => (string) $id)->all();

                // TurnkeyWP filters listings by the token's team role and the
                // API doesn't say which role that is, so "not listed" can mean
                // "not visible" rather than "deleted". Sites are only swept on
                // servers this run did list (visibility is per server). Servers
                // are never swept automatically, only reported.
                // An empty site listing is treated as a broken response, not a
                // fleet with no sites.
                if (! $sitesPartial && ! $serversPartial && $liveServerIds !== [] && $listedSites !== []) {
                    $visibleServerIds = Server::whereIn('turnkeywp_server_id', $liveServerIds)->pluck('id')->all();
                    $siteStats['turnkeywp_id_nulled'] = Site::query()
                        ->withoutGlobalScopes()
                        ->whereNotNull('turnkeywp_site_id')
                        ->whereIn('server_id', $visibleServerIds)
                        ->whereNotIn('turnkeywp_site_id', array_map('strval', array_keys($listedSites)))
                        ->update(['turnkeywp_site_id' => null, 'updated_at' => now()]);
                }

                if (! $serversPartial && $liveServerIds !== []) {
                    $unlisted = Server::query()
                        ->whereNotNull('turnkeywp_server_id')
                        ->whereNotIn('turnkeywp_server_id', $liveServerIds)
                        ->pluck('name')
                        ->all();
                    $serverStats['not_listed'] = count($unlisted);
                    if ($unlisted !== []) {
                        $this->warn('  Not listed by TurnkeyWP (deleted there, or not visible to this token): '.implode(', ', $unlisted));
                    }
                }

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
        $this->info($prefix.'Servers: '.json_encode($serverStats));
        $this->info($prefix.'Sites:   '.json_encode($siteStats));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $stats
     */
    protected function upsertServer(array $row, array &$stats): void
    {
        $turnkeyId = (string) ($row['id'] ?? '');
        if ($turnkeyId === '') {
            return;
        }

        // A server still being created has no IP yet; pick it up next run.
        $ip = $row['ip_address'] ?? null;
        if (! is_string($ip) || $ip === '') {
            $stats['skipped_no_ip']++;

            return;
        }

        $sshPort = (int) ($row['ssh_port'] ?? 22);

        $server = Server::where('turnkeywp_server_id', $turnkeyId)->first()
            ?? Server::where('hostname', $ip)->where('ssh_port', $sshPort)->first();

        // Adopted from SpinupWP and still tracked there: link only.
        if ($server && $server->spinupwp_id !== null) {
            if ((string) $server->turnkeywp_server_id !== $turnkeyId) {
                $server->turnkeywp_server_id = $turnkeyId;
                $server->save();
                $stats['linked_existing']++;
            } else {
                $stats['unchanged']++;
            }

            return;
        }

        $name = (string) ($row['name'] ?? "turnkeywp-{$turnkeyId}");

        $attributes = [
            'turnkeywp_server_id' => $turnkeyId,
            'name' => $name,
            'hostname' => $ip,
            'ssh_port' => $sshPort,
            'ubuntu_version' => $row['ubuntu_version'] ?? null,
        ];

        // provider is the cloud provider (digitalocean, hetzner, custom), used
        // for metrics; clockwork:reconcile-provider fills provider_id by IP.
        // Only set it on create so a manual correction sticks.
        if (! $server) {
            $attributes['provider'] = strtolower((string) ($row['provider_name'] ?? Server::PROVIDER_DIGITALOCEAN));
        }

        // Same staleness guards as the SpinupWP import: our own nightly apt
        // poll and a just-done reboot beat the host's mirrored flags.
        $snapshot = $server?->updateSnapshot;
        $recentPollIsFresh = $snapshot !== null
            && $snapshot->poll_status === ServerUpdateSnapshot::STATUS_OK
            && $snapshot->polled_at !== null
            && $snapshot->polled_at->gt(now()->subHours(24));
        if (! $recentPollIsFresh) {
            $attributes['upgrade_required'] = (bool) ($row['upgrade_required'] ?? false);
        }

        $recentlyRebooted = $server !== null
            && $server->scheduled_reboot_at !== null
            && $server->scheduled_reboot_at->isPast()
            && $server->scheduled_reboot_at->gt(now()->subHours(24));
        if (! $recentlyRebooted) {
            $attributes['reboot_required'] = (bool) ($row['reboot_required'] ?? false);
        }

        if (! $server) {
            $server = new Server($attributes);
            // TurnkeyWP disables root login after provisioning; its sudo
            // user is `turnkey`. Only set on create so an operator's
            // change (e.g. to a dedicated deploy user) sticks.
            $server->ssh_user = (string) ($row['ssh_user'] ?? 'turnkey') ?: 'turnkey';
            $server->status = Server::STATUS_UNKNOWN;
            $server->save();
            $stats['created']++;

            return;
        }

        $server->fill($attributes);
        if ($server->isDirty()) {
            $server->save();
            $stats['updated']++;
        } else {
            $stats['unchanged']++;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $serverIdByTurnkeyId
     * @param  array<string, array<string, mixed>>  $listedSites  every site in this run's listing, by TurnkeyWP id
     * @param  array<string, int>  $stats
     */
    protected function upsertSite(array $row, array $serverIdByTurnkeyId, array $listedSites, array &$stats, SiteIngestExclusionSet $exclusions): void
    {
        $domain = strtolower(trim((string) ($row['domain'] ?? '')));
        $turnkeyId = isset($row['id']) ? (string) $row['id'] : '';
        if ($domain === '' || $turnkeyId === '') {
            return;
        }

        if (($row['is_staging'] ?? false) === true && ! $this->option('include-staging')) {
            $stats['skipped_staging']++;

            return;
        }

        $site = Site::withoutGlobalScopes()->where('turnkeywp_site_id', $turnkeyId)->first();

        // A site still installing (or failed) isn't worth monitoring yet.
        // Rows we already track keep updating whatever their state.
        if (! $site && ($row['status'] ?? null) !== 'deployed') {
            $stats['skipped_not_deployed']++;

            return;
        }

        $serverId = $serverIdByTurnkeyId[(string) ($row['server_id'] ?? '')] ?? null;
        if (! $serverId) {
            $stats['skipped_no_server']++;
            $this->warn("  Skipping {$domain}: its TurnkeyWP server isn't imported (no IP yet, or not visible to this token).");

            return;
        }

        if ($exclusions->blocks(Site::HOSTING_PROVIDER_TURNKEYWP, $turnkeyId, $domain)) {
            $stats['skipped_excluded']++;

            return;
        }

        $adopting = false;
        if (! $site) {
            $site = $this->findByDomain($domain, (int) $serverId);

            if ($site) {
                // Move Site leaves the old copy listed under another id with
                // the same domain until it's deleted. Keep the row on the copy
                // it already follows, so it doesn't swap servers every hour.
                $currentId = $site->turnkeywp_site_id !== null ? (string) $site->turnkeywp_site_id : null;
                if ($currentId !== null && $currentId !== $turnkeyId && isset($listedSites[$currentId])
                    && (($listedSites[$currentId]['status'] ?? null) === 'deployed' || ($row['status'] ?? null) !== 'deployed')) {
                    $this->line("  Skipping {$domain} (TurnkeyWP site {$turnkeyId}): the same domain is also TurnkeyWP site {$currentId}, which this row already follows.");
                    $stats['skipped_duplicate_domain']++;

                    return;
                }

                $owner = $this->otherHostOwner($site);
                if ($owner !== null && $this->forceAdopt($site, $domain)) {
                    $owner = null;
                }
                if ($owner !== null) {
                    $this->line("  Skipping {$domain}: still tracked by {$owner}. It moves to TurnkeyWP once removed there (or pass --adopt={$domain}).");
                    $stats['skipped_other_host']++;

                    return;
                }

                if ($site->consolidated_into_site_id !== null || $site->archived_at !== null) {
                    $this->warn("  {$domain}: adopting a row that is archived or consolidated into another site; it stays hidden until you restore it.");
                }

                $adopting = true;
            }
        }

        // A primary-domain change in TurnkeyWP renames the row, unless another
        // row already holds that domain (sites.domain is unique).
        if ($site !== null && $site->domain !== $domain
            && Site::withoutGlobalScopes()->where('domain', $domain)->where('id', '!=', $site->id)->exists()) {
            $this->warn("  {$site->domain}: TurnkeyWP now calls it {$domain}, but another row already has that domain. Left as is.");
            $domain = (string) $site->domain;
        }

        $existed = $site !== null;
        $site ??= new Site(['domain' => $domain]);
        $fromOtherHost = $existed && $site->hosting_provider !== Site::HOSTING_PROVIDER_TURNKEYWP;

        $isWordpress = (bool) ($row['is_wordpress'] ?? false);

        $attributes = [
            'domain' => $domain,
            'hosting_provider' => Site::HOSTING_PROVIDER_TURNKEYWP,
            'turnkeywp_site_id' => $turnkeyId,
            'server_id' => $serverId,
            'is_wordpress' => $isWordpress,
        ];

        // Set the path when we take a row on; after that an operator's
        // correction (e.g. WordPress in a subdirectory) sticks.
        if (! $existed || $adopting || empty($site->wp_path)) {
            $attributes['wp_path'] = "/sites/{$domain}/files";
        }

        // The previous host's update flags and renewal date no longer apply,
        // and TurnkeyWP doesn't report real ones yet.
        if ($fromOtherHost) {
            $attributes['wp_core_update'] = null;
            $attributes['wp_theme_updates'] = null;
            $attributes['wp_plugin_updates'] = null;
            $attributes['wp_updates_checked_at'] = null;
            $attributes['cert_renews_at'] = null;
        }

        if (! empty($row['site_user'])) {
            $attributes['site_user'] = (string) $row['site_user'];
        }
        if (! empty($row['database']['table_prefix'])) {
            $attributes['table_prefix'] = (string) $row['database']['table_prefix'];
        }

        // Certificates are checked by the live TLS probe. Keep a manual
        // external/redirect-only override.
        if (! in_array($site->cert_source, [Site::CERT_SOURCE_EXTERNAL, Site::CERT_SOURCE_REDIRECT_ONLY], true)) {
            $attributes['cert_source'] = Site::CERT_SOURCE_LIVE_PROBE;
        }

        if (! $existed) {
            // Same as the SpinupWP import: new sites start unpaused, and a
            // staging import never fires uptime alerts.
            $attributes['auto_updates_paused'] = false;
            if (($row['is_staging'] ?? false) === true) {
                $attributes['uptime_monitoring_enabled'] = false;
            }
        }

        $site->fill($attributes);
        $site->save();

        if (! $existed) {
            $stats['created']++;
        } elseif ($adopting) {
            $stats['adopted']++;
        } elseif ($site->wasChanged()) {
            $stats['updated']++;
        } else {
            $stats['unchanged']++;
        }
    }

    /**
     * Same order as ImportSpinupWp::resolveSite(): the exact domain, then the
     * www/root variant on the same server, then the exact domain even if that
     * row is consolidated or archived (so the unique domain index never trips).
     */
    private function findByDomain(string $domain, int $serverId): ?Site
    {
        $site = Site::withoutGlobalScopes()
            ->where('domain', $domain)
            ->whereNull('consolidated_into_site_id')
            ->first();
        if ($site) {
            return $site;
        }

        $altDomain = str_starts_with($domain, 'www.') ? substr($domain, 4) : 'www.'.$domain;
        $site = Site::withoutGlobalScopes()
            ->where('domain', $altDomain)
            ->where('server_id', $serverId)
            ->whereNull('consolidated_into_site_id')
            ->first();
        if ($site) {
            return $site;
        }

        return Site::withoutGlobalScopes()->where('domain', $domain)->first();
    }

    /**
     * --adopt: release a site SpinupWP still tracks so it can move now. Its
     * spinupwp_id is cleared; the SpinupWP import then skips the row because
     * it belongs to TurnkeyWP. Other hosts are never force-adopted.
     */
    private function forceAdopt(Site $site, string $domain): bool
    {
        $requested = array_map(fn ($d) => strtolower(trim((string) $d)), (array) $this->option('adopt'));
        if (! in_array($domain, $requested, true)) {
            return false;
        }

        foreach (self::OTHER_HOST_ID_COLUMNS as $column) {
            if ($column !== 'spinupwp_id' && ! empty($site->{$column})) {
                $this->warn("  --adopt {$domain}: tracked by {$column}, not SpinupWP; not taking it over.");

                return false;
            }
        }

        $site->spinupwp_id = null;

        return true;
    }

    /**
     * Name of another host that still tracks this site, or null when the
     * row is free to adopt.
     */
    private function otherHostOwner(Site $site): ?string
    {
        foreach (self::OTHER_HOST_ID_COLUMNS as $column) {
            if (! empty($site->{$column})) {
                return $site->hostLabel()." ({$column} {$site->{$column}})";
            }
        }

        return null;
    }
}
