<?php

namespace App\Console\Commands;

use App\Services\Cloudflare\CloudflareClient;
use Illuminate\Console\Command;

class AddCloudflareRateLimit extends Command
{
    protected $signature = 'clockwork:cf-rate-limit
        {domain : Zone to target, e.g. museumclient.example}
        {--requests=60 : Max requests per IP per period before blocking}
        {--period=60 : Rolling window in seconds}
        {--mitigation=3600 : How long (seconds) to block an IP once it trips the limit}
        {--description= : Rule description (default: auto-generated)}
        {--list : List existing rate limit rules for the zone instead of adding one}
        {--remove= : Remove a rate limit rule by its CF rule ID}
        {--dry-run : Print the rule that would be created without touching CF}';

    protected $description = 'Add (or list/remove) a Cloudflare rate limiting rule for a zone. Blocks IPs that exceed N requests per period. Safe: fetches existing rules first so nothing is accidentally removed.';

    public function handle(CloudflareClient $cf): int
    {
        if (! $cf->isConfigured()) {
            $this->error('CLOCKWORK_CLOUDFLARE_API_TOKEN is not set.');

            return self::FAILURE;
        }

        $domain = $this->argument('domain');

        try {
            $zone = $cf->zoneByName($domain);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (! $zone) {
            $this->error("Zone '{$domain}' not found in this Cloudflare account.");

            return self::FAILURE;
        }

        $zoneId = $zone['id'];
        $this->line("Zone: {$zone['name']} ({$zoneId})");

        // ── List mode ────────────────────────────────────────────────────────
        if ($this->option('list')) {
            return $this->listRules($cf, $zoneId);
        }

        // ── Remove mode ──────────────────────────────────────────────────────
        if ($ruleId = $this->option('remove')) {
            return $this->removeRule($cf, $zoneId, $ruleId);
        }

        // ── Add mode ─────────────────────────────────────────────────────────
        $requests = (int) $this->option('requests');
        $period = (int) $this->option('period');
        $mitigation = (int) $this->option('mitigation');
        $description = (string) ($this->option('description')
            ?: "Clockwork: block IPs exceeding {$requests} req/{$period}s on {$domain}");

        $newRule = [
            'action' => 'block',
            'ratelimit' => [
                'characteristics' => ['ip.src'],
                'period' => $period,
                'requests_per_period' => $requests,
                'mitigation_timeout' => $mitigation,
            ],
            'expression' => "http.host eq \"{$domain}\"",
            'description' => $description,
            'enabled' => true,
        ];

        if ($this->option('dry-run')) {
            $this->line('Would create rule:');
            $this->line(json_encode($newRule, JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        if (! $cf->isWriteConfigured()) {
            $this->error('CLOCKWORK_CLOUDFLARE_WRITE_TOKEN is not set. The write token needs Zone.Firewall Services:Edit scope.');

            return self::FAILURE;
        }

        try {
            $existing = $cf->getRateLimitRules($zoneId);
            $this->line('Existing rate limit rules: '.count($existing));

            // Strip CF-assigned fields from existing rules so PUT doesn't choke on read-only fields.
            $stripped = array_map(fn ($r) => array_intersect_key($r, array_flip([
                'id', 'action', 'ratelimit', 'expression', 'description', 'enabled', 'ref',
            ])), $existing);

            $updated = $cf->putRateLimitRules($zoneId, [...$stripped, $newRule]);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            $this->line('');
            $this->line('The write token likely needs Zone → Firewall Services: Edit permission.');
            $this->line('Edit your token at https://dash.cloudflare.com/profile/api-tokens');

            return self::FAILURE;
        }

        $addedRule = collect($updated['rules'] ?? [])->last();
        $ruleId = $addedRule['id'] ?? '?';

        $this->info("Rate limit rule created (id: {$ruleId})");
        $this->line("  {$requests} req / {$period}s window → block for {$mitigation}s");
        $this->line("  Expression: http.host eq \"{$domain}\"");
        $this->line("  To remove: php artisan clockwork:cf-rate-limit {$domain} --remove={$ruleId}");

        return self::SUCCESS;
    }

    private function listRules(CloudflareClient $cf, string $zoneId): int
    {
        try {
            $rules = $cf->getRateLimitRules($zoneId);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($rules === []) {
            $this->line('No rate limit rules found.');

            return self::SUCCESS;
        }

        foreach ($rules as $rule) {
            $enabled = ($rule['enabled'] ?? true) ? '<fg=green>ON</>' : '<fg=red>OFF</>';
            $id = $rule['id'] ?? '?';
            $desc = $rule['description'] ?? '(no description)';
            $rl = $rule['ratelimit'] ?? [];
            $this->line("[{$enabled}] {$id} — {$desc}");
            $this->line('      expr: '.($rule['expression'] ?? ''));
            if ($rl) {
                $this->line("      limit: {$rl['requests_per_period']} req/{$rl['period']}s, block {$rl['mitigation_timeout']}s");
            }
        }

        return self::SUCCESS;
    }

    private function removeRule(CloudflareClient $cf, string $zoneId, string $ruleId): int
    {
        if (! $cf->isWriteConfigured()) {
            $this->error('CLOCKWORK_CLOUDFLARE_WRITE_TOKEN is not set.');

            return self::FAILURE;
        }

        try {
            $cf->deleteRateLimitRule($zoneId, $ruleId);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Rule {$ruleId} removed.");

        return self::SUCCESS;
    }
}
