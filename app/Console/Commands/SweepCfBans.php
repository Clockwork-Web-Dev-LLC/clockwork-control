<?php

namespace App\Console\Commands;

use App\Models\BlockedIp;
use App\Services\Cloudflare\CloudflareDetector;
use App\Services\Fail2ban\Fail2banClient;
use Illuminate\Console\Command;

class SweepCfBans extends Command
{
    protected $signature = 'clockwork:sweep-cf-bans
        {--dry-run : List what would be unbanned without actually unbanning}';

    protected $description = "Sweep every server for fail2ban bans whose IP is in Cloudflare's published edge range. CF edges shouldn't be banned — bans against them are almost always misattributed lockouts coming from real visitors behind CF. Reversible: anything that's actually a real attacker will get re-banned on the next 4 lockouts.";

    public function handle(CloudflareDetector $detector, Fail2banClient $fail2ban): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $cfRanges = $detector->ranges();
        $this->info('Loaded '.count($cfRanges).' Cloudflare IPv4 CIDR ranges.');

        // Pull every active ban; group by server. We re-check each IP against the
        // live CF range list rather than trust a heuristic — single source of truth.
        $active = BlockedIp::query()
            ->with('server')
            ->whereNull('unbanned_at')
            ->get();

        $byServer = $active
            ->filter(fn ($b) => $b->server !== null && $b->server->clockwork_jail_provisioned_at !== null)
            ->filter(fn ($b) => $detector->isCloudflareIp($b->ip))
            ->groupBy('server_id');

        if ($byServer->isEmpty()) {
            $this->info('No active CF-IP bans found across the fleet. Nothing to do.');

            return self::SUCCESS;
        }

        $totalCfBans = $byServer->flatten()->count();
        $serverCount = $byServer->count();
        $this->info("Found {$totalCfBans} CF-IP bans across {$serverCount} servers.");

        if ($dryRun) {
            $this->warn('Dry run — not unbanning. Per-server breakdown:');
        }

        $totalUnbanned = 0;
        $totalFailed = 0;
        $now = now();

        foreach ($byServer as $serverId => $bans) {
            $server = $bans->first()->server;
            $ips = $bans->pluck('ip')->unique()->values()->all();
            $this->line(sprintf('  %s — %d CF bans', $server->name, count($ips)));

            if ($dryRun) {
                foreach ($ips as $ip) {
                    $this->line("    {$ip}");
                }

                continue;
            }

            $result = $fail2ban->unbanIps($server, $ips);

            // Mark every successfully-unbanned IP in our local mirror so the UI stays honest.
            foreach ($result['results'] as $ip => $r) {
                if ($r['ok']) {
                    BlockedIp::query()
                        ->where('server_id', $serverId)
                        ->where('ip', $ip)
                        ->whereNull('unbanned_at')
                        ->update(['unbanned_at' => $now]);
                    $totalUnbanned++;
                } else {
                    $totalFailed++;
                    $this->warn("    ✗ {$ip} on {$server->name}: ".trim($r['output']));
                }
            }

            $this->line('    → '.$result['message']);
        }

        if (! $dryRun) {
            $this->line('');
            $this->info("Done. unbanned={$totalUnbanned}, failed={$totalFailed}");
        }

        return $totalFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
