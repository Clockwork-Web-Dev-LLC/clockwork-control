<?php

namespace Modules\AiRemedy\Services;

use App\Support\CredentialResolver;
use App\Support\EnvCredentialManager;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenRouterClient
{
    public const DEFAULT_MODEL = 'anthropic/claude-sonnet-4.5';

    public const API_URL = 'https://openrouter.ai/api/v1/chat/completions';

    public function __construct(
        protected Settings $settings,
        protected ?EnvCredentialManager $envManager = null,
    ) {
        $this->envManager ??= app(EnvCredentialManager::class);
    }

    /**
     * Retrieve the OpenRouter API key from runtime config, .env, or CredentialResolver.
     */
    public function getApiKey(): ?string
    {
        // 1. Check runtime configuration
        $configKey = config('services.openrouter.api_key');
        if (! empty($configKey)) {
            return (string) $configKey;
        }

        // 2. Read directly from the .env file
        $fileKey = $this->envManager?->getEnvValue('OPENROUTER_API_KEY');
        if (! empty($fileKey)) {
            return (string) $fileKey;
        }

        // 3. Fall back to IntegrationCredential via CredentialResolver
        try {
            $dbKey = app(CredentialResolver::class)->get('ai-remedy.openrouter_api_key')
                ?: app(CredentialResolver::class)->get('ai_remedy.openrouter_api_key');
            if (! empty($dbKey)) {
                return (string) $dbKey;
            }
        } catch (Throwable) {
            // Resolver not booted
        }

        return null;
    }

    /**
     * Store the OpenRouter API key directly in .env (never the database).
     */
    public function setApiKey(?string $apiKey): void
    {
        if (empty($apiKey)) {
            $this->envManager?->removeEnv('OPENROUTER_API_KEY');
            putenv('OPENROUTER_API_KEY=');
            unset($_ENV['OPENROUTER_API_KEY'], $_SERVER['OPENROUTER_API_KEY']);
            config(['services.openrouter.api_key' => null]);

            // Ensure database is completely clean
            $this->settings->put('clockwork.ai_remedy.openrouter_api_key', '');

            return;
        }

        $trimmed = trim($apiKey);
        $this->envManager?->writeEnv('OPENROUTER_API_KEY', $trimmed);

        // Populate runtime environment and config for the current process/request
        putenv("OPENROUTER_API_KEY={$trimmed}");
        $_ENV['OPENROUTER_API_KEY'] = $trimmed;
        $_SERVER['OPENROUTER_API_KEY'] = $trimmed;
        config(['services.openrouter.api_key' => $trimmed]);

        // Ensure database never holds the key
        $this->settings->put('clockwork.ai_remedy.openrouter_api_key', '');
    }

    /**
     * Get the configured model slug.
     */
    public function getModel(): string
    {
        return (string) $this->settings->get('clockwork.ai_remedy.model', self::DEFAULT_MODEL);
    }

    /**
     * Test OpenRouter API connectivity with a fast ping.
     *
     * @return array{ok: bool, message: string, latency_ms?: int}
     */
    public function testConnection(?string $explicitKey = null): array
    {
        $apiKey = $explicitKey ?: $this->getApiKey();

        if (empty($apiKey)) {
            return [
                'ok' => false,
                'message' => 'No OpenRouter API key configured.',
            ];
        }

        $start = microtime(true);

        try {
            $response = Http::withToken($apiKey)
                ->withHeaders([
                    'HTTP-Referer' => 'https://monitor.clockworkcontrol.com',
                    'X-Title' => 'Clockwork Control - AiRemedy',
                ])
                ->timeout(10)
                ->post(self::API_URL, [
                    'model' => 'meta-llama/llama-3.2-1b-instruct', // Fast and cheap for test ping
                    'messages' => [
                        ['role' => 'user', 'content' => 'Respond with the word "PONG" only.'],
                    ],
                    'max_tokens' => 5,
                ]);

            $latencyMs = (int) round((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                return [
                    'ok' => true,
                    'message' => "Connected successfully to OpenRouter in {$latencyMs}ms.",
                    'latency_ms' => $latencyMs,
                ];
            }

            $errorMessage = $response->json('error.message') ?? "HTTP {$response->status()}: {$response->body()}";

            return [
                'ok' => false,
                'message' => "OpenRouter error: {$errorMessage}",
                'latency_ms' => $latencyMs,
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'message' => 'Connection failed: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Analyze a server performance spike (CPU/RAM/Load) and propose remediation.
     *
     * @param  array<string, mixed>  $telemetry
     * @return array{
     *     ok: bool,
     *     summary: string,
     *     root_cause: string,
     *     safety_tier: string,
     *     is_fixable: bool,
     *     commands: array<int, string>,
     *     explanation: string,
     *     unfixable_briefing: ?string,
     *     prompt_tokens: int,
     *     completion_tokens: int,
     *     cost_usd: float,
     *     error?: string
     * }
     */
    public function diagnoseServerSpike(array $telemetry, string $reason): array
    {
        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            return [
                'ok' => false,
                'summary' => 'OpenRouter API key is missing.',
                'root_cause' => 'API Key Unconfigured',
                'safety_tier' => 'unfixable',
                'is_fixable' => false,
                'commands' => [],
                'explanation' => 'Please configure an OpenRouter API key in Settings → AiRemedy.',
                'unfixable_briefing' => null,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cost_usd' => 0.0,
                'error' => 'API key missing',
            ];
        }

        $systemPrompt = <<<'SYS'
You are AiRemedy, a senior Linux systems engineer and performance specialist for Clockwork Control (managing Ubuntu servers running SpinupWP, GridPane, Nginx, PHP-FPM, MySQL, and Redis).

Analyze the provided server telemetry and diagnostic outputs to identify the root cause of the performance spike (CPU, RAM, or Load).
Determine safe, non-destructive bash remediation commands to restore normal performance.

CRITICAL SAFETY RULES:
1. ONLY propose non-destructive commands. Never propose `rm -rf /` or dropping databases.
2. If restarting services, target the exact worker pool (e.g., `sudo systemctl reload php8.3-fpm` or `sudo systemctl reload nginx`).
3. If killing runaway processes, provide exact PID-based commands (e.g., `sudo kill -15 <PID>` or `sudo kill -9 <PID>`).
4. Categorize the safety_tier:
   - "tier_1_safe": Service reload/restart, clearing caches, log rotation, deprioritizing CPU/IO (renice/ionice).
   - "tier_2_cautious": Killing rogue worker processes, restarting MySQL.
   - "unfixable": Hardware bottleneck, physical RAM exhaustion needing droplet resize, persistent DDoS, code bug in client script.
5. Each command in "commands" MUST be a single, standalone bash command. NEVER use shell chaining (&&, ||), pipes (|), subshells ($(), ``), or redirection (>, 2>/dev/null). Write each command plainly, with no trailing fallbacks.
6. SITE COMMANDS: the telemetry's "wordpress_sites" list is the only source of truth for sites on this server. For any wp-cli or site-file command, use that site's exact "wp_path" and run as its "site_user", e.g. "sudo -u <site_user> wp cache flush --path=<wp_path>". NEVER guess or invent paths (such as /var/www/... or htdocs). If the site you need isn't listed, don't propose a site command.
7. EXPECTED MAINTENANCE VS TRUE INCIDENTS:
   - If the CPU/load spike is caused by scheduled or expected background maintenance (such as an rclone process uploading backups to S3, mysqldump, logrotate, or borgbackup):
     - Set "is_maintenance": true
     - Set "maintenance_type": "SpinupWP Backup" (or "Database Backup", "Log Rotation", etc.)
     - DO NOT kill the backup process! Propose non-destructive prioritization adjustments so it yields CPU cycles to web traffic without failing the backup:
       `sudo renice -n 19 -p <PID>`
       `sudo ionice -c 3 -p <PID>`
     - Or propose empty commands `[]` if the backup is running normally and should simply be allowed to complete.

Return ONLY a valid JSON object with this exact schema:
{
  "summary": "1-2 sentence executive overview of what is happening.",
  "root_cause": "The specific culprit causing the spike.",
  "is_maintenance": true | false,
  "maintenance_type": null | "SpinupWP Backup",
  "safety_tier": "tier_1_safe" | "tier_2_cautious" | "unfixable",
  "is_fixable": true | false,
  "commands": ["bash command 1", "bash command 2"],
  "explanation": "Clear markdown explanation of why these commands resolve the issue.",
  "unfixable_briefing": null or "Markdown briefing if human intervention or droplet resize is required."
}
SYS;

        $userPrompt = "Incident Trigger: {$reason}\n\nServer Telemetry Snapshot:\n```json\n"
            .json_encode($telemetry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ."\n```\n\nDiagnose the incident and return the remediation JSON.";

        return $this->chatJson($systemPrompt, $userPrompt);
    }

    /**
     * Analyze a site downtime incident (e.g. 502 Bad Gateway, 503 Service Unavailable, timeout).
     *
     * @param  array<string, mixed>  $telemetry
     * @return array{
     *     ok: bool,
     *     summary: string,
     *     root_cause: string,
     *     safety_tier: string,
     *     is_fixable: bool,
     *     commands: array<int, string>,
     *     explanation: string,
     *     unfixable_briefing: ?string,
     *     prompt_tokens: int,
     *     completion_tokens: int,
     *     cost_usd: float,
     *     error?: string
     * }
     */
    public function diagnoseSiteDowntime(string $domain, int $statusCode, array $telemetry, ?string $errorMessage = null): array
    {
        $apiKey = $this->getApiKey();
        if (empty($apiKey)) {
            return [
                'ok' => false,
                'summary' => 'OpenRouter API key is missing.',
                'root_cause' => 'API Key Unconfigured',
                'safety_tier' => 'unfixable',
                'is_fixable' => false,
                'commands' => [],
                'explanation' => 'Please configure an OpenRouter API key in Settings → AiRemedy.',
                'unfixable_briefing' => null,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cost_usd' => 0.0,
                'error' => 'API key missing',
            ];
        }

        $systemPrompt = <<<'SYS'
You are AiRemedy, a senior Linux systems engineer and site reliability specialist for Clockwork Control (managing WordPress sites on Ubuntu with Nginx, PHP-FPM, MySQL, and Redis).

A monitored website is DOWN. Analyze the HTTP response code, SSH diagnostics (FPM socket, service state, server load, and error log excerpts) to determine the exact root cause.
Determine if the issue can be safely remediated via bash commands or if it requires human developer intervention.

CRITICAL RULES:
1. ONLY propose non-destructive commands (e.g. reloading/restarting the specific PHP-FPM pool or Nginx, clearing stale `.maintenance` file, clearing cache).
2. If the outage is caused by a fatal PHP parse error, missing database table, corrupted plugin, or external API timeout, mark `is_fixable: false` and `safety_tier: "unfixable"`, and provide a detailed `unfixable_briefing`.
3. Categorize safety_tier: "tier_1_safe" | "tier_2_cautious" | "unfixable".
4. Each command in "commands" MUST be a single, standalone bash command. NEVER use shell chaining (&&, ||), pipes (|), subshells ($(), ``), or redirection (>, 2>/dev/null). Write each command plainly, with no trailing fallbacks.
5. SITE COMMANDS: use the telemetry's exact "wp_path" and "site_user" for this site, e.g. "sudo -u <site_user> wp cache flush --path=<wp_path>". NEVER guess or invent paths (such as /var/www/... or htdocs).


Return ONLY a valid JSON object matching this schema:
{
  "summary": "1-2 sentence executive overview of why the site is down.",
  "root_cause": "The specific culprit causing the downtime.",
  "safety_tier": "tier_1_safe" | "tier_2_cautious" | "unfixable",
  "is_fixable": true | false,
  "commands": ["bash command 1", "bash command 2"],
  "explanation": "Clear markdown explanation of the root cause and why these commands fix it.",
  "unfixable_briefing": null or "Markdown briefing with stack trace analysis and developer action items if unfixable."
}
SYS;

        $userPrompt = "Target Site: {$domain}\nHTTP Status: {$statusCode}".($errorMessage ? " ({$errorMessage})" : '')."\n\nDiagnostic Snapshot:\n```json\n"
            .json_encode($telemetry, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            ."\n```\n\nDiagnose the site outage and return the remediation JSON.";

        return $this->chatJson($systemPrompt, $userPrompt);
    }

    /**
     * Send chat completion request to OpenRouter requesting JSON.
     *
     * @return array{
     *     ok: bool,
     *     summary: string,
     *     root_cause: string,
     *     safety_tier: string,
     *     is_fixable: bool,
     *     commands: array<int, string>,
     *     explanation: string,
     *     unfixable_briefing: ?string,
     *     prompt_tokens: int,
     *     completion_tokens: int,
     *     cost_usd: float,
     *     error?: string
     * }
     */
    protected function chatJson(string $systemPrompt, string $userPrompt): array
    {
        $apiKey = $this->getApiKey();
        $model = $this->getModel();

        try {
            $response = Http::withToken($apiKey)
                ->withHeaders([
                    'HTTP-Referer' => 'https://monitor.clockworkcontrol.com',
                    'X-Title' => 'Clockwork Control - AiRemedy',
                ])
                ->timeout(30)
                ->post(self::API_URL, [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'response_format' => ['type' => 'json_object'],
                    'temperature' => 0.1,
                ]);

            if (! $response->successful()) {
                $err = $response->json('error.message') ?? $response->body();
                Log::error('openrouter.api_error', ['status' => $response->status(), 'error' => $err]);

                return [
                    'ok' => false,
                    'summary' => "OpenRouter API call failed: {$err}",
                    'root_cause' => 'API Error',
                    'safety_tier' => 'unfixable',
                    'is_fixable' => false,
                    'commands' => [],
                    'explanation' => "Failed to communicate with OpenRouter ({$err}).",
                    'unfixable_briefing' => null,
                    'prompt_tokens' => 0,
                    'completion_tokens' => 0,
                    'cost_usd' => 0.0,
                    'error' => $err,
                ];
            }

            $content = (string) $response->json('choices.0.message.content', '{}');
            $parsed = json_decode($content, true);

            if (! is_array($parsed)) {
                // If model wrapped JSON in markdown code fence, strip it
                if (preg_match('/```(?:json)?\s*(\{.*?\})\s*```/s', $content, $m)) {
                    $parsed = json_decode($m[1], true);
                }
            }

            $promptTokens = (int) $response->json('usage.prompt_tokens', 0);
            $completionTokens = (int) $response->json('usage.completion_tokens', 0);
            $costUsd = $this->calculateCost($model, $promptTokens, $completionTokens);

            if (! is_array($parsed)) {
                return [
                    'ok' => false,
                    'summary' => 'Could not parse JSON response from AI.',
                    'root_cause' => 'Parse Error',
                    'safety_tier' => 'unfixable',
                    'is_fixable' => false,
                    'commands' => [],
                    'explanation' => $content,
                    'unfixable_briefing' => null,
                    'prompt_tokens' => $promptTokens,
                    'completion_tokens' => $completionTokens,
                    'cost_usd' => $costUsd,
                    'error' => 'Malformed JSON response',
                ];
            }

            return [
                'ok' => true,
                'summary' => (string) ($parsed['summary'] ?? 'Diagnosis complete.'),
                'root_cause' => (string) ($parsed['root_cause'] ?? 'Unknown cause.'),
                'is_maintenance' => (bool) ($parsed['is_maintenance'] ?? false),
                'maintenance_type' => ! empty($parsed['maintenance_type']) ? (string) $parsed['maintenance_type'] : null,
                'safety_tier' => (string) ($parsed['safety_tier'] ?? 'tier_1_safe'),
                'is_fixable' => (bool) ($parsed['is_fixable'] ?? true),
                'commands' => array_values(array_filter((array) ($parsed['commands'] ?? []))),
                'explanation' => (string) ($parsed['explanation'] ?? ''),
                'unfixable_briefing' => $parsed['unfixable_briefing'] ?? null,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'cost_usd' => $costUsd,
            ];
        } catch (Throwable $e) {
            Log::error('openrouter.exception', ['message' => $e->getMessage()]);

            return [
                'ok' => false,
                'summary' => 'AI diagnosis exception: '.$e->getMessage(),
                'root_cause' => 'Exception',
                'safety_tier' => 'unfixable',
                'is_fixable' => false,
                'commands' => [],
                'explanation' => $e->getMessage(),
                'unfixable_briefing' => null,
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'cost_usd' => 0.0,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Calculate cost in USD based on model pricing per 1M tokens.
     */
    public function calculateCost(string $model, int $promptTokens, int $completionTokens): float
    {
        // Pricing per 1,000,000 tokens
        [$promptRate, $completionRate] = match (true) {
            str_contains($model, 'claude-3.5-sonnet') => [3.00, 15.00],
            str_contains($model, 'claude-3.5-haiku') => [0.80, 4.00],
            str_contains($model, 'gpt-4o-mini') => [0.15, 0.60],
            str_contains($model, 'gpt-4o') => [2.50, 10.00],
            str_contains($model, 'deepseek') => [0.14, 0.28],
            default => [3.00, 15.00],
        };

        $promptCost = ($promptTokens / 1_000_000) * $promptRate;
        $completionCost = ($completionTokens / 1_000_000) * $completionRate;

        return round($promptCost + $completionCost, 4);
    }
}
