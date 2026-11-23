<?php

namespace Modules\Seo\Diagnostics;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Modules\Seo\Google\SearchConsoleClient;
use Modules\Seo\Models\GoogleConnection;

/**
 * /settings/diagnostics runs every check on each load, so this reuses the
 * cached access token and makes a single sites.list call. Skipped when no
 * connection was ever made.
 */
class GoogleConnectionCheck implements DiagnosticCheck
{
    public function __construct(private readonly SearchConsoleClient $searchConsole) {}

    public function id(): string
    {
        return 'seo-google';
    }

    public function name(): string
    {
        return 'SEO Google connection';
    }

    public function description(): string
    {
        return 'Lists the Search Console properties the agency Google connection can see.';
    }

    public function run(): CheckResult
    {
        $connection = GoogleConnection::current();
        if ($connection === null) {
            return CheckResult::skipped('Not connected. Connect Google on /seo/settings.');
        }

        $start = microtime(true);
        $result = $this->searchConsole->listSites($connection);
        $ms = (int) ((microtime(true) - $start) * 1000);

        if (! $result->ok) {
            return CheckResult::fail(
                $result->isRevoked() ? 'Google connection revoked' : 'Search Console request failed',
                $result->error,
                $ms,
            );
        }

        $count = count((array) ($result->json['siteEntry'] ?? []));
        $account = $connection->account_email ?? 'the agency account';

        return CheckResult::ok("Connected as {$account} · {$count} Search Console ".($count === 1 ? 'property' : 'properties'), null, $ms);
    }
}
