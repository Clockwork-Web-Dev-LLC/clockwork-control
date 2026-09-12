<?php

use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use App\Services\Runtime\RuntimeEolEvaluator;
use App\Support\Settings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function samplePhpCycles(): array
{
    return [
        '8.3' => [
            'name' => '8.3',
            'label' => '8.3',
            'release_date' => '2023-11-23',
            'is_eoas' => false,
            'eoas_from' => '2025-12-31',
            'is_eol' => false,
            'eol_from' => '2027-12-31',
            'is_maintained' => true,
        ],
        '8.1' => [
            'name' => '8.1',
            'label' => '8.1',
            'release_date' => '2021-11-25',
            'is_eoas' => true,
            'eoas_from' => '2023-11-25',
            'is_eol' => false,
            'eol_from' => '2025-12-31',
            'is_maintained' => false,
        ],
        '7.4' => [
            'name' => '7.4',
            'label' => '7.4',
            'release_date' => '2019-11-28',
            'is_eoas' => true,
            'eoas_from' => '2021-11-28',
            'is_eol' => true,
            'eol_from' => '2022-11-28',
            'is_maintained' => false,
        ],
    ];
}

describe('RuntimeEolEvaluator', function () {
    it('normalizes major.minor version strings correctly', function () {
        expect(RuntimeEolEvaluator::normalizeCycle('8.1.2'))->toBe('8.1')
            ->and(RuntimeEolEvaluator::normalizeCycle('8.2.14-1+ubuntu22.04.1'))->toBe('8.2')
            ->and(RuntimeEolEvaluator::normalizeCycle('6.4.3'))->toBe('6.4')
            ->and(RuntimeEolEvaluator::normalizeCycle('invalid'))->toBeNull()
            ->and(RuntimeEolEvaluator::normalizeCycle(null))->toBeNull();
    });

    it('classifies PHP 8.1 as security-only in 2024 and EOL in 2026', function () {
        $evaluator = new RuntimeEolEvaluator;
        $cycles = samplePhpCycles();

        // In June 2024: 8.1 is past active support (2023-11-25) but before EOL (2025-12-31)
        $in2024 = CarbonImmutable::parse('2024-06-01');
        $eval2024 = $evaluator->evaluate($cycles, '8.1.20', $in2024);

        expect($eval2024['status'])->toBe(RuntimeEolEvaluator::STATUS_SECURITY_ONLY)
            ->and($eval2024['cycle'])->toBe('8.1')
            ->and($eval2024['detail'])->toContain('Security support ends');

        // In January 2026: 8.1 is past EOL (2025-12-31)
        $in2026 = CarbonImmutable::parse('2026-01-01');
        $eval2026 = $evaluator->evaluate($cycles, '8.1.20', $in2026);

        expect($eval2026['status'])->toBe(RuntimeEolEvaluator::STATUS_EOL)
            ->and($eval2026['cycle'])->toBe('8.1')
            ->and($eval2026['detail'])->toContain('Security support ended');
    });

    it('classifies active supported PHP versions', function () {
        $evaluator = new RuntimeEolEvaluator;
        $cycles = samplePhpCycles();

        $in2024 = CarbonImmutable::parse('2024-06-01');
        $eval = $evaluator->evaluate($cycles, '8.3.1', $in2024);

        expect($eval['status'])->toBe(RuntimeEolEvaluator::STATUS_ACTIVE_SUPPORT)
            ->and($eval['cycle'])->toBe('8.3');
    });
});

describe('Capacity dashboard & site widget EOL integration', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
    });

    it('renders the Capacity page without 500 or HTTP calls when Settings are empty', function () {
        Http::preventStrayRequests();

        $response = $this->actingAs($this->user)->get(route('capacity.index'));

        $response->assertOk();
        $response->assertSee('Runtime EOL');
        $response->assertSee('EOL data is stale or unavailable');
        Http::assertNothingSent();
    });

    it('renders fleet PHP EOL breakdown on Capacity page with populated settings', function () {
        Http::preventStrayRequests();

        $settings = app(Settings::class);
        $settings->put('runtime_eol.php_cycles', samplePhpCycles());
        $settings->put('runtime_eol.fetched_at', now()->toIso8601String());

        $server = Server::factory()->create(['name' => 'app-server-01']);

        // Site 1: PHP 7.4 (EOL)
        $siteEol = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'eol-site.example.com',
            'is_inactive' => false,
            'companion_snapshot' => [
                'environment' => ['php_version' => '7.4.33'],
            ],
        ]);

        // Site 2: PHP 8.3 (Supported)
        $siteCurrent = Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'modern-site.example.com',
            'is_inactive' => false,
            'companion_snapshot' => [
                'environment' => ['php_version' => '8.3.2'],
            ],
        ]);

        // Site 3: Missing snapshot (omitted from table)
        Site::factory()->create([
            'server_id' => $server->id,
            'domain' => 'unsnapped.example.com',
            'is_inactive' => false,
            'companion_snapshot' => null,
        ]);

        $response = $this->actingAs($this->user)->get(route('capacity.index'));

        $response->assertOk();
        $response->assertSee('Runtime EOL');
        $response->assertSee('eol-site.example.com');
        $response->assertSee('7.4.33');
        $response->assertSee('modern-site.example.com');
        $response->assertSee('8.3.2');
        $response->assertDontSee('unsnapped.example.com');
        $response->assertDontSee('EOL data is stale or unavailable');

        Http::assertNothingSent();
    });

    it('renders quiet EOL badge in site widget', function () {
        $settings = app(Settings::class);
        $settings->put('runtime_eol.php_cycles', samplePhpCycles());

        $site = Site::factory()->create([
            'domain' => 'widget-eol.example.com',
            'companion_snapshot' => [
                'environment' => ['php_version' => '7.4.33'],
            ],
        ]);

        $response = $this->actingAs($this->user)->get(route('sites.show', $site));

        $response->assertOk();
        $response->assertSee('7.4.33');
        $response->assertSee('EOL');
    });
});
