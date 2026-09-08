<?php

use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Facades\Http;
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

it('captures and caches site screenshot via service and artisan command', function () {
    $site = Site::factory()->spinupwp()->create([
        'domain' => 'capture-test.com',
    ]);

    $fakeImageBytes = str_repeat('GIF89a', 30); // Valid mock image bytes (> 100 bytes)

    Http::fake([
        'https://s0.wp.com/mshots/v1/*' => Http::response($fakeImageBytes, 200, ['Content-Type' => 'image/jpeg']),
    ]);

    $this->artisan('clockwork:capture-site-screenshots', ['--site' => $site->domain, '--force' => true])
        ->assertSuccessful();

    $site->refresh();

    expect($site->screenshot_path)->toBe("screenshots/{$site->id}.jpg")
        ->and($site->screenshot_captured_at)->not->toBeNull();

    Storage::disk('public')->assertExists("screenshots/{$site->id}.jpg");
});
