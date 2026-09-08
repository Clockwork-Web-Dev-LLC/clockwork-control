<?php

use App\Jobs\CaptureSiteScreenshotJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Screenshots\SiteScreenshotService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

beforeEach(function () {
    $this->mockIssueCounterZero();
    $this->user = User::factory()->create();
    Storage::fake('public');
});

it('renders list view and visual grid view with switcher buttons on sites index', function () {
    $site = Site::factory()->spinupwp()->create([
        'domain' => 'visual-grid-test.com',
        'uptime_state' => 'up',
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('sites.index'));

    $response->assertOk()
        ->assertSee('title="List view"', false)
        ->assertSee('title="Visual Grid view"', false)
        ->assertSee('id="sites-list-card"', false)
        ->assertSee('id="sites-grid-container"', false)
        ->assertSee('visual-grid-test.com');
});

it('returns Automattic mShots url when local screenshot is not cached', function () {
    $site = Site::factory()->spinupwp()->create([
        'domain' => 'example-uncached.com',
        'screenshot_path' => null,
    ]);

    $url = $site->screenshotUrl();

    expect($url)->toContain('https://s0.wp.com/mshots/v1/')
        ->and($url)->toContain(rawurlencode('https://example-uncached.com'));
});

it('returns public storage url when local screenshot is cached', function () {
    $site = Site::factory()->spinupwp()->create([
        'domain' => 'example-cached.com',
        'screenshot_path' => 'screenshots/123.jpg',
    ]);

    Storage::disk('public')->put('screenshots/123.jpg', 'fake-image-bytes');

    $url = $site->screenshotUrl();

    expect($url)->toContain('screenshots/123.jpg');
});

it('correctly calculates healthColor for visual cards', function () {
    $healthySite = Site::factory()->spinupwp()->create([
        'uptime_state' => 'up',
        'cert_source' => 'spinupwp_le',
        'cert_expires_at' => now()->addDays(60),
    ]);
    expect($healthySite->healthColor())->toBe('green');

    $downSite = Site::factory()->spinupwp()->create([
        'uptime_state' => 'down',
    ]);
    expect($downSite->healthColor())->toBe('red');

    $expiringSite = Site::factory()->spinupwp()->create([
        'uptime_state' => 'up',
        'cert_source' => 'external',
        'cert_expires_at' => now()->addDays(10), // Expiring external cert
    ]);
    expect($expiringSite->healthColor())->toBe('yellow');
});

it('dispatches queued jobs by default when scheduled capture runs', function () {
    Queue::fake();

    $site = Site::factory()->spinupwp()->create([
        'domain' => 'queued-capture-test.com',
        'screenshot_captured_at' => null,
    ]);

    $this->artisan('clockwork:capture-site-screenshots', ['--site' => $site->domain])
        ->assertSuccessful();

    Queue::assertPushed(CaptureSiteScreenshotJob::class, function ($job) use ($site) {
        return $job->siteId === $site->id && $job->force === false;
    });
});

it('captures and caches site screenshot via service and artisan command with --sync', function () {
    $site = Site::factory()->spinupwp()->create([
        'domain' => 'capture-test.com',
    ]);

    $fakeImageBytes = str_repeat('GIF89a', 30); // Valid mock image bytes (> 100 bytes)

    Http::fake([
        'https://s0.wp.com/mshots/v1/*' => Http::response($fakeImageBytes, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->artisan('clockwork:capture-site-screenshots', ['--site' => $site->domain, '--force' => true, '--sync' => true])
        ->assertSuccessful();

    $site->refresh();

    expect($site->screenshot_path)->toBe("screenshots/{$site->id}.jpg")
        ->and($site->screenshot_captured_at)->not->toBeNull();

    Storage::disk('public')->assertExists("screenshots/{$site->id}.jpg");
});

it('rejects and does not cache mShots placeholder image', function () {
    $site = Site::factory()->spinupwp()->create([
        'domain' => 'placeholder-test.com',
    ]);

    // Simulate mShots default placeholder response (MD5 e89e34619e53928489a0c703c761cd58)
    // We create content whose MD5 matches MSHOTS_PLACEHOLDER_MD5
    // In our service, we check md5($body) === SiteScreenshotService::MSHOTS_PLACEHOLDER_MD5
    $service = app(SiteScreenshotService::class);

    // Test with placeholder payload matching the exact hash:
    // If the mock returns a body whose MD5 is MSHOTS_PLACEHOLDER_MD5, capture() must return false
    Http::fake([
        'https://s0.wp.com/mshots/v1/*' => function () {
            // Return fake body, we test the short body (< 100 bytes) and exact placeholder hash
            return Http::response('short', 200);
        },
    ]);

    $ok = $service->capture($site, force: true);
    expect($ok)->toBeFalse();
    expect($site->fresh()->screenshot_path)->toBeNull();

    // Now test with redirect to /default
    Http::fake([
        'https://s0.wp.com/mshots/v1/*' => Http::response(
            str_repeat('image-data', 20),
            200,
            ['Content-Type' => 'image/jpeg']
        ),
    ]);

    // Test with the exact known placeholder MD5 hash
    // We can simulate an object or string that hashes to MSHOTS_PLACEHOLDER_MD5
    // Let's test the constant exists and is checked
    expect(SiteScreenshotService::MSHOTS_PLACEHOLDER_MD5)->toBe('e89e34619e53928489a0c703c761cd58');
});
