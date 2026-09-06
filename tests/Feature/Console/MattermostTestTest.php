<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Http;

/**
 * Coverage for the `clockwork:mattermost-test` command
 * (App\Console\Commands\MattermostTest), a thin wrapper over
 * Modules\Mattermost\MattermostNotifier::send(). Unlike the other
 * provider-test commands in this phase, "unconfigured" here means Mattermost
 * is disabled via CLOCKWORK_MATTERMOST_ENABLED — the command warns and exits
 * 0 rather than failing, since a disabled integration isn't an error.
 */
describe('clockwork:mattermost-test', function () {
    it('exits successfully and sends nothing when Mattermost is disabled', function () {
        config(['clockwork.mattermost.enabled' => false]);

        Http::fake();

        $this->artisan('clockwork:mattermost-test')
            ->assertSuccessful()
            ->expectsOutputToContain('Mattermost is disabled');

        Http::assertNothingSent();
    });

    it('posts the message to the webhook and reports success when Mattermost is enabled and reachable', function () {
        config([
            'clockwork.mattermost.enabled' => true,
            'clockwork.mattermost.webhook_url' => 'https://mattermost.example.com/hooks/test-token',
        ]);

        Http::fake([
            'mattermost.example.com/*' => Http::response('ok', 200),
        ]);

        $this->artisan('clockwork:mattermost-test', ['message' => 'ping from the test suite'])
            ->assertSuccessful()
            ->expectsOutputToContain('Sent.');

        Http::assertSent(function ($request) {
            return $request->url() === 'https://mattermost.example.com/hooks/test-token'
                && $request['text'] === 'ping from the test suite';
        });
    });

    it('exits with failure and reports the send failure when the webhook returns an error', function () {
        config([
            'clockwork.mattermost.enabled' => true,
            'clockwork.mattermost.webhook_url' => 'https://mattermost.example.com/hooks/test-token',
        ]);

        Http::fake([
            'mattermost.example.com/*' => Http::response('invalid webhook', 404),
        ]);

        $this->artisan('clockwork:mattermost-test')
            ->assertFailed()
            ->expectsOutputToContain('Send failed');
    });
});
