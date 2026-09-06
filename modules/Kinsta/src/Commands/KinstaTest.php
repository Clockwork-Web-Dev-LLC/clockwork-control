<?php

namespace Modules\Kinsta\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Kinsta\KinstaClient;

#[Signature('clockwork:kinsta-test', ['clockwork:test-kinsta'])]
#[Description('Verify the Kinsta API key by checking authentication against the Kinsta v2 API.')]
class KinstaTest extends Command
{
    public function handle(KinstaClient $client): int
    {
        if (! $client->isConfigured()) {
            $this->error('CLOCKWORK_KINSTA_API_KEY is not set in .env.');

            return self::FAILURE;
        }

        $this->info('Testing Kinsta API connection…');

        try {
            $mode = $client->isViewOnly() ? 'View Only (Read-Only)' : 'Full Access (Read/Write)';
            $this->line("  Operating Mode:   <comment>{$mode}</comment>");

            $result = $client->ping();
            $status = $result['status'] ?? 200;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("  Authentication:   <info>API key accepted (HTTP {$status})</info>");

        return self::SUCCESS;
    }
}
