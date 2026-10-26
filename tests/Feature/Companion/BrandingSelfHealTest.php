<?php

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Companion\CompanionBrandingManager;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function brandingSite(string $domain): Site
{
    return Site::factory()->withCompanionInstalled()->create(['domain' => $domain, 'is_inactive' => false]);
}

/** Live branding as a site would report it, matching Control's current settings unless overridden. */
function liveBranding(array $overrides = []): array
{
    $want = app(CompanionBrandingManager::class)->payload();

    return ['ok' => true, 'branding' => array_merge(
        array_intersect_key($want, array_flip(CompanionBrandingManager::DRIFT_KEYS)),
        $overrides,
    )];
}

beforeEach(function () {
    app(CompanionBrandingManager::class)->save([
        'enabled' => true,
        'company_name' => 'Clockwork Web Dev',
        'support_email' => 'support@clockwork.test',
        'plugin_name' => 'Clockwork Companion',
        'menu_title' => 'Clockwork',
        'logo_url' => 'https://brand.test/logo.png',
        'primary_color' => '#2D2062',
        'accent_color' => '#7EFF83',
    ]);
});

describe('signed GET cache-busting', function () {
    it('adds a unique query param and no-cache header to GETs but not POSTs', function () {
        $site = brandingSite('cache.test');
        Http::fake(['*' => Http::response(['ok' => true, 'branding' => []])]);

        $client = new ClockworkCompanionClient($site);
        $client->branding();
        $client->branding();
        $client->pushBranding(['enabled' => true]);

        $gets = Http::recorded(fn (Request $r) => $r->method() === 'GET')->map(fn ($p) => $p[0]);
        expect($gets)->toHaveCount(2);
        $gets->each(fn (Request $r) => expect($r->url())->toContain('/wp-json/clockwork/v1/branding?_cw=')
            ->and($r->header('Cache-Control'))->toBe(['no-cache']));
        expect($gets[0]->url())->not->toBe($gets[1]->url());

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && ! str_contains($r->url(), '_cw='));
    });
});

describe('drift detection', function () {
    it('reports no drift when the live branding matches (ignoring case/whitespace)', function () {
        $site = brandingSite('match.test');
        Http::fake(['*' => Http::response(liveBranding(['primary_color' => '#2d2062 ', 'company_name' => 'clockwork web dev']))]);

        expect(app(CompanionBrandingManager::class)->drift($site))->toBe([]);
    });

    it('returns the drifted fields with their live values', function () {
        $site = brandingSite('drift.test');
        Http::fake(['*' => Http::response(liveBranding(['enabled' => false, 'primary_color' => '#6953C4', 'logo_url' => '']))]);

        expect(app(CompanionBrandingManager::class)->drift($site))
            ->toBe(['enabled' => false, 'logo_url' => '', 'primary_color' => '#6953C4']);
    });
});

describe('clockwork:push-companion-branding --verify', function () {
    it('leaves in-sync sites alone, heals drifted ones, and reports unreachable ones', function () {
        brandingSite('ok.test');
        brandingSite('drifted.test');
        brandingSite('stuck.test');
        brandingSite('down.test');

        Http::fake([
            'https://ok.test/*' => Http::response(liveBranding()),
            // drifted: stale until the re-push lands, then correct
            'https://drifted.test/wp-json/clockwork/v1/branding*' => Http::sequence()
                ->push(liveBranding(['enabled' => false, 'primary_color' => '#6953C4']))
                ->push(['ok' => true])                // POST /branding
                ->push(liveBranding()),               // re-read
            // stuck: accepts the push but keeps reporting old values
            'https://stuck.test/wp-json/clockwork/v1/branding*' => Http::sequence()
                ->push(liveBranding(['logo_url' => '']))
                ->push(['ok' => true])
                ->push(liveBranding(['logo_url' => ''])),
            'https://down.test/*' => Http::response(['code' => 'invalid_signature'], 401),
        ]);

        $this->artisan('clockwork:push-companion-branding', ['--verify' => true])
            ->expectsOutputToContain('drifted.test: re-pushed')
            ->expectsOutputToContain('stuck.test: still out of sync')
            ->expectsOutputToContain('down.test: unreachable')
            ->expectsOutputToContain('1 in sync, 1 healed, 1 failed, 1 unreachable')
            ->assertFailed();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'ok.test'));
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), 'down.test'));

        $log = ActionLog::where('action_type', 'companion_branding_verified')->firstOrFail();
        expect($log->ok)->toBeFalse()
            ->and($log->details['healed'])->toHaveKey('drifted.test')
            ->and($log->details['failed'])->toHaveKey('stuck.test')
            ->and($log->details['unreachable'])->toHaveKey('down.test');
    });

    it('succeeds quietly when every site is in sync', function () {
        brandingSite('a.test');
        brandingSite('b.test');
        Http::fake(['*' => Http::response(liveBranding())]);

        $this->artisan('clockwork:push-companion-branding', ['--verify' => true])
            ->expectsOutputToContain('2 in sync, 0 healed, 0 failed, 0 unreachable')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
        expect(ActionLog::where('action_type', 'companion_branding_verified')->exists())->toBeFalse();
    });
});
