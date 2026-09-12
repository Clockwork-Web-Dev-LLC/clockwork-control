<?php

use App\Models\PluginDirectoryStatus;
use App\Models\Site;
use App\Services\Security\PluginDirectoryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    PluginDirectoryClient::$requestDelayMs = 0;
});

afterEach(function () {
    PluginDirectoryClient::$requestDelayMs = PluginDirectoryClient::DEFAULT_REQUEST_DELAY_MS;
});

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
            'https://api.wordpress.org/plugins/info/1.2/*shared-plugin*' => Http::response([
                'slug' => 'shared-plugin',
                'name' => 'Shared Plugin',
            ], 200),
            'https://api.wordpress.org/plugins/info/1.2/*plugin-alpha*' => Http::response([
                'error' => 'closed',
                'description' => 'Closed on 2024-05-01 due to security vulnerability.',
                'closed_date' => '2024-05-01',
            ], 200),
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
            ->and($alpha->reason)->toContain('Closed on 2024-05-01')
            ->and($alpha->closed_date)->toBe('2024-05-01');
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
            'https://api.wordpress.org/plugins/info/1.2/*' => Http::response([
                'error' => 'Plugin not found',
            ], 200),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins')
            ->expectsOutputToContain('total=1  open=0  closed=0  not_found=1  errors=0')
            ->assertSuccessful();

        $status = PluginDirectoryStatus::where('slug', 'gravityforms')->first();
        expect($status)->not->toBeNull()
            ->and($status->status)->toBe('not_found')
            ->and($status->reason)->toBeNull();
    });

    it('does not clobber a previous closed status on network error', function () {
        PluginDirectoryStatus::factory()->closed('Previously verified closed')->create([
            'slug' => 'zombie-plugin',
        ]);

        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*' => Http::response('Gateway Timeout', 504),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins', ['--slug' => 'zombie-plugin'])
            ->expectsOutputToContain('total=1  open=0  closed=0  not_found=0  errors=1')
            ->assertSuccessful();

        $reloaded = PluginDirectoryStatus::where('slug', 'zombie-plugin')->first();
        expect($reloaded->status)->toBe('closed')
            ->and($reloaded->reason)->toBe('Previously verified closed');
    });

    it('supports checking a specific slug via --slug option', function () {
        Http::fake([
            'https://api.wordpress.org/plugins/info/1.2/*targeted-slug*' => Http::response([
                'error' => 'closed',
                'description' => 'Author requested closure',
            ], 200),
        ]);

        $this->artisan('clockwork:refresh-closed-plugins', ['--slug' => 'targeted-slug'])
            ->expectsOutputToContain('total=1  open=0  closed=1')
            ->assertSuccessful();

        expect(PluginDirectoryStatus::where('slug', 'targeted-slug')->first()?->status)->toBe('closed');
    });
});
