<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Ssh\SshClient;
use Illuminate\Console\Command;

/**
 * Drops MySQL tables matching an orphan prefix on a site.
 *
 * Built for a specific cleanup: 4 sites had `delete_this_*`, `deleteme_*`,
 * etc. tables left over from past prefix-rotation experiments. The live
 * WP install was on `wp_*`, but Clockwork's Site::table_prefix had drifted
 * to the orphan name, so the Companion installer wrote secrets into the
 * dead tables and the live plugin couldn't find them (the diagnosis is in
 * DiagnoseCompanionInstall).
 *
 * Safety rails:
 *   - Default is dry-run. --force is required to actually issue DROP.
 *   - Refuses to drop the prefix currently in use by wp-config.php (i.e.
 *     the live install). You'd have to manually edit wp-config + drop in
 *     two steps to wipe a live install — this command won't do that.
 *   - Only matches tables whose names start with EXACTLY '{prefix}'. Will
 *     not touch sibling prefixes that share a leading substring (e.g.
 *     prefix 'wp_' won't match 'wpx_' tables — MySQL LIKE handles this).
 */
class DropOrphanPrefixTables extends Command
{
    protected $signature = 'clockwork:drop-orphan-prefix-tables
        {site : Site ID or domain}
        {prefix : The orphan prefix to drop, e.g. delete_this_}
        {--force : Actually issue the DROP statements (default is dry-run)}';

    protected $description = 'Drop MySQL tables matching an orphan prefix on a site (refuses to touch the live prefix).';

    public function handle(SshClient $ssh): int
    {
        $arg = (string) $this->argument('site');
        $orphanPrefix = (string) $this->argument('prefix');
        $force = (bool) $this->option('force');

        if (! preg_match('/^[a-z0-9_]+$/i', $orphanPrefix)) {
            $this->error("Refusing: prefix '{$orphanPrefix}' contains characters outside [a-zA-Z0-9_]. Aborting.");

            return self::FAILURE;
        }

        $site = Site::query()
            ->with('server')
            ->where(function ($q) use ($arg) {
                $q->where('id', is_numeric($arg) ? (int) $arg : 0)
                    ->orWhere('domain', $arg);
            })
            ->first();

        if (! $site) {
            $this->error("No site matched id-or-domain '{$arg}'.");

            return self::FAILURE;
        }

        if (! $site->server || ! $site->site_user || ! $site->server->ssh_password) {
            $this->error('Missing prerequisites (server / site_user / ssh_password).');

            return self::FAILURE;
        }

        $wpPath = $site->wp_path ?: '/sites/'.$site->domain.'/files';

        // Safety check: read the actual live prefix from wp-config and refuse
        // to drop tables that belong to it.
        $configRead = $this->runAsSiteUser($ssh, $site, sprintf(
            'grep -E \'\\$table_prefix\\s*=\' %s/wp-config.php 2>/dev/null | head -1',
            escapeshellarg($wpPath),
        ));
        $livePrefix = '';
        if (preg_match("/\\\$table_prefix\\s*=\\s*['\"]([^'\"]+)['\"]/", $configRead['output'], $m)) {
            $livePrefix = $m[1];
        }

        if ($livePrefix === '') {
            $this->error('Could not read $table_prefix from wp-config.php. Aborting (cannot verify safety).');

            return self::FAILURE;
        }

        if ($livePrefix === $orphanPrefix) {
            $this->error("Refusing to drop: '{$orphanPrefix}' IS the live prefix in wp-config.php. That would wipe the running site.");

            return self::FAILURE;
        }

        $this->line("Site:        {$site->domain}");
        $this->line("Live prefix: {$livePrefix}");
        $this->line("Drop prefix: {$orphanPrefix}");
        $this->line('');

        $listSql = sprintf("SHOW TABLES LIKE '%s%%'", $orphanPrefix);
        $list = $this->runAsSiteUser($ssh, $site, sprintf(
            '/usr/local/bin/wp --path=%s db query %s --skip-column-names 2>/dev/null',
            escapeshellarg($wpPath),
            escapeshellarg($listSql),
        ));

        if ($list['exit'] !== 0) {
            $this->error("Could not list tables (wp db query exit {$list['exit']}).");

            return self::FAILURE;
        }

        $tables = array_values(array_filter(array_map('trim', explode("\n", $list['output']))));

        if ($tables === []) {
            $this->line("<info>No tables match '{$orphanPrefix}%'. Nothing to drop.</info>");

            return self::SUCCESS;
        }

        $this->line('Tables matched ('.count($tables).'):');
        foreach ($tables as $t) {
            $this->line("  - {$t}");
        }
        $this->line('');

        if (! $force) {
            $this->line('<comment>Dry-run. Re-run with --force to actually drop.</comment>');

            return self::SUCCESS;
        }

        $dropList = implode(', ', array_map(fn ($t) => '`'.$t.'`', $tables));
        $dropSql = "DROP TABLE IF EXISTS {$dropList}";

        $this->line('<comment>Issuing DROP…</comment>');
        $drop = $this->runAsSiteUser($ssh, $site, sprintf(
            '/usr/local/bin/wp --path=%s db query %s 2>/dev/null',
            escapeshellarg($wpPath),
            escapeshellarg($dropSql),
        ));

        if ($drop['exit'] !== 0) {
            $this->error("DROP failed (exit {$drop['exit']}): ".$drop['output']);

            return self::FAILURE;
        }

        $this->line('<info>Dropped '.count($tables)." table(s) starting with '{$orphanPrefix}' on {$site->domain}.</info>");

        return self::SUCCESS;
    }

    /**
     * @return array{output: string, exit: int}
     */
    private function runAsSiteUser(SshClient $ssh, Site $site, string $script): array
    {
        $sentinel = '__CLOCKWORK_DROP_EXIT__';
        $inner = sprintf(
            'echo "$CW_SUDO_PW" | sudo -S -u %s bash -c %s 2>&1; echo "%s:$?"',
            escapeshellarg((string) $site->site_user),
            escapeshellarg($script),
            $sentinel,
        );
        $cmd = sprintf(
            'CW_SUDO_PW=%s bash -c %s',
            escapeshellarg((string) $site->server->ssh_password),
            escapeshellarg($inner),
        );

        $raw = $ssh->exec($site->server, $cmd);

        $exit = -1;
        if (preg_match('/'.$sentinel.':(\d+)/', $raw, $m)) {
            $exit = (int) $m[1];
            $raw = (string) preg_replace('/\s*'.$sentinel.':\d+\s*$/', '', $raw);
        }

        $raw = (string) preg_replace('/\[sudo\] password for [^:]*:\s*/', '', $raw);

        return ['output' => trim($raw), 'exit' => $exit];
    }
}
