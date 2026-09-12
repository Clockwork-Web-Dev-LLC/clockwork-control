<?php

namespace Modules\GridPane\Commands;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Modules\GridPane\GridPaneClient;

#[Signature('clockwork:import-gridpane {--dry-run : Simulate the import without writing any changes to the database}')]
#[Description('Import (or refresh) servers and sites from the GridPane API. Cross-references servers by IP or GridPane server ID. Idempotent.')]
class ImportGridPane extends Command
{
    public function handle(GridPaneClient $gridpane): int
    {
        if (! $gridpane->isConfigured()) {
            $this->error('GRIDPANE_API_KEY is not configured in .env or Settings.');

            return self::FAILURE;
        }

        if ($gridpane->isViewOnly()) {
            $this->comment('Running in View-Only Mode: Remote mutations, WP-CLI executions, and Companion deployments are strictly disabled.');
        }

        $dryRun = (bool) $this->option('dry-run');
        if ($dryRun) {
            $this->warn('DRY RUN: Database changes will be rolled back.');
        }

        $this->info('Fetching GridPane servers…');
        try {
            $gpServers = $gridpane->servers();
        } catch (\Throwable $e) {
            $this->error('Failed fetching servers from GridPane: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->line('  '.count($gpServers).' servers found');
        if ($gridpane->wasPartial()) {
            $this->warn('  Server list may be incomplete: GridPane stopped responding partway through pagination. Re-run this command shortly to pick up the rest.');
        }

        $this->info('Fetching GridPane sites…');
        try {
            $gpSites = $gridpane->sites();
        } catch (\Throwable $e) {
            $this->error('Failed fetching sites from GridPane: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->line('  '.count($gpSites).' sites found');
        if ($gridpane->wasPartial()) {
            $this->warn('  Site list may be incomplete: GridPane stopped responding partway through pagination. Re-run this command shortly to pick up the rest.');
        }

        // Site rows never carry a system_user/user string directly — only
        // system_user_id, a foreign key into /system-user. Fetch the fleet's
        // system users once up front and resolve site_user by id below,
        // rather than per-site API calls.
        $this->info('Fetching GridPane system users…');
        try {
            $gpSystemUsers = $gridpane->systemUsers();
        } catch (\Throwable $e) {
            $this->error('Failed fetching system users from GridPane: '.$e->getMessage());

            return self::FAILURE;
        }
        $this->line('  '.count($gpSystemUsers).' system users found');
        if ($gridpane->wasPartial()) {
            $this->warn('  System user list may be incomplete: GridPane stopped responding partway through pagination. Re-run this command shortly to pick up the rest.');
        }
        $systemUsernameById = [];
        foreach ($gpSystemUsers as $u) {
            if (isset($u['id'], $u['username']) && is_string($u['username']) && $u['username'] !== '') {
                $systemUsernameById[(string) $u['id']] = $u['username'];
            }
        }

        $serverStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0];
        $siteStats = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped_no_domain' => 0];

        try {
            DB::transaction(function () use ($gpServers, $gpSites, $systemUsernameById, &$serverStats, &$siteStats, $dryRun) {
                $serverIdByGpId = [];

                foreach ($gpServers as $row) {
                    $server = $this->upsertServer($row, $serverStats);
                    if ($server && isset($row['id'])) {
                        $serverIdByGpId[(string) $row['id']] = $server->id;
                    }
                }

                foreach ($gpSites as $row) {
                    $this->upsertSite($row, $serverIdByGpId, $systemUsernameById, $siteStats);
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
    protected function upsertServer(array $row, array &$stats): ?Server
    {
        $gpId = isset($row['id']) ? (string) $row['id'] : null;
        $ip = $row['ip'] ?? $row['server_ip'] ?? null;
        $name = $row['label'] ?? $row['name'] ?? ($gpId ? "gridpane-server-{$gpId}" : null);

        if (! $ip && ! $gpId) {
            return null;
        }

        /** @var ?Server $server */
        $server = null;
        if ($gpId) {
            $server = Server::where('provider', Server::PROVIDER_GRIDPANE)
                ->where('provider_id', $gpId)
                ->first();
        }

        if (! $server && $ip) {
            $server = Server::where('hostname', $ip)->first();
        }

        $attributes = [
            'name' => $name ?? ($ip ?? 'GridPane Server'),
            'hostname' => $ip ?? ($server->hostname ?? '127.0.0.1'),
            'ssh_port' => 22,
            'ssh_user' => 'root',
            'provider' => $server->provider ?? Server::PROVIDER_GRIDPANE,
            'provider_id' => $gpId ?? $server?->provider_id,
            'status' => $server->status ?? Server::STATUS_GREEN,
        ];

        if (! $server) {
            $server = Server::create($attributes);
            $stats['created']++;

            return $server;
        }

        $server->fill($attributes);
        if ($server->isDirty()) {
            $server->save();
            $stats['updated']++;
        } else {
            $stats['unchanged']++;
        }

        return $server;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, int>  $serverIdByGpId
     * @param  array<string, string>  $systemUsernameById
     * @param  array<string, int>  $stats
     */
    protected function upsertSite(array $row, array $serverIdByGpId, array $systemUsernameById, array &$stats): ?Site
    {
        $domain = $row['url'] ?? $row['domain'] ?? $row['primary_domain'] ?? null;
        if (! $domain) {
            $stats['skipped_no_domain']++;

            return null;
        }

        // Clean domain format (e.g. remove https:// or trailing slashes)
        $domain = preg_replace('#^https?://#', '', rtrim(strtolower((string) $domain), '/'));

        $gpSiteId = isset($row['id']) ? (string) $row['id'] : null;
        $gpServerId = isset($row['server_id']) ? (string) $row['server_id'] : null;
        $serverId = $gpServerId ? ($serverIdByGpId[$gpServerId] ?? null) : null;

        /** @var ?Site $site */
        $site = null;
        if ($gpSiteId) {
            $site = Site::withoutGlobalScopes()->where('gridpane_site_id', $gpSiteId)->first();
        }

        if (! $site) {
            $site = Site::withoutGlobalScopes()->where('domain', $domain)->first();
        }

        // GridPane's site payloads never carry a literal system_user/user
        // string (kept here only in case a future API version adds one) —
        // in practice this always resolves via system_user_id against the
        // system-users map built in handle(). A site whose id can't be
        // resolved (e.g. the owning user was deleted from GridPane) falls
        // back to the 'gridpane' placeholder only when creating a brand-new
        // site record; an existing site keeps whatever site_user it already
        // has rather than being clobbered back to the placeholder on every
        // re-import.
        $gpSystemUserId = isset($row['system_user_id']) ? (string) $row['system_user_id'] : null;
        $systemUser = $row['system_user'] ?? $row['user'] ?? ($gpSystemUserId !== null ? ($systemUsernameById[$gpSystemUserId] ?? null) : null);
        if ($systemUser === null && ! $site) {
            $systemUser = 'gridpane';
        }

        $attributes = [
            'domain' => $domain,
            'hosting_provider' => Site::HOSTING_PROVIDER_GRIDPANE,
            'gridpane_site_id' => $gpSiteId,
            'wp_path' => "/var/www/{$domain}/htdocs",
            'is_wordpress' => true,
            'cert_source' => Site::CERT_SOURCE_EXTERNAL,
        ];

        if ($serverId) {
            $attributes['server_id'] = $serverId;
        }

        if ($systemUser !== null) {
            $attributes['site_user'] = $systemUser;
        }

        if (! $site) {
            $site = Site::create($attributes);
            $stats['created']++;

            return $site;
        }

        $site->fill($attributes);
        if ($site->isDirty()) {
            $site->save();
            $stats['updated']++;
        } else {
            $stats['unchanged']++;
        }

        return $site;
    }
}
