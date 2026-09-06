<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Support\CredentialResolver;
use Illuminate\Support\Facades\Http;
use Throwable;

class GitHubOAuthCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'github-oauth';
    }

    public function name(): string
    {
        return 'GitHub OAuth';
    }

    public function description(): string
    {
        return 'Verify credentials present and reach GitHub API.';
    }

    public function run(): CheckResult
    {
        $resolver = app(CredentialResolver::class);
        $clientId = (string) ($resolver->get('auth_github.client_id') ?? config('services.github.client_id', ''));
        $clientSecret = (string) ($resolver->get('auth_github.client_secret') ?? config('services.github.client_secret', ''));

        if ($clientId === '' || $clientSecret === '') {
            return CheckResult::fail(
                'Missing GITHUB_CLIENT_ID or GITHUB_CLIENT_SECRET',
                'Set both in .env to enable GitHub login.',
            );
        }

        $start = microtime(true);
        try {
            $response = Http::timeout(8)
                ->withUserAgent('Clockwork-Control')
                ->get('https://api.github.com/octocat');
            $ms = (int) ((microtime(true) - $start) * 1000);

            if ($response->failed() && $response->status() !== 404) {
                return CheckResult::fail("GitHub API HTTP {$response->status()}", null, $ms);
            }

            $shortClient = strlen($clientId) > 12 ? substr($clientId, 0, 8).'…' : $clientId;

            return CheckResult::ok("Reachable · client {$shortClient}", null, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'GitHub API request failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
