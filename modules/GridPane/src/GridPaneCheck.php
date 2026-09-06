<?php

namespace Modules\GridPane;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

class GridPaneCheck implements DiagnosticCheck
{
    public function __construct(private readonly CredentialResolver $resolver) {}

    public function id(): string
    {
        return 'gridpane';
    }

    public function name(): string
    {
        return 'GridPane API';
    }

    public function description(): string
    {
        return 'Verify the API token via GET /user.';
    }

    public function run(): CheckResult
    {
        $token = (string) $this->resolver->get('gridpane.api_key');
        if ($token === '') {
            return CheckResult::skipped('No GRIDPANE_API_KEY set');
        }

        $baseUrl = (string) $this->resolver->get('gridpane.base_url', GridPaneClient::DEFAULT_BASE_URL);

        $start = microtime(true);
        try {
            $response = Http::baseUrl($baseUrl)
                ->withToken($token)
                ->timeout(8)
                ->acceptJson()
                ->get('/user');

            $ms = (int) ((microtime(true) - $start) * 1000);
            if ($response->failed()) {
                return CheckResult::fail(
                    "HTTP {$response->status()}",
                    substr((string) $response->body(), 0, 500),
                    $ms,
                );
            }

            $email = $response->json('email') ?? $response->json('data.email') ?? $response->json('name');
            $userPart = ! empty($email) ? " · User: {$email}" : '';

            $viewOnlyVal = $this->resolver->get('gridpane.view_only');
            $isViewOnly = $viewOnlyVal !== null ? filter_var($viewOnlyVal, FILTER_VALIDATE_BOOLEAN) : (bool) config('clockwork.gridpane.view_only', true);
            $modePart = $isViewOnly ? ' · Mode: View Only' : '';

            return CheckResult::ok("Token valid{$userPart}{$modePart}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
