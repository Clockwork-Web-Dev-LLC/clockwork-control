<?php

namespace Tests\Feature\Companion;

use App\Models\Site;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

describe('ClockworkCompanionClient Extended Methods', function () {
    it('fetches database bloat summary via GET /database/summary', function () {
        $site = Site::factory()->withCompanionInstalled()->create();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/database/summary" => Http::response([
                'ok' => true,
                'revisions' => 15,
                'auto_drafts' => 3,
                'trashed_posts' => 2,
                'spam_comments' => 8,
                'overhead_bytes' => 40960,
                'total_cleanable_items' => 28,
            ], 200),
        ]);

        $client = new ClockworkCompanionClient($site);
        $summary = $client->getDatabaseSummary();

        expect($summary)->toBeArray()
            ->and($summary['ok'])->toBeTrue()
            ->and($summary['revisions'])->toBe(15)
            ->and($summary['overhead_bytes'])->toBe(40960);

        Http::assertSent(function (Request $request) use ($site) {
            return $request->url() === "https://{$site->domain}/wp-json/clockwork/v1/database/summary"
                && $request->method() === 'GET'
                && $request->hasHeader('X-Clockwork-Signature')
                && $request->hasHeader('X-Clockwork-Timestamp');
        });
    });

    it('runs database optimization via POST /database/optimize', function () {
        $site = Site::factory()->withCompanionInstalled()->create();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/database/optimize" => Http::response([
                'ok' => true,
                'cleaned' => [
                    'revisions' => 15,
                    'optimized_tables' => 4,
                    'reclaimed_bytes' => 40960,
                ],
                'elapsed_ms' => 125,
            ], 200),
        ]);

        $client = new ClockworkCompanionClient($site);
        $result = $client->optimizeDatabase([
            'revisions' => true,
            'keep_revisions' => 5,
            'tables' => true,
        ]);

        expect($result)->toBeArray()
            ->and($result['ok'])->toBeTrue()
            ->and($result['cleaned']['revisions'])->toBe(15)
            ->and($result['cleaned']['optimized_tables'])->toBe(4);

        Http::assertSent(function (Request $request) use ($site) {
            return $request->url() === "https://{$site->domain}/wp-json/clockwork/v1/database/optimize"
                && $request->method() === 'POST'
                && $request['revisions'] === true
                && $request['keep_revisions'] === 5;
        });
    });

    it('toggles plugin activation via POST /plugins/toggle', function () {
        $site = Site::factory()->withCompanionInstalled()->create();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins/toggle" => Http::response([
                'ok' => true,
                'slug' => 'akismet/akismet.php',
                'action' => 'activate',
                'active' => true,
                'network_wide' => false,
            ], 200),
        ]);

        $client = new ClockworkCompanionClient($site);
        $result = $client->togglePlugin('akismet/akismet.php', 'activate');

        expect($result)->toBeArray()
            ->and($result['ok'])->toBeTrue()
            ->and($result['action'])->toBe('activate')
            ->and($result['active'])->toBeTrue();

        Http::assertSent(function (Request $request) use ($site) {
            return $request->url() === "https://{$site->domain}/wp-json/clockwork/v1/plugins/toggle"
                && $request['slug'] === 'akismet/akismet.php'
                && $request['action'] === 'activate';
        });
    });

    it('deletes plugin via POST /plugins/delete', function () {
        $site = Site::factory()->withCompanionInstalled()->create();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins/delete" => Http::response([
                'ok' => true,
                'slug' => 'hello-dolly/hello.php',
                'deleted' => true,
            ], 200),
        ]);

        $client = new ClockworkCompanionClient($site);
        $result = $client->deletePlugin('hello-dolly/hello.php');

        expect($result)->toBeArray()
            ->and($result['ok'])->toBeTrue()
            ->and($result['deleted'])->toBeTrue();
    });

    it('installs plugin from WP.org via POST /plugins/install', function () {
        $site = Site::factory()->withCompanionInstalled()->create();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins/install" => Http::response([
                'ok' => true,
                'slug' => 'classic-editor',
                'plugin_file' => 'classic-editor/classic-editor.php',
                'version' => '1.6.5',
                'activated' => true,
            ], 200),
        ]);

        $client = new ClockworkCompanionClient($site);
        $result = $client->installPlugin('classic-editor', activate: true);

        expect($result)->toBeArray()
            ->and($result['ok'])->toBeTrue()
            ->and($result['plugin_file'])->toBe('classic-editor/classic-editor.php')
            ->and($result['activated'])->toBeTrue();
    });

    it('fetches remote debug log entries via GET /debug-log', function () {
        $site = Site::factory()->withCompanionInstalled()->create();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/debug-log*" => Http::response([
                'ok' => true,
                'exists' => true,
                'enabled' => true,
                'file_size' => 2048,
                'line_count' => 2,
                'lines' => [
                    '[13-Sep-2026 12:00:01 UTC] PHP Notice: Test notice',
                    '[13-Sep-2026 12:00:02 UTC] PHP Warning: Test warning',
                ],
                'path_masked' => 'wp-content/debug.log',
            ], 200),
        ]);

        $client = new ClockworkCompanionClient($site);
        $result = $client->getDebugLog(50);

        expect($result)->toBeArray()
            ->and($result['ok'])->toBeTrue()
            ->and($result['line_count'])->toBe(2)
            ->and($result['lines'])->toHaveCount(2);

        Http::assertSent(function (Request $request) use ($site) {
            return str_starts_with($request->url(), "https://{$site->domain}/wp-json/clockwork/v1/debug-log")
                && $request->method() === 'GET';
        });
    });

    it('clears remote debug log via DELETE /debug-log', function () {
        $site = Site::factory()->withCompanionInstalled()->create();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/debug-log" => Http::response([
                'ok' => true,
                'cleared' => true,
            ], 200),
        ]);

        $client = new ClockworkCompanionClient($site);
        $result = $client->clearDebugLog();

        expect($result)->toBeArray()
            ->and($result['ok'])->toBeTrue()
            ->and($result['cleared'])->toBeTrue();

        Http::assertSent(function (Request $request) use ($site) {
            return $request->url() === "https://{$site->domain}/wp-json/clockwork/v1/debug-log"
                && $request->method() === 'DELETE'
                && $request->hasHeader('X-Clockwork-Signature')
                && $request->hasHeader('X-Clockwork-Timestamp');
        });
    });

    it('fetches environment telemetry via GET /environment', function () {
        $site = Site::factory()->withCompanionInstalled()->create();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/environment" => Http::response([
                'ok' => true,
                'php' => [
                    'version' => '8.3.4',
                    'memory_limit' => '512M',
                ],
                'database' => [
                    'server_version' => '8.0.36',
                    'size_bytes' => 10485760,
                ],
                'server' => [
                    'web_server' => 'nginx',
                ],
            ], 200),
        ]);

        $client = new ClockworkCompanionClient($site);
        $env = $client->getEnvironment();

        expect($env)->toBeArray()
            ->and($env['ok'])->toBeTrue()
            ->and($env['php']['version'])->toBe('8.3.4')
            ->and($env['database']['server_version'])->toBe('8.0.36')
            ->and($env['server']['web_server'])->toBe('nginx');
    });

    it('refuses to deactivate or delete protected plugins before calling the site', function () {
        $site = Site::factory()->withCompanionInstalled()->create();

        Http::fake();

        $client = new ClockworkCompanionClient($site);

        expect(fn () => $client->togglePlugin('woocommerce/woocommerce.php', 'deactivate'))
            ->toThrow(\RuntimeException::class, "Refusing to deactivate protected plugin 'woocommerce/woocommerce.php'.");

        expect(fn () => $client->deletePlugin('clockwork-renegade/clockwork-renegade.php'))
            ->toThrow(\RuntimeException::class, "Refusing to delete protected plugin 'clockwork-renegade/clockwork-renegade.php'.");

        Http::assertNothingSent();
    });

    it('still allows activating a protected plugin', function () {
        $site = Site::factory()->withCompanionInstalled()->create();

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork/v1/plugins/toggle" => Http::response([
                'ok' => true,
                'slug' => 'woocommerce/woocommerce.php',
                'action' => 'activate',
                'active' => true,
            ], 200),
        ]);

        $client = new ClockworkCompanionClient($site);
        $result = $client->togglePlugin('woocommerce/woocommerce.php', 'activate');

        expect($result['ok'])->toBeTrue()
            ->and($result['action'])->toBe('activate');

        Http::assertSent(fn (Request $request) => $request['action'] === 'activate');
    });

    it('routes requests to clockwork-renegade/v1 when site variant is renegade', function () {
        $site = Site::factory()->withCompanionInstalled()->create([
            'companion_variant' => 'renegade',
        ]);

        Http::fake([
            "https://{$site->domain}/wp-json/clockwork-renegade/v1/database/summary" => Http::response([
                'ok' => true,
            ], 200),
        ]);

        $client = new ClockworkCompanionClient($site);
        $summary = $client->getDatabaseSummary();

        expect($summary['ok'])->toBeTrue();

        Http::assertSent(function (Request $request) use ($site) {
            return $request->url() === "https://{$site->domain}/wp-json/clockwork-renegade/v1/database/summary";
        });
    });
});
