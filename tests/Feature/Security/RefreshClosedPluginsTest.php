<?php

use App\Models\PluginDirectoryStatus;
use App\Models\Site;
use App\Services\Security\PluginDirectoryClient;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    PluginDirectoryClient::$requestDelayMs = 0;
});

afterEach(function () {
    PluginDirectoryClient::$requestDelayMs = PluginDirectoryClient::DEFAULT_REQUEST_DELAY_MS;
});

/**
 * WordPress.org serves closed plugins with HTTP 404 and a JSON error body.
 * Mirrors the live API shape for e.g. display-widgets.
 */
function fakeClosedPluginResponse(string $slug = 'plugin-alpha', ?string $description = 'This plugin has been closed as of January 30, 2021 and is not available for download. Reason: Security Issue.'): PromiseInterface
{
    $body = [
        'error' => 'closed',
        'name' => 'Some Plugin',
        'slug' => $slug,
        'closed' => true,
        'closed_date' => '2021-01-30',
        'reason' => 'security-issue',
        'reason_text' => 'Security Issue',
    ];

    if ($description !== null) {
        $body['description'] = $description;
    }

    return Http::response($body, 404);
}

/**
 * WordPress.org serves genuinely unknown slugs (premium/custom plugins) with
 * HTTP 404 and this exact body.
 */
function fakeNotFoundPluginResponse(): PromiseInterface
{
    return Http::response(['error' => 'Plugin not found.'], 404);
}

function fakeOpenPluginResponse(string $slug): PromiseInterface
{
    return Http::response(['slug' => $slug, 'name' => ucfirst($slug)], 200);
}

describe('clockwork:refresh-closed-plugins — slug discovery & sync', function () {
    it('succeeds with zeroed summary when no site has companion_snapshot', function () {
        Site::factory()->create(['companion_snapshot' => null]);

        Http::fake();

        $this->artisan('clockwork:refresh-closed-plugins')
            ->expectsOutputToContain('Done. total=0  open=0  closed=0  not_found=0  errors=0')
            ->assertSuccessful();

        Http::assertNothingSent();
        expect(PluginDirectoryStatus::count())->toBe(0);
    });

    it('ignores inactive sites during slug discovery', function () {
        Site::factory()->inactive()->create([
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        ['slug' => 'old-plugin/old.php', 'active' => true],
                    ],
                ],
            ],
        ]);

        Http::fake();

        $this->artisan('clockwork:refresh-closed-plugins')
            ->expectsOutputToContain('total=0')
            ->assertSuccessful();

        Http::assertNothingSent();
    });

    it('deduplicates slugs across the fleet and queries each unique slug once', function () {
        Site::factory()->create([
            'is_inactive' => false,
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        ['slug' => 'shared-plugin/shared.php', 'active' => true],
                        ['slug' => 'plugin-alpha/alpha.php', 'active' => true],
                    ],
                ],
            ],
        ]);

        Site::factory()->create([
            'is_inactive' => false,
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        ['slug' => 'shared-plugin/shared.php', 'active' => true],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*shared-plugin*' => fakeOpenPluginResponse('shared-plugin'),
            'https://api.wordpress.org/plugins/info/1.2/*plugin-alpha*' => fakeClosedPluginResponse('plugin-alpha'),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins')
            ->expectsOutputToContain('total=2  open=1  closed=1  not_found=0  errors=0')
            ->assertSuccessful();

        Http::assertSentCount(2);

        $shared = PluginDirectoryStatus::where('slug', 'shared-plugin')->first();
        expect($shared)->not->toBeNull()
            ->and($shared->status)->toBe('open');

        $alpha = PluginDirectoryStatus::where('slug', 'plugin-alpha')->first();
        expect($alpha)->not->toBeNull()
            ->and($alpha->status)->toBe('closed')
            ->and($alpha->reason)->toContain('Reason: Security Issue')
            ->and($alpha->closed_date)->toBe('2021-01-30');
    });

    it('records a closed plugin served with HTTP 404 (live API behavior) as closed with reason and closed_date', function () {
        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*' => fakeClosedPluginResponse('display-widgets'),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins', ['--slug' => 'display-widgets'])
            ->expectsOutputToContain('total=1  open=0  closed=1  not_found=0  errors=0')
            ->assertSuccessful();

        $status = PluginDirectoryStatus::where('slug', 'display-widgets')->first();
        expect($status)->not->toBeNull()
            ->and($status->status)->toBe('closed')
            ->and($status->reason)->toContain('This plugin has been closed')
            ->and($status->closed_date)->toBe('2021-01-30');
    });

    it('falls back to reason_text when a closed body has no description', function () {
        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*' => fakeClosedPluginResponse('display-widgets', description: null),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins', ['--slug' => 'display-widgets'])
            ->assertSuccessful();

        expect(PluginDirectoryStatus::where('slug', 'display-widgets')->first())
            ->status->toBe('closed')
            ->reason->toBe('Security Issue')
            ->closed_date->toBe('2021-01-30');
    });

    it('does not retry a 404 — it is a definitive answer, not a transient failure', function () {
        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*' => fakeClosedPluginResponse(),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins', ['--slug' => 'plugin-alpha'])
            ->assertSuccessful();

        Http::assertSentCount(1);
    });

    it('classifies unknown / premium plugins as not_found without error', function () {
        Site::factory()->create([
            'is_inactive' => false,
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        ['slug' => 'gravityforms/gravityforms.php', 'active' => true],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*' => fakeNotFoundPluginResponse(),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins')
            ->expectsOutputToContain('total=1  open=0  closed=0  not_found=1  errors=0')
            ->assertSuccessful();

        $status = PluginDirectoryStatus::where('slug', 'gravityforms')->first();
        expect($status)->not->toBeNull()
            ->and($status->status)->toBe('not_found')
            ->and($status->reason)->toBeNull();
    });

    it('supports checking a specific slug via --slug option', function () {
        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*targeted-slug*' => fakeClosedPluginResponse('targeted-slug'),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins', ['--slug' => 'targeted-slug'])
            ->expectsOutputToContain('total=1  open=0  closed=1')
            ->assertSuccessful();

        expect(PluginDirectoryStatus::where('slug', 'targeted-slug')->first()?->status)->toBe('closed');
    });
});

describe('clockwork:refresh-closed-plugins — transient errors', function () {
    it('does not clobber a previous closed status on network error', function () {
        PluginDirectoryStatus::factory()->closed('Previously verified closed')->create([
            'slug' => 'zombie-plugin',
        ]);

        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*' => Http::response('Gateway Timeout', 504),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins', ['--slug' => 'zombie-plugin'])
            ->expectsOutputToContain('total=1  open=0  closed=0  not_found=0  errors=1')
            ->assertFailed();

        $reloaded = PluginDirectoryStatus::where('slug', 'zombie-plugin')->first();
        expect($reloaded->status)->toBe('closed')
            ->and($reloaded->reason)->toBe('Previously verified closed');
    });

    it('does not clobber previous open or not_found statuses on network error', function () {
        PluginDirectoryStatus::factory()->create(['slug' => 'healthy-plugin', 'status' => 'open']);
        PluginDirectoryStatus::factory()->create(['slug' => 'premium-plugin', 'status' => 'not_found']);

        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*' => Http::response('Service Unavailable', 503),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins', ['--slug' => 'healthy-plugin'])->assertFailed();
        $this->artisan('clockwork:refresh-closed-plugins', ['--slug' => 'premium-plugin'])->assertFailed();

        expect(PluginDirectoryStatus::where('slug', 'healthy-plugin')->first()->status)->toBe('open')
            ->and(PluginDirectoryStatus::where('slug', 'premium-plugin')->first()->status)->toBe('not_found');
    });

    it('returns FAILURE with a warning when every slug check errors (total outage)', function () {
        Site::factory()->create([
            'is_inactive' => false,
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        ['slug' => 'plugin-one/one.php', 'active' => true],
                        ['slug' => 'plugin-two/two.php', 'active' => true],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*' => Http::response('Bad Gateway', 502),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins')
            ->expectsOutputToContain('total=2  open=0  closed=0  not_found=0  errors=2')
            ->expectsOutputToContain('2 slug(s) could not be checked')
            ->assertFailed();
    });

    it('still succeeds (with a warning) when only some slugs error', function () {
        Site::factory()->create([
            'is_inactive' => false,
            'companion_snapshot' => [
                'plugins' => [
                    'plugins' => [
                        ['slug' => 'fine-plugin/fine.php', 'active' => true],
                        ['slug' => 'flaky-plugin/flaky.php', 'active' => true],
                    ],
                ],
            ],
        ]);

        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*flaky-plugin*' => Http::response('Bad Gateway', 502),
            'https://api.wordpress.org/plugins/info/1.2/*' => fakeOpenPluginResponse('fine-plugin'),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins')
            ->expectsOutputToContain('total=2  open=1  closed=0  not_found=0  errors=1')
            ->expectsOutputToContain('1 slug(s) could not be checked')
            ->assertSuccessful();
    });
});
