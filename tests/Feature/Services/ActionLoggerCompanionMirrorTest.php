<?php

use App\Models\ActionLog;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Support\Facades\Http;

/*
| ActionLogger mirrors every row to the site's Companion activity log, which
| the client sees in wp-admin. Whatever wrote the row, licence keys, tokens
| and signed download URLs are redacted on the way out. Values are invented;
| hosts are reserved example domains.
*/

it('redacts secrets in the summary, details and error it mirrors to Companion', function () {
    $site = Site::factory()->create([
        'domain' => 'mirror.example.com',
        'companion_installed' => true,
        'companion_secret' => 'test-secret-'.uniqid(),
    ]);
    Http::fake([
        "https://{$site->domain}/wp-json/clockwork/v1/action-log/append*" => Http::response(['ok' => true], 200, ['Content-Type' => 'application/json']),
    ]);

    app(ActionLogger::class)->record(
        actionType: ActionLog::TYPE_PLUGIN_UPDATE,
        summary: 'Plugin update failed: https://vendor.example.com/dl.zip?license_key=FAKE-KEY-2222',
        site: $site,
        target: 'example-pro/example-pro.php',
        details: ['messages' => ['Downloading update from https://vendor.example.com/dl.zip?token=FAKE-TOKEN-3333&#8230;'], 'attempt' => 2],
        ok: false,
        error: 'Download failed: https://vendor.example.com/dl.zip?token=FAKE-TOKEN-3333',
        actor: 'scheduled',
    );

    Http::assertSent(fn ($request) => str_contains($request->url(), 'action-log/append')
        && ! str_contains($request->body(), 'FAKE-KEY-2222')
        && ! str_contains($request->body(), 'FAKE-TOKEN-3333')
        && $request['details']['attempt'] === 2
        && $request['target'] === 'example-pro/example-pro.php');
});

it('keeps local-only rows (observed or held-back updates) out of the Companion mirror', function (string $type) {
    $site = Site::factory()->create([
        'domain' => 'local-only.example.com',
        'companion_installed' => true,
        'companion_secret' => 'test-secret-'.uniqid(),
    ]);
    Http::fake();

    $row = app(ActionLogger::class)->record(
        actionType: $type,
        summary: 'Example row',
        site: $site,
        target: 'example-plugin/example-plugin.php',
        actor: 'auto',
    );

    Http::assertNothingSent();
    expect($row)->not->toBeNull()
        ->and($row->companion_pushed_at)->toBeNull()
        ->and(ActionLog::query()->where('action_type', $type)->count())->toBe(1);
})->with(ActionLog::LOCAL_ONLY_TYPES);
