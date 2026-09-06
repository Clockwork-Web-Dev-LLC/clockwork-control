<?php

use App\Models\User;
use App\Support\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

describe('SendTelemetry command and maintenance endpoints', function () {
    it('sends telemetry payload successfully to configured endpoint', function () {
        Http::fake([
            'https://telemetry.clockworkcontrol.com/*' => Http::response(null, 204),
        ]);

        $this->artisan('clockwork:send-telemetry')
            ->assertSuccessful()
            ->expectsOutputToContain('Telemetry sent:');

        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'https://telemetry.clockworkcontrol.com/v1/report')
                && $request->hasHeader('User-Agent')
                && str_starts_with($request->header('User-Agent')[0], 'Clockwork-Control/')
                && isset($request['schema_version'])
                && isset($request['install_id'])
                && isset($request['sites_count_bucket']);
        });
    });

    it('handles non-2xx HTTP responses gracefully without failing the command', function () {
        Http::fake([
            'https://telemetry.clockworkcontrol.com/*' => Http::response('Bad request', 400),
        ]);

        $this->artisan('clockwork:send-telemetry')
            ->assertSuccessful()
            ->expectsOutputToContain('Telemetry send failed: HTTP 400');
    });

    it('handles network connection exceptions gracefully without failing the command', function () {
        Http::fake([
            'https://telemetry.clockworkcontrol.com/*' => fn () => throw new ConnectionException('Connection timed out'),
        ]);

        $this->artisan('clockwork:send-telemetry')
            ->assertSuccessful()
            ->expectsOutputToContain('Telemetry send failed: Connection timed out');
    });

    it('updates telemetry enabled setting via maintenance route', function () {
        $user = User::factory()->create();
        $settings = app(Settings::class);

        $settings->put('telemetry.enabled', false);

        $response = $this->actingAs($user)
            ->patch(route('settings.maintenance.telemetry.update'), [
                'enabled' => '1',
            ]);

        $response->assertRedirect(route('settings.maintenance.index'))
            ->assertSessionHas('status', 'Telemetry setting saved.');

        expect($settings->get('telemetry.enabled'))->toBeTrue();

        $this->actingAs($user)
            ->patch(route('settings.maintenance.telemetry.update'), [
                'enabled' => '0',
            ]);

        expect($settings->get('telemetry.enabled'))->toBeFalse();
    });

    it('triggers send telemetry queue via maintenance sendNow route', function () {
        Queue::fake();
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('settings.maintenance.telemetry.sendNow'));

        $response->assertRedirect()
            ->assertSessionHas('status', 'Telemetry report queued — sending in background.');
    });
});
