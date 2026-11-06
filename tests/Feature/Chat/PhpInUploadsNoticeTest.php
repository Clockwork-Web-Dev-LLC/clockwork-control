<?php

use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Support\Settings;
use Illuminate\Support\Facades\Http;
use Modules\Mattermost\MattermostNotifier;

/*
|--------------------------------------------------------------------------
| WebhookChatNotifier::phpInUploadsDetected
|--------------------------------------------------------------------------
|
| The message an operator gets when the Companion malware probe reports a
| PHP file under uploads that the site's previous scan did not have. When it
| fires is covered by the SecurityScanRecorder tests in
| tests/Feature/Security/SecurityScanAndChecksumTest.php; this file covers
| the per-event toggle and what the posted message contains.
*/

beforeEach(function () {
    config([
        'clockwork.mattermost.enabled' => true,
        'clockwork.mattermost.webhook_url' => 'https://chat.example.com/hooks/test',
    ]);
    Http::fake(['chat.example.com/*' => Http::response('ok', 200)]);
});

function phpUploadsNoticeScan(Site $site): SiteSecurityScan
{
    return SiteSecurityScan::factory()->create([
        'site_id' => $site->id,
        'scan_type' => SiteSecurityScan::TYPE_COMPANION_MALWARE,
        'status' => SiteSecurityScan::STATUS_WARNING,
    ]);
}

it('posts the new paths with the site and scan', function () {
    $site = Site::factory()->create(['domain' => 'notice.example.com']);
    $scan = phpUploadsNoticeScan($site);

    $sent = app(MattermostNotifier::class)->phpInUploadsDetected($site, $scan, ['wp-content/uploads/2026/10/dropped.php']);

    expect($sent)->toBeTrue();
    Http::assertSent(function ($request) use ($scan) {
        $attachment = $request['attachments'][0] ?? [];
        $fields = collect($attachment['fields'] ?? [])->pluck('value', 'title');

        return str_contains($request['text'], '1 new PHP file in uploads on notice.example.com')
            && str_contains($attachment['text'] ?? '', '`wp-content/uploads/2026/10/dropped.php`')
            && $fields['Site'] === 'notice.example.com'
            && $fields['Scan'] === '#'.$scan->id;
    });
});

it('lists at most ten paths and counts the rest', function () {
    $site = Site::factory()->create(['domain' => 'many.example.com']);
    $paths = array_map(fn ($i) => "wp-content/uploads/f{$i}.php", range(1, 12));

    app(MattermostNotifier::class)->phpInUploadsDetected($site, phpUploadsNoticeScan($site), $paths);

    Http::assertSent(function ($request) {
        $text = $request['attachments'][0]['text'] ?? '';

        return str_contains($request['text'], '12 new PHP files in uploads')
            && str_contains($text, 'f10.php')
            && ! str_contains($text, 'f11.php')
            && str_contains($text, '…and 2 more');
    });
});

it('defangs characters in a file name that could break formatting or trigger a mention', function () {
    $site = Site::factory()->create();

    app(MattermostNotifier::class)->phpInUploadsDetected($site, phpUploadsNoticeScan($site), ['wp-content/uploads/<!channel>`x`.php']);

    Http::assertSent(function ($request) {
        $text = $request['attachments'][0]['text'] ?? '';

        return ! str_contains($text, '<!channel>')
            && str_contains($text, "‹!channel›'x'.php");
    });
});

it('sends nothing when the event is switched off in notification settings', function () {
    app(Settings::class)->put(MattermostNotifier::SETTINGS_KEY, ['php_in_uploads_detected' => false]);
    $site = Site::factory()->create();

    $sent = app(MattermostNotifier::class)->phpInUploadsDetected($site, phpUploadsNoticeScan($site), ['wp-content/uploads/a.php']);

    expect($sent)->toBeFalse();
    Http::assertNothingSent();
});

it('sends nothing for an empty path list', function () {
    $site = Site::factory()->create();

    expect(app(MattermostNotifier::class)->phpInUploadsDetected($site, phpUploadsNoticeScan($site), []))->toBeFalse();
    Http::assertNothingSent();
});
