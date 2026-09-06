<?php

namespace Tests\Feature;

use App\Services\Chat\ChatNotifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Phase 7 characterization test: AppServiceProvider used to bind ChatNotifier
 * to a ChatNotifierDispatcher built from a hardcoded 3-item array literal.
 * It's now built from $app->tagged('clockwork.notifiers') instead, so a
 * future module can contribute its own channel — this pins that the
 * container-tag wiring fans a call out to every real channel exactly like
 * the hardcoded array did.
 */
class ChatNotifierDispatchTest extends TestCase
{
    public function test_send_fans_out_to_every_tagged_notifier(): void
    {
        config([
            'clockwork.mattermost.enabled' => true,
            'clockwork.mattermost.webhook_url' => 'https://mattermost.example.test/hooks/abc',
            'clockwork.slack.enabled' => true,
            'clockwork.slack.webhook_url' => 'https://hooks.slack.example.test/services/abc',
        ]);

        Http::fake([
            'mattermost.example.test/*' => Http::response(['ok' => true], 200),
            'hooks.slack.example.test/*' => Http::response('ok', 200),
        ]);

        $result = app(ChatNotifier::class)->send('Phase 7 tag-based dispatch test');

        $this->assertTrue($result);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'mattermost.example.test'));
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'hooks.slack.example.test'));
    }

    public function test_send_returns_false_when_no_channel_is_configured(): void
    {
        config([
            'clockwork.mattermost.enabled' => false,
            'clockwork.slack.enabled' => false,
        ]);

        Http::fake();

        $this->assertFalse(app(ChatNotifier::class)->send('should not send anywhere'));
        Http::assertNothingSent();
    }
}
