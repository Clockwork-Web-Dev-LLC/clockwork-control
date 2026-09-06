<?php

use App\Jobs\PushCompanionBrandingJob;
use App\Models\Site;
use App\Models\User;
use App\Services\Companion\CompanionBrandingManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('CompanionSettingsController', function () {
    beforeEach(function () {
        $this->mockIssueCounterZero();
    });

    it('requires authentication for all companion settings routes', function () {
        $this->get(route('settings.companion.index'))->assertRedirect(route('login'));
        $this->get(route('settings.companion.preview'))->assertRedirect(route('login'));
        $this->patch(route('settings.companion.update'), [])->assertRedirect(route('login'));
        $this->post(route('settings.companion.logo'), [])->assertRedirect(route('login'));
        $this->post(route('settings.companion.sync'))->assertRedirect(route('login'));
        $this->post(route('settings.companion.reset'))->assertRedirect(route('login'));
    });

    describe('preview', function () {
        it('renders the standalone companion live preview screen with active branding', function () {
            $user = User::factory()->create();

            $manager = app(CompanionBrandingManager::class);
            $manager->save([
                'enabled' => true,
                'company_name' => 'Starlight Digital',
                'menu_title' => 'Starlight Security',
            ]);

            $response = $this->actingAs($user)->get(route('settings.companion.preview'));

            $response->assertOk()
                ->assertSee('Live Companion Preview')
                ->assertSee('Starlight Security')
                ->assertSee('Starlight Digital')
                ->assertSee('Site Care &amp; Telemetry Hub', false);
        });
    });

    describe('index', function () {
        it('renders the companion branding settings page with default values and site counts', function () {
            $user = User::factory()->create();

            Site::factory()->count(3)->create([
                'companion_installed' => true,
                'is_inactive' => false,
            ]);
            Site::factory()->count(2)->create([
                'companion_installed' => false,
                'is_inactive' => false,
            ]);
            Site::factory()->create([
                'companion_installed' => true,
                'is_inactive' => true,
            ]);

            $response = $this->actingAs($user)->get(route('settings.companion.index'));

            $response->assertOk()
                ->assertSee('White Label')
                ->assertSee('Clockwork Web Dev')
                ->assertSee('Clockwork Companion')
                ->assertSee('Monitored Sites')
                ->assertSee('Companion Installed');
        });
    });

    describe('update', function () {
        it('updates white-label branding configuration and redirects with flash message', function () {
            Queue::fake();
            $user = User::factory()->create();

            $payload = [
                'enabled' => '1',
                'company_name' => 'Acme Web Agency',
                'company_url' => 'https://acmeagency.com',
                'support_email' => 'help@acmeagency.com',
                'support_url' => 'https://acmeagency.com/help',
                'plugin_name' => 'Acme Site Guardian',
                'plugin_description' => 'Custom monitoring and telemetry suite.',
                'menu_title' => 'Site Guardian',
                'menu_icon' => 'dashicons-shield',
                'hide_plugin_row' => '1',
                'hide_help_links' => '1',
                'footer_text' => 'Managed with care by Acme Agency',
            ];

            $response = $this->actingAs($user)->patch(route('settings.companion.update'), $payload);

            $response->assertRedirect(route('settings.companion.index'));
            $response->assertSessionHas('status', 'Companion branding saved successfully.');

            Queue::assertNotPushed(PushCompanionBrandingJob::class);

            $manager = app(CompanionBrandingManager::class);
            $branding = $manager->get();

            expect($branding['enabled'])->toBeTrue()
                ->and($branding['company_name'])->toBe('Acme Web Agency')
                ->and($branding['company_url'])->toBe('https://acmeagency.com')
                ->and($branding['support_email'])->toBe('help@acmeagency.com')
                ->and($branding['plugin_name'])->toBe('Acme Site Guardian')
                ->and($branding['menu_title'])->toBe('Site Guardian')
                ->and($branding['hide_plugin_row'])->toBeTrue()
                ->and($branding['is_custom'])->toBeTrue();
        });

        it('dispatches fleet sync job when sync_fleet is checked', function () {
            Queue::fake();
            $user = User::factory()->create();

            $response = $this->actingAs($user)->patch(route('settings.companion.update'), [
                'company_name' => 'Fleetwide Agency',
                'sync_fleet' => '1',
            ]);

            $response->assertRedirect(route('settings.companion.index'));
            $response->assertSessionHas('status', 'Companion branding saved and queued for fleet sync.');

            Queue::assertPushed(PushCompanionBrandingJob::class);
        });

        it('returns JSON response when requested via AJAX', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->patchJson(route('settings.companion.update'), [
                'company_name' => 'API Agency',
            ]);

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('branding.company_name', 'API Agency');
        });

        it('validates email format and string lengths', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->patch(route('settings.companion.update'), [
                'support_email' => 'not-an-email',
                'company_name' => str_repeat('a', 150),
            ]);

            $response->assertSessionHasErrors(['support_email', 'company_name']);
        });

        it('does not wipe a previously uploaded logo when the main form is saved', function () {
            Storage::fake('public');
            $user = User::factory()->create();

            $uploadResponse = $this->actingAs($user)->post(route('settings.companion.logo'), [
                'logo' => UploadedFile::fake()->image('logo.png', 100, 100),
            ]);
            $uploadResponse->assertRedirect();

            $logoUrl = app(CompanionBrandingManager::class)->get()['logo_url'];
            expect($logoUrl)->not->toBe('');

            // The real settings.companion form has no logo_url field at all —
            // this mirrors that by never mentioning it.
            $this->actingAs($user)->patch(route('settings.companion.update'), [
                'company_name' => 'Renamed Agency',
            ]);

            expect(app(CompanionBrandingManager::class)->get()['logo_url'])->toBe($logoUrl);
        });
    });

    describe('uploadLogo', function () {
        it('uploads a valid logo image to public disk and stores url in settings', function () {
            Storage::fake('public');
            $user = User::factory()->create();

            $file = UploadedFile::fake()->image('agency-logo.png', 400, 100);

            $response = $this->actingAs($user)->post(route('settings.companion.logo'), [
                'logo' => $file,
            ]);

            $response->assertRedirect();
            $response->assertSessionHas('status', 'Brand logo uploaded successfully.');

            $manager = app(CompanionBrandingManager::class);
            $branding = $manager->get();

            expect($branding['logo_url'])->toContain('/storage/branding/companion-logo-');
        });

        it('rejects non-image files or files exceeding max size', function () {
            Storage::fake('public');
            $user = User::factory()->create();

            $file = UploadedFile::fake()->create('malicious.exe', 5000);

            $response = $this->actingAs($user)->post(route('settings.companion.logo'), [
                'logo' => $file,
            ]);

            $response->assertSessionHasErrors(['logo']);
        });
    });

    describe('sync', function () {
        it('executes fleet sync and redirects with summary message', function () {
            $user = User::factory()->create();

            $mockManager = Mockery::mock(CompanionBrandingManager::class);
            $mockManager->shouldReceive('syncFleet')->once()->andReturn([
                'total' => 2,
                'successful' => 2,
                'failed' => 0,
                'errors' => [],
            ]);
            $this->app->instance(CompanionBrandingManager::class, $mockManager);

            $response = $this->actingAs($user)->post(route('settings.companion.sync'));

            $response->assertRedirect();
            $response->assertSessionHas('status', 'Branding synced to 2 of 2 site(s).');
        });

        it('reports failure warnings when one or more sites fail sync', function () {
            $user = User::factory()->create();

            $mockManager = Mockery::mock(CompanionBrandingManager::class);
            $mockManager->shouldReceive('syncFleet')->once()->andReturn([
                'total' => 3,
                'successful' => 2,
                'failed' => 1,
                'errors' => ['fail.com' => 'Connection timeout'],
            ]);
            $this->app->instance(CompanionBrandingManager::class, $mockManager);

            $response = $this->actingAs($user)->post(route('settings.companion.sync'));

            $response->assertRedirect();
            $response->assertSessionHas('warning', 'Branding synced to 2 of 3 site(s). (1 failed)');
        });
    });

    describe('reset', function () {
        it('resets white-label settings to Clockwork Control defaults', function () {
            $user = User::factory()->create();
            $manager = app(CompanionBrandingManager::class);

            $manager->save([
                'enabled' => true,
                'company_name' => 'Custom Company',
            ]);

            expect($manager->get()['is_custom'])->toBeTrue();

            $response = $this->actingAs($user)->post(route('settings.companion.reset'));

            $response->assertRedirect(route('settings.companion.index'));
            $response->assertSessionHas('status', 'Companion branding reset to Clockwork Control defaults.');

            $freshBranding = $manager->get();
            expect($freshBranding['enabled'])->toBeFalse()
                ->and($freshBranding['company_name'])->toBe(CompanionBrandingManager::DEFAULT_COMPANY_NAME)
                ->and($freshBranding['is_custom'])->toBeFalse();
        });
    });
});
