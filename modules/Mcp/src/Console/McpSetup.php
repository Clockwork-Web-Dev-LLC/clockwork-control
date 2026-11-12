<?php

namespace Modules\Mcp\Console;

use Illuminate\Console\Command;
use Modules\Mcp\Support\ClaudeConnector;
use Throwable;

class McpSetup extends Command
{
    protected $signature = 'clockwork:mcp-setup
                            {--rotate-secret : Issue a new client secret; the old one stops working}';

    protected $description = 'Create the signing keys and the OAuth client Claude signs in through (same as the Set up button on Settings → Claude access)';

    public function handle(ClaudeConnector $connector): int
    {
        try {
            if ($connector->ensureKeys()) {
                $this->info('Created the signing keys in storage/.');
            }

            if ($this->option('rotate-secret')) {
                $secret = $connector->rotateSecret();
                $client = $connector->client();
                $this->info('Issued a new client secret. The old one no longer works.');
                $this->printCredentials((string) $client?->getKey(), $secret);

                return self::SUCCESS;
            }

            $result = $connector->ensureClient();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['created']) {
            $this->info('Created the Claude client.');
            $this->printCredentials((string) $result['client']->getKey(), (string) $result['client']->plainSecret);
        } else {
            $this->line('The Claude client already exists. Client ID: '.$result['client']->getKey());
            $this->line('Its secret is stored hashed and can\'t be shown again. Use --rotate-secret for a new one.');
        }

        $this->line('Connector URL: '.$connector->endpointUrl());
        if (! $connector->enabled()) {
            $this->warn('Claude access is switched off. Turn it on at Settings → Claude access.');
        }

        return self::SUCCESS;
    }

    private function printCredentials(string $clientId, string $secret): void
    {
        $this->line('Client ID:     '.$clientId);
        $this->line('Client secret: '.$secret);
        $this->warn('Copy the secret now. It is not stored in readable form and won\'t be shown again.');
    }
}
