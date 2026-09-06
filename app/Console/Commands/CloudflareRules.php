<?php

namespace App\Console\Commands;

use App\Services\Cloudflare\CloudflareClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('clockwork:cf-rules {domain : Zone name, e.g. cfclient.example} {--filter= : Substring to filter rule expressions/actions on}')]
#[Description('Dump Cloudflare redirect/transform/WAF/page rules for a zone. Use --filter to spotlight rules whose expression mentions a specific path or country.')]
class CloudflareRules extends Command
{
    public function handle(CloudflareClient $cf): int
    {
        if (! $cf->isConfigured()) {
            $this->error('CLOCKWORK_CLOUDFLARE_API_TOKEN is not set in .env.');
            $this->line('Generate one at https://dash.cloudflare.com/profile/api-tokens with these permissions:');
            $this->line('  Zone → Zone → Read');
            $this->line('  Zone → Zone WAF → Read   (rulesets API)');
            $this->line('  Zone → Page Rules → Read');
            $this->line('Apply to "All zones from an account" for fleet-wide diagnosis.');

            return self::FAILURE;
        }

        $domain = $this->argument('domain');
        $filter = $this->option('filter');

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

        $this->info("Zone: {$zone['name']} ({$zone['id']})  status: {$zone['status']}  plan: ".($zone['plan']['name'] ?? '?'));
        $this->newLine();

        // Phases worth checking when a request URL is being mangled. Order matches request lifecycle.
        // Each phase requires its own Cloudflare token permission — they're not bundled.
        $phases = [
            'http_request_firewall_custom' => ['Custom WAF rules', 'Zone WAF'],
            // Note: Cloudflare split what was once 'Transform Rules: Read' into two
            // distinct permission groups. Redirect Rules need 'Dynamic Redirect: Read'
            // (search for 'redirect' in the token-edit dropdown). URL Rewrite Rules
            // still use 'Transform Rules: Read'.
            'http_request_dynamic_redirect' => ['Redirect Rules', 'Dynamic Redirect'],
            'http_request_transform' => ['Transform Rules / URL Rewrite', 'Transform Rules'],
            'http_config_settings' => ['Configuration Rules', 'Config Rules'],
        ];

        $missingPerms = [];

        foreach ($phases as $phase => [$label, $tokenPerm]) {
            $result = $cf->phaseRules($zone['id'], $phase);

            if ($result['error']) {
                $this->line("<fg=red>{$label}: forbidden — token needs '{$tokenPerm}: Read'</>");
                $missingPerms[] = $tokenPerm;

                continue;
            }

            $rules = $result['rules'];

            if ($filter !== null && $filter !== '') {
                $rules = array_values(array_filter($rules, fn ($r) => stripos(json_encode($r), $filter) !== false));
            }

            if ($rules === []) {
                $this->line("<fg=gray>{$label}: (none)</>");

                continue;
            }

            $this->line("<fg=cyan>{$label}: ".count($rules).'</>');

            foreach ($rules as $rule) {
                $this->renderRule($rule);
            }

            $this->newLine();
        }

        if ($missingPerms !== []) {
            $this->newLine();
            $this->warn('Token is missing permissions for some phases. To see everything, edit the token at');
            $this->warn('https://dash.cloudflare.com/profile/api-tokens and add: '.implode(', ', array_unique($missingPerms)).' (Read).');
        }

        $pageRules = $cf->pageRules($zone['id']);
        if ($filter !== null && $filter !== '') {
            $pageRules = array_values(array_filter($pageRules, fn ($r) => stripos(json_encode($r), $filter) !== false));
        }

        if ($pageRules !== []) {
            $this->line('<fg=cyan>Page Rules (legacy): '.count($pageRules).'</>');
            foreach ($pageRules as $rule) {
                $this->renderPageRule($rule);
            }
        } else {
            $this->line('<fg=gray>Page Rules (legacy): (none)</>');
        }

        return self::SUCCESS;
    }

    private function renderRule(array $rule): void
    {
        $enabled = ($rule['enabled'] ?? true) ? '<fg=green>ON</>' : '<fg=red>OFF</>';
        $action = $rule['action'] ?? '?';
        $desc = $rule['description'] ?? '(no description)';
        $expr = $rule['expression'] ?? '';

        $this->line("  [{$enabled}] {$action} — {$desc}");
        $this->line("        expr: {$expr}");

        // Actions that mutate the URL store their target under action_parameters.
        $params = $rule['action_parameters'] ?? null;
        if ($params) {
            // Redirect rules: ['from_value' => ['target_url' => ['expression' => '...'], 'preserve_query_string' => true, 'status_code' => 301]]
            if (isset($params['from_value'])) {
                $target = $params['from_value']['target_url']['expression']
                    ?? $params['from_value']['target_url']['value']
                    ?? '?';
                $code = $params['from_value']['status_code'] ?? '?';
                $this->line("        → redirect [{$code}] to: {$target}");
            }

            // URL rewrite Transform rules: ['uri' => ['path' => ['expression' => 'concat("/foo", ...)']]]
            if (isset($params['uri'])) {
                $pathExpr = $params['uri']['path']['expression']
                    ?? $params['uri']['path']['value']
                    ?? null;
                $queryExpr = $params['uri']['query']['expression']
                    ?? $params['uri']['query']['value']
                    ?? null;
                if ($pathExpr) {
                    $this->line("        → rewrite path: {$pathExpr}");
                }
                if ($queryExpr) {
                    $this->line("        → rewrite query: {$queryExpr}");
                }
            }
        }
    }

    private function renderPageRule(array $rule): void
    {
        $status = ($rule['status'] ?? 'disabled') === 'active' ? '<fg=green>ON</>' : '<fg=red>OFF</>';
        $targets = collect($rule['targets'] ?? [])
            ->map(fn ($t) => $t['constraint']['value'] ?? '?')
            ->implode(', ');
        $actions = collect($rule['actions'] ?? [])
            ->map(fn ($a) => ($a['id'] ?? '?').(isset($a['value']) ? '='.(is_array($a['value']) ? json_encode($a['value']) : $a['value']) : ''))
            ->implode('; ');

        $this->line("  [{$status}] {$targets}");
        $this->line("        actions: {$actions}");
    }
}
