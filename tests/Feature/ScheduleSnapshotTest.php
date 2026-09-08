<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

/**
 * Snapshot of the scheduler's registered commands, written ahead of the
 * modularization refactor (Phase 0). Provider-coupled schedule entries
 * (SpinupWP/Pressable/DigitalOcean/Hetzner/Azure) eventually move into
 * per-module `scheduledTasks()` contributions (Phases 4-7) — this test's
 * job is to fail loudly if that migration silently drops or renames an
 * entry, not to police the exact count going forward. Update the fixture
 * deliberately whenever routes/console.php changes on purpose.
 */
class ScheduleSnapshotTest extends TestCase
{
    /**
     * Every command name currently scheduled in routes/console.php, minus
     * artisan-prefix/options — just the `clockwork:x` (or built-in) name.
     * Sorted, deduped is NOT applied: a command scheduled twice (e.g. with
     * different flags) appears twice, matching schedule:list's own output.
     */
    private const EXPECTED_COMMANDS = [
        'clockwork:auto-approve-repeats',
        'clockwork:backup-relay-run',
        'clockwork:check-blacklists',
        'clockwork:check-cloudflare',
        'clockwork:check-domain-expirations',
        'clockwork:check-robots-txt',
        'clockwork:check-site-uptime',
        'clockwork:check-ssl-certs',
        'clockwork:cleanup-spam-comments',
        'clockwork:composer-audit',
        'clockwork:detect-contact-forms',
        'clockwork:detect-stuck-companion-state',
        'clockwork:detect-wp-plugins',
        'clockwork:ensure-companion-trust-proxy',
        'clockwork:ensure-queue-worker',
        'clockwork:find-orphan-sites',
        'clockwork:import-gridpane',
        'clockwork:import-spinupwp',
        'clockwork:nightly-update-summary',
        'clockwork:poll-servers',
        'clockwork:poll-system-updates',
        'clockwork:poll-system-updates --all',
        'clockwork:pressable-backups-report',
        'clockwork:pressable-security-summary-report',
        'clockwork:pressable-traffic-report',
        'clockwork:process-pending-bans',
        'clockwork:process-server-updates',
        'clockwork:prune-server-metrics',
        'clockwork:pull-llar-lockouts',
        'clockwork:pull-site-metrics',
        'clockwork:pull-wordfence-blocks',
        'clockwork:push-companion-backups',
        'clockwork:push-companion-traffic',
        'clockwork:reap-stale-server-updates',
        'clockwork:reap-stale-update-jobs',
        'clockwork:reconcile-provider',
        'clockwork:refresh-cloudflare-real-ip',
        'clockwork:refresh-companion-capabilities',
        'clockwork:refresh-companion-snapshot',
        'clockwork:refresh-companion-snapshot --pending-updates-only',
        'clockwork:refresh-fail2ban-ignoreip',
        'clockwork:refresh-plugin-vulnerabilities',
        'clockwork:rollup-traffic --backfill=2',
        'clockwork:run-companion-malware-scans',
        // clockwork:run-nightly-plugin-updates intentionally absent — it's no
        // longer its own Schedule::command() entry. It's chained via ->then()
        // off clockwork:refresh-companion-snapshot's daily event instead, so
        // it only starts once that refresh has actually finished rather than
        // racing a fixed time offset (see routes/console.php for why).
        'clockwork:run-performance-scans --strategy=mobile --weekly-rotation',
        'clockwork:scan-sitecheck',
        'clockwork:scan-wp7-truncation --repair',
        'clockwork:security-check --ssh --quiet-ok',
        'clockwork:send-client-reports',
        'clockwork:send-telemetry',
        'clockwork:sync-allowed-bots',
        'clockwork:sync-bill-care-plans',
        'clockwork:sync-bill-customers',
        'clockwork:sync-companion-form-subscriptions',
        'clockwork:tail-nginx-logs',
        'clockwork:test-contact-forms',
        'clockwork:verify-wp-core-checksums',
        'clockwork:warm-weird-stats',
    ];

    /**
     * The subset of EXPECTED_COMMANDS whose behavior is provider-coupled
     * (SpinupWP/Pressable/DigitalOcean/Hetzner/Azure) — these are the
     * candidates for relocation into module scheduledTasks() contributions.
     * Cross-checked against EXPECTED_COMMANDS so a rename doesn't silently
     * fall out of both lists at once.
     */
    private const PROVIDER_COUPLED_COMMANDS = [
        'clockwork:check-ssl-certs',
        'clockwork:find-orphan-sites',
        'clockwork:import-spinupwp',
        'clockwork:poll-servers',
        'clockwork:poll-system-updates',
        'clockwork:pressable-backups-report',
        'clockwork:pressable-security-summary-report',
        'clockwork:pressable-traffic-report',
        'clockwork:backup-relay-run',
        'clockwork:process-server-updates',
        'clockwork:prune-server-metrics',
        'clockwork:push-companion-backups',
        'clockwork:push-companion-traffic',
        'clockwork:reap-stale-server-updates',
        'clockwork:reconcile-provider',
    ];

    public function test_scheduled_commands_match_the_known_snapshot(): void
    {
        $actual = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $this->commandName($event->command ?? ''))
            ->sort()
            ->values()
            ->all();

        $expected = collect(self::EXPECTED_COMMANDS)->sort()->values()->all();

        $this->assertSame($expected, $actual, implode("\n", [
            'The scheduled-command snapshot changed.',
            'If this is an intentional routes/console.php edit, update',
            'ScheduleSnapshotTest::EXPECTED_COMMANDS to match.',
            'If this happened during a modularization refactor (Phases 4-7),',
            'it means an entry was dropped or renamed during the move —',
            'fix the module scheduledTasks() contribution instead.',
        ]));
    }

    public function test_provider_coupled_list_is_a_subset_of_the_snapshot(): void
    {
        foreach (self::PROVIDER_COUPLED_COMMANDS as $command) {
            $this->assertContains(
                $command,
                self::EXPECTED_COMMANDS,
                "'{$command}' is listed as provider-coupled but missing from EXPECTED_COMMANDS — likely renamed."
            );
        }
    }

    private function commandName(string $rawCommand): string
    {
        // Raw form is like: '/path/to/php' 'artisan' clockwork:x --flag
        // — the php binary and "artisan" are individually shell-quoted.
        $pos = strpos($rawCommand, 'artisan');
        $tail = $pos !== false ? substr($rawCommand, $pos + strlen('artisan')) : $rawCommand;

        return trim($tail, " '\"");
    }
}
