<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Modules\Mattermost\MattermostNotifier;

#[Signature('clockwork:mattermost-test {message=Clockwork test message}')]
#[Description('Send a test message to the configured Mattermost incoming webhook.')]
class MattermostTest extends Command
{
    public function handle(MattermostNotifier $notifier): int
    {
        if (! config('clockwork.mattermost.enabled')) {
            $this->warn('Mattermost is disabled (CLOCKWORK_MATTERMOST_ENABLED=false). Nothing sent.');

            return self::SUCCESS;
        }

        $message = (string) $this->argument('message');
        $sent = $notifier->send($message);

        if ($sent) {
            $this->info('Sent.');

            return self::SUCCESS;
        }

        $this->error('Send failed. Check logs and CLOCKWORK_MATTERMOST_WEBHOOK_URL.');

        return self::FAILURE;
    }
}
