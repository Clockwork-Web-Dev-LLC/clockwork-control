<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use App\Services\DigitalOcean\SpacesClient;

/**
 * Thin wrapper around SpacesClient::smokeTest() (already built for
 * clockwork:do-spaces-test) — lists at most one object in the bucket root.
 * Separate credential from the DigitalOceanCheck above: Spaces uses an
 * HMAC key+secret pair, not the DO personal access token.
 */
class DigitalOceanSpacesCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'do-spaces';
    }

    public function name(): string
    {
        return 'DigitalOcean Spaces';
    }

    public function description(): string
    {
        return 'List up to one object in the bucket root via the S3-compatible API.';
    }

    public function run(): CheckResult
    {
        $client = app(SpacesClient::class);
        if (! $client->isConfigured()) {
            return CheckResult::skipped('No CLOCKWORK_DO_SPACES_KEY/SECRET set');
        }

        $start = microtime(true);
        $result = $client->smokeTest();
        $ms = (int) ((microtime(true) - $start) * 1000);

        if (! $result['ok']) {
            return CheckResult::fail('Request failed', $result['message'] ?? null, $ms);
        }

        $sampleCount = count($result['sample_keys']);

        return CheckResult::ok("Bucket \"{$client->bucket()}\" reachable · {$sampleCount} sample key(s)", null, $ms);
    }
}
