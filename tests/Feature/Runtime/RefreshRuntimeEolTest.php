<?php

use App\Services\Runtime\EndOfLifeClient;
use App\Support\Settings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function endOfLifeProductPayload(array $releases = []): array
{
    return [
        'schema_version' => '1.2.1',
        'result' => [
            'releases' => $releases,
        ],
    ];
}

function releaseCycle(string $name, array $overrides = []): array
{
    return array_merge([
        'name' => $name,
        'label' => $name,
        'releaseDate' => '2023-11-23',
        'isEoas' => false,
        'eoasFrom' => '2025-12-31',
        'isEol' => false,
        'eolFrom' => '2027-12-31',
        'isMaintained' => true,
    ], $overrides);
}

describe('clockwork:refresh-runtime-eol', function () {
    it('fetches PHP and WordPress lifecycle data and persists cycles to settings', function () {
        Http::fake([
            EndOfLifeClient::PHP_URL => Http::response(endOfLifeProductPayload([
                releaseCycle('8.3'),
                releaseCycle('8.1', ['isEol' => true, 'eolFrom' => '2025-12-31', 'isMaintained' => false]),
            ]), 200),
            EndOfLifeClient::WORDPRESS_URL => Http::response(endOfLifeProductPayload([
                releaseCycle('6.6', ['eolFrom' => '2027-01-01']),
            ]), 200),
        ]);

        $this->artisan('clockwork:refresh-runtime-eol')
            ->expectsOutputToContain('PHP cycles=2  WordPress cycles=1')
            ->assertSuccessful();

        $settings = app(Settings::class);
        $php = $settings->get('runtime_eol.php_cycles');
        $wp = $settings->get('runtime_eol.wordpress_cycles');
        $fetchedAt = $settings->get('runtime_eol.fetched_at');

        expect($php)->toBeArray()
            ->and(array_keys($php))->toEqualCanonicalizing(['8.3', '8.1'])
            ->and($wp)->toBeArray()
            ->and(array_keys($wp))->toEqualCanonicalizing(['6.6'])
            ->and($fetchedAt)->not->toBeNull();
    });

    it('stores the successful product but does not bump fetched_at when the other product fails', function () {
        $settings = app(Settings::class);
        $settings->put('runtime_eol.php_cycles', ['8.2' => ['name' => '8.2']]);
        $settings->put('runtime_eol.wordpress_cycles', ['6.5' => ['name' => '6.5']]);
        $settings->put('runtime_eol.fetched_at', '2026-01-01T00:00:00Z');

        Http::fake([
            EndOfLifeClient::PHP_URL => Http::response(endOfLifeProductPayload([
                releaseCycle('8.4'),
            ]), 200),
            EndOfLifeClient::WORDPRESS_URL => Http::response('Gateway Timeout', 504),
        ]);

        $this->artisan('clockwork:refresh-runtime-eol')
            ->assertSuccessful();

        expect(array_keys($settings->get('runtime_eol.php_cycles')))->toEqualCanonicalizing(['8.4'])
            ->and($settings->get('runtime_eol.wordpress_cycles'))->toHaveKey('6.5')
            ->and($settings->get('runtime_eol.fetched_at'))->toBe('2026-01-01T00:00:00Z');
    });

    it('preserves existing settings if HTTP requests fail', function () {
        $settings = app(Settings::class);
        $settings->put('runtime_eol.php_cycles', ['8.2' => ['name' => '8.2']]);
        $settings->put('runtime_eol.fetched_at', '2026-01-01T00:00:00Z');

        Http::fake([
            EndOfLifeClient::PHP_URL => Http::response('Gateway Timeout', 504),
            EndOfLifeClient::WORDPRESS_URL => Http::response('Gateway Timeout', 504),
        ]);

        $this->artisan('clockwork:refresh-runtime-eol')
            ->expectsOutputToContain('Refresh failed')
            ->assertFailed();

        expect($settings->get('runtime_eol.php_cycles'))->toHaveKey('8.2')
            ->and($settings->get('runtime_eol.fetched_at'))->toBe('2026-01-01T00:00:00Z');
    });
});
