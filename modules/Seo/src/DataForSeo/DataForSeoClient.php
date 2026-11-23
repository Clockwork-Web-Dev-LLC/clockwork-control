<?php

namespace Modules\Seo\DataForSeo;

use App\Models\User;
use App\Support\CredentialResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Models\ApiUsage;
use Throwable;

/**
 * The one door to DataForSEO (https://docs.dataforseo.com/v3/).
 *
 * Every paid call passes the budget guard first and is written to the spend
 * ledger afterwards with the cost DataForSEO itself reported, so the ledger
 * is the bill, not an estimate.
 *
 * Request shape, for every endpoint: a JSON array of task objects, HTTP
 * basic auth. Response shape, for every endpoint: status_code, cost and a
 * tasks array, each task with its own status_code, cost and result.
 */
class DataForSeoClient
{
    public const PROVIDER = 'dataforseo';

    public const LIVE_URL = 'https://api.dataforseo.com';

    /** Free, answers with made-up sample data. For trying things without spending. */
    public const SANDBOX_URL = 'https://sandbox.dataforseo.com';

    /** DataForSEO accepts at most this many tasks in one POST. */
    public const MAX_TASKS_PER_POST = 100;

    public function __construct(
        private readonly CredentialResolver $credentials,
        private readonly BudgetGuard $budget,
    ) {}

    public function isConfigured(): bool
    {
        return $this->login() !== '' && $this->password() !== '';
    }

    public function usesSandbox(): bool
    {
        return (bool) config('clockwork.seo.dataforseo_sandbox', false);
    }

    /**
     * A paid call.
     *
     * @param  list<array<string, mixed>>  $tasks  one object per task, 100 at most
     * @param  float  $estimateUsd  what the caller expects this to cost, checked against the caps
     * @param  ?User  $user  the person who asked, when one did; null for scheduled jobs
     */
    public function post(string $path, array $tasks, string $feature, float $estimateUsd, ?int $siteId = null, ?User $user = null): DataForSeoResult
    {
        if (! $this->isConfigured()) {
            return DataForSeoResult::failure('DataForSEO is not set up. Add the API login and password under SEO on Settings → Integrations.');
        }
        if ($tasks === [] || count($tasks) > self::MAX_TASKS_PER_POST) {
            return DataForSeoResult::failure('A DataForSEO call takes between 1 and '.self::MAX_TASKS_PER_POST.' tasks.');
        }

        // The sandbox is free, so it never counts against a cap.
        if (! $this->usesSandbox()) {
            $refusal = $this->budget->refusal($feature, $estimateUsd, $user);
            if ($refusal !== null) {
                $this->record($feature, $path, $siteId, $user, count($tasks), 0.0, ApiUsage::STATUS_REFUSED, $refusal);

                return DataForSeoResult::failure($refusal, refused: true);
            }
        }

        $result = $this->send('POST', $path, $tasks);

        $this->record(
            $feature, $path, $siteId, $user, count($tasks), $result->cost,
            $result->ok ? ApiUsage::STATUS_OK : ApiUsage::STATUS_ERROR,
            $result->error,
        );

        return $result;
    }

    /**
     * A read that DataForSEO does not charge for: collecting a finished
     * task, listing locations, checking the account. If it does report a
     * cost, that is still written to the ledger.
     */
    public function get(string $path, string $feature, ?int $siteId = null): DataForSeoResult
    {
        if (! $this->isConfigured()) {
            return DataForSeoResult::failure('DataForSEO is not set up. Add the API login and password under SEO on Settings → Integrations.');
        }

        $result = $this->send('GET', $path);

        if ($result->cost > 0) {
            $this->record($feature, $path, $siteId, null, count($result->tasks), $result->cost, ApiUsage::STATUS_OK, null);
        }

        return $result;
    }

    /**
     * The account's balance, for the settings page and the connection test.
     *
     * @return array{ok: bool, login: ?string, balance: ?float, error: ?string}
     */
    public function account(): array
    {
        $result = $this->get('/v3/appendix/user_data', Feature::KEYWORDS);
        $data = $result->firstResult()[0] ?? null;

        if (! $result->ok || ! is_array($data)) {
            return ['ok' => false, 'login' => null, 'balance' => null, 'error' => $result->error ?? 'DataForSEO returned no account details.'];
        }

        $balance = $data['money']['balance'] ?? null;

        return [
            'ok' => true,
            'login' => isset($data['login']) ? (string) $data['login'] : null,
            'balance' => is_numeric($balance) ? (float) $balance : null,
            'error' => null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    private function send(string $method, string $path, array $tasks = []): DataForSeoResult
    {
        $url = ($this->usesSandbox() ? self::SANDBOX_URL : self::LIVE_URL).'/'.ltrim($path, '/');

        try {
            $response = $method === 'GET' ? $this->http()->get($url) : $this->http()->post($url, $tasks);
        } catch (Throwable $e) {
            return DataForSeoResult::failure('DataForSEO request failed: '.$e->getMessage());
        }

        return $this->parse($response);
    }

    private function parse(Response $response): DataForSeoResult
    {
        $json = $response->json();
        $status = is_array($json) ? (int) ($json['status_code'] ?? 0) : 0;
        $message = is_array($json) ? (string) ($json['status_message'] ?? '') : '';

        if (! is_array($json) || $response->failed() || ! in_array($status, DataForSeoResult::OK_CODES, true)) {
            $error = match (true) {
                $response->status() === 401 || $status === 40100 => 'DataForSEO rejected the API login or password.',
                $response->status() === 402 || $status === 40200 => 'The DataForSEO account is out of funds. Top it up at app.dataforseo.com.',
                $response->status() === 429 || $status === 40202 => 'DataForSEO says too many requests. Try again shortly.',
                default => 'DataForSEO error '.($status ?: $response->status()).($message !== '' ? ": {$message}" : ''),
            };

            return DataForSeoResult::failure($error, $response->status(), $status);
        }

        $tasks = array_values(array_filter((array) ($json['tasks'] ?? []), 'is_array'));

        return new DataForSeoResult(
            ok: true,
            httpStatus: $response->status(),
            statusCode: $status,
            cost: (float) ($json['cost'] ?? 0),
            tasks: $tasks,
        );
    }

    private function http(): PendingRequest
    {
        // Retry a dropped connection or a server hiccup, never an answer
        // that means "no": bad login, no funds, too many requests.
        return Http::withBasicAuth($this->login(), $this->password())
            ->timeout((int) config('clockwork.seo.dataforseo_timeout', 60))
            ->retry(2, 1000, fn (Throwable $e) => ! (
                $e instanceof RequestException && $e->response->status() < 500
            ), throw: false)
            ->acceptJson();
    }

    private function record(string $feature, string $path, ?int $siteId, ?User $user, int $tasks, float $cost, string $status, ?string $error): void
    {
        try {
            ApiUsage::query()->create([
                'provider' => self::PROVIDER,
                'feature' => $feature,
                'endpoint' => mb_substr($path, 0, 191),
                'site_id' => $siteId,
                'user_id' => $user?->id,
                'tasks' => $tasks,
                'cost_usd' => $cost,
                'status' => $status,
                'error' => $error === null ? null : mb_substr($error, 0, 480),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The money is already spent. Losing the ledger row is bad, but
            // losing the data we just paid for would be worse.
            report($e);
        }
    }

    private function login(): string
    {
        return trim((string) $this->credentials->get('seo.dataforseo_login'));
    }

    private function password(): string
    {
        return trim((string) $this->credentials->get('seo.dataforseo_password'));
    }
}
