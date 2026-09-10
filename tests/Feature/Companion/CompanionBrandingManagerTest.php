<?php

use App\Jobs\PushCompanionBrandingJob;
use App\Models\Site;
use App\Services\Companion\CompanionBrandingManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

describe('CompanionBrandingManager', function () {
    it('returns default branding configuration when none is set', function () {
        $manager = app(CompanionBrandingManager::class);
        $branding = $manager->get();

        expect($branding['enabled'])->toBeFalse()
            ->and($branding['company_name'])->toBe(CompanionBrandingManager::DEFAULT_COMPANY_NAME)
            ->and($branding['company_url'])->toBe(CompanionBrandingManager::DEFAULT_COMPANY_URL)
            ->and($branding['support_email'])->toBe(CompanionBrandingManager::DEFAULT_SUPPORT_EMAIL)
            ->and($branding['plugin_name'])->toBe(CompanionBrandingManager::DEFAULT_PLUGIN_NAME)
            ->and($branding['menu_title'])->toBe(CompanionBrandingManager::DEFAULT_MENU_TITLE)
            ->and($branding['menu_icon'])->toBe(CompanionBrandingManager::DEFAULT_MENU_ICON)
            ->and($branding['hide_plugin_row'])->toBeFalse()
            ->and($branding['hide_help_links'])->toBeFalse()
            ->and($branding['is_custom'])->toBeFalse();
    });

    it('persists and retrieves custom branding parameters', function () {
        $manager = app(CompanionBrandingManager::class);

        $manager->save([
            'enabled' => true,
            'company_name' => 'Agency Pro',
            'company_url' => 'https://agencypro.dev',
            'support_email' => 'ops@agencypro.dev',
            'support_url' => 'https://agencypro.dev/support',
            'plugin_name' => 'Agency Pro Telemetry',
            'plugin_description' => 'Dedicated site monitoring & security agent.',
            'menu_title' => 'Agency Pro',
            'menu_icon' => 'dashicons-admin-generic',
            'logo_url' => 'https://agencypro.dev/logo.svg',
            'hide_plugin_row' => true,
            'hide_help_links' => true,
            'footer_text' => 'Powered by Agency Pro',
        ]);

        $branding = $manager->get();

        expect($branding['enabled'])->toBeTrue()
            ->and($branding['company_name'])->toBe('Agency Pro')
            ->and($branding['company_url'])->toBe('https://agencypro.dev')
            ->and($branding['support_email'])->toBe('ops@agencypro.dev')
            ->and($branding['support_url'])->toBe('https://agencypro.dev/support')
            ->and($branding['plugin_name'])->toBe('Agency Pro Telemetry')
            ->and($branding['plugin_description'])->toBe('Dedicated site monitoring & security agent.')
            ->and($branding['menu_title'])->toBe('Agency Pro')
            ->and($branding['menu_icon'])->toBe('dashicons-admin-generic')
            ->and($branding['logo_url'])->toBe('https://agencypro.dev/logo.svg')
            ->and($branding['hide_plugin_row'])->toBeTrue()
            ->and($branding['footer_text'])->toBe('Powered by Agency Pro')
            ->and($branding['is_custom'])->toBeTrue();
    });

    it('generates a complete wire transmission payload with synced_at timestamp', function () {
        $manager = app(CompanionBrandingManager::class);
        $manager->save([
            'enabled' => true,
            'company_name' => 'Partner Brand',
        ]);

        $payload = $manager->payload();

        expect($payload)->toHaveKeys([
            'enabled',
            'company_name',
            'company_url',
            'support_email',
            'support_url',
            'plugin_name',
            'plugin_description',
            'menu_title',
            'menu_icon',
            'logo_url',
            'hide_plugin_row',
            'hide_help_links',
            'footer_text',
            'synced_at',
        ])
            ->and($payload['enabled'])->toBeTrue()
            ->and($payload['company_name'])->toBe('Partner Brand')
            ->and($payload['synced_at'])->toBeString();
    });

    it('resets branding back to defaults', function () {
        $manager = app(CompanionBrandingManager::class);
        $manager->save([
            'enabled' => true,
            'company_name' => 'Custom Name',
            'logo_url' => 'https://example.com/logo.png',
        ]);

        expect($manager->get()['company_name'])->toBe('Custom Name');

        $manager->reset();

        $fresh = $manager->get();
        expect($fresh['enabled'])->toBeFalse()
            ->and($fresh['company_name'])->toBe(CompanionBrandingManager::DEFAULT_COMPANY_NAME)
            ->and($fresh['logo_url'])->toBe('')
            ->and($fresh['is_custom'])->toBeFalse();
    });

    describe('uploadLogo', function () {
        it('stores the file, sets logo_url, and deletes the previous file on re-upload', function () {
            Storage::fake('public');
            $manager = app(CompanionBrandingManager::class);

            $first = UploadedFile::fake()->image('first-logo.png', 100, 100);
            $firstUrl = $manager->uploadLogo($first);

            $firstPath = 'branding/'.basename(parse_url($firstUrl, PHP_URL_PATH));
            Storage::disk('public')->assertExists($firstPath);
            expect($manager->get()['logo_url'])->toBe($firstUrl);

            $second = UploadedFile::fake()->image('second-logo.png', 100, 100);
            $secondUrl = $manager->uploadLogo($second);

            expect($secondUrl)->not->toBe($firstUrl);
            Storage::disk('public')->assertMissing($firstPath);
            expect($manager->get()['logo_url'])->toBe($secondUrl);
        });
    });

    describe('save() with an explicit logo_url', function () {
        it('does not wipe a previously uploaded logo when logo_url is absent from the payload', function () {
            Storage::fake('public');
            $manager = app(CompanionBrandingManager::class);

            $url = $manager->uploadLogo(UploadedFile::fake()->image('logo.png', 100, 100));

            // Mirrors CompanionSettingsController::update() — saves the rest
            // of the form without ever mentioning logo_url at all.
            $manager->save(['company_name' => 'Some Agency']);

            expect($manager->get()['logo_url'])->toBe($url);
        });

        it('deletes a previously uploaded local file when an explicit logo_url overrides it', function () {
            Storage::fake('public');
            $manager = app(CompanionBrandingManager::class);

            $uploadedUrl = $manager->uploadLogo(UploadedFile::fake()->image('logo.png', 100, 100));
            $uploadedPath = 'branding/'.basename(parse_url($uploadedUrl, PHP_URL_PATH));
            Storage::disk('public')->assertExists($uploadedPath);

            $manager->save(['logo_url' => 'https://external.example.com/logo.svg']);

            Storage::disk('public')->assertMissing($uploadedPath);
            expect($manager->get()['logo_url'])->toBe('https://external.example.com/logo.svg');
        });

        it('keeps the uploaded file when save() re-posts the same logo_url', function () {
            Storage::fake('public');
            $manager = app(CompanionBrandingManager::class);

            $uploadedUrl = $manager->uploadLogo(UploadedFile::fake()->image('logo.png', 100, 100));
            $uploadedPath = 'branding/'.basename(parse_url($uploadedUrl, PHP_URL_PATH));
            Storage::disk('public')->assertExists($uploadedPath);

            $manager->save([
                'company_name' => 'Same Logo Agency',
                'logo_url' => $uploadedUrl,
            ]);

            Storage::disk('public')->assertExists($uploadedPath);
            expect($manager->get()['logo_url'])->toBe($uploadedUrl);
        });
    });

    describe('reset', function () {
        it('deletes the stored logo file from disk', function () {
            Storage::fake('public');
            $manager = app(CompanionBrandingManager::class);

            $url = $manager->uploadLogo(UploadedFile::fake()->image('logo.png', 100, 100));
            $path = 'branding/'.basename(parse_url($url, PHP_URL_PATH));
            Storage::disk('public')->assertExists($path);

            $manager->reset();

            Storage::disk('public')->assertMissing($path);
        });
    });

    describe('syncSite', function () {
        it('returns false immediately if site does not have companion installed or missing secret', function () {
            $siteNoCompanion = Site::factory()->create([
                'companion_installed' => false,
                'companion_secret' => 'some-secret',
            ]);

            $siteNoSecret = Site::factory()->create([
                'companion_installed' => true,
                'companion_secret' => null,
            ]);

            $manager = app(CompanionBrandingManager::class);

            expect($manager->syncSite($siteNoCompanion))->toBeFalse()
                ->and($manager->syncSite($siteNoSecret))->toBeFalse();
        });

        it('dispatches HMAC-signed REST request to /branding endpoint and returns true on success', function () {
            $site = Site::factory()->withCompanionInstalled()->create();
            $manager = app(CompanionBrandingManager::class);
            $manager->save([
                'enabled' => true,
                'company_name' => 'Signature Tested Agency',
            ]);

            Http::fake([
                "https://{$site->domain}/wp-json/clockwork/v1/branding" => function ($request) {
                    $hasSig = $request->hasHeader('X-Clockwork-Signature');
                    $hasTime = $request->hasHeader('X-Clockwork-Timestamp');
                    $data = $request->data();

                    return ($hasSig && $hasTime && ($data['company_name'] ?? '') === 'Signature Tested Agency')
                        ? Http::response(['ok' => true, 'updated' => true], 200)
                        : Http::response(['ok' => false, 'error' => 'invalid_signature'], 401);
                },
            ]);

            $result = $manager->syncSite($site);

            expect($result)->toBeTrue();
        });

        it('handles connection failures gracefully and logs warning', function () {
            $site = Site::factory()->withCompanionInstalled()->create();
            $manager = app(CompanionBrandingManager::class);

            Http::fake([
                "https://{$site->domain}/wp-json/clockwork/v1/branding" => Http::response(['ok' => false, 'error' => 'server_error'], 500),
            ]);

            Log::shouldReceive('warning')
                ->once()
                ->with('companion.branding_sync_failed', Mockery::subset([
                    'site_id' => $site->id,
                    'domain' => $site->domain,
                ]));

            $result = $manager->syncSite($site);

            expect($result)->toBeFalse();
        });
    });

    describe('syncFleet', function () {
        it('syncs branding to all active companion-installed sites', function () {
            $site1 = Site::factory()->withCompanionInstalled()->create(['domain' => 'alpha.com', 'is_inactive' => false]);
            $site2 = Site::factory()->withCompanionInstalled()->create(['domain' => 'beta.com', 'is_inactive' => false]);
            Site::factory()->create(['domain' => 'gamma.com', 'companion_installed' => false]);
            Site::factory()->withCompanionInstalled()->create(['domain' => 'delta.com', 'is_inactive' => true]);

            Http::fake([
                'https://alpha.com/wp-json/clockwork/v1/branding' => Http::response(['ok' => true], 200),
                'https://beta.com/wp-json/clockwork/v1/branding' => Http::response(['ok' => false], 500),
            ]);

            $manager = app(CompanionBrandingManager::class);
            $results = $manager->syncFleet();

            expect($results['total'])->toBe(2)
                ->and($results['successful'])->toBe(1)
                ->and($results['failed'])->toBe(1)
                ->and($results['errors'])->toHaveKey('beta.com');
        });
    });

    describe('command and job', function () {
        it('executes PushCompanionBranding command with site filter and fleet', function () {
            $site = Site::factory()->withCompanionInstalled()->create(['domain' => 'cli-test.com', 'is_inactive' => false]);

            Http::fake([
                'https://cli-test.com/wp-json/clockwork/v1/branding' => Http::response(['ok' => true], 200),
            ]);

            $this->artisan('clockwork:push-companion-branding', ['--site' => 'cli-test.com'])
                ->expectsOutputToContain('Pushing branding')
                ->expectsOutputToContain('cli-test.com synced.')
                ->assertSuccessful();

            $this->artisan('clockwork:push-companion-branding', ['--site' => 'nonexistent.com'])
                ->expectsOutputToContain('No eligible Companion sites found.')
                ->assertSuccessful();
        });

        it('handles PushCompanionBrandingJob for single site and fleet', function () {
            $site = Site::factory()->withCompanionInstalled()->create(['domain' => 'job-test.com', 'is_inactive' => false]);

            Http::fake([
                'https://job-test.com/wp-json/clockwork/v1/branding' => Http::response(['ok' => true], 200),
            ]);

            $jobSingle = new PushCompanionBrandingJob($site->id);
            $jobSingle->handle(app(CompanionBrandingManager::class));

            $jobFleet = new PushCompanionBrandingJob;
            $jobFleet->handle(app(CompanionBrandingManager::class));

            Http::assertSentCount(2);
        });
    });

    describe('color palette and master palette', function () {
        it('persists primary_color and accent_color and includes them in payload', function () {
            $manager = app(CompanionBrandingManager::class);
            $manager->save([
                'primary_color' => '#0F172A',
                'accent_color' => '#38BDF8',
            ]);

            $branding = $manager->get();
            expect($branding['primary_color'])->toBe('#0F172A')
                ->and($branding['accent_color'])->toBe('#38BDF8');

            $payload = $manager->payload();
            expect($payload['primary_color'])->toBe('#0F172A')
                ->and($payload['primary_dark_color'])->toBe('#0F172A')
                ->and($payload['accent_color'])->toBe('#38BDF8');
        });

        it('saves and cascades master palette to all 3 surfaces when requested', function () {
            $manager = app(CompanionBrandingManager::class);
            $manager->saveMasterPalette([
                'primary_color' => '#1E1B4B',
                'accent_color' => '#F43F5E',
            ], applyToAll: true);

            $master = $manager->getMasterPalette();
            expect($master['primary_color'])->toBe('#1E1B4B')
                ->and($master['accent_color'])->toBe('#F43F5E');

            $companion = $manager->get();
            expect($companion['primary_color'])->toBe('#1E1B4B')
                ->and($companion['accent_color'])->toBe('#F43F5E');

            $reports = $manager->getReportsBranding();
            expect($reports['primary_color'])->toBe('#1E1B4B')
                ->and($reports['accent_color'])->toBe('#F43F5E');

            $email = $manager->getEmailBranding();
            expect($email['header_bg'])->toBe('#1E1B4B')
                ->and($email['accent_color'])->toBe('#F43F5E');
        });

        it('normalizes invalid stored hex colors back to defaults', function () {
            $manager = app(CompanionBrandingManager::class);
            $manager->save([
                'primary_color' => 'not-a-color',
                'accent_color' => '#38BDF8',
            ]);

            $branding = $manager->get();
            expect($branding['primary_color'])->toBe(CompanionBrandingManager::DEFAULT_PRIMARY_COLOR)
                ->and($branding['accent_color'])->toBe('#38BDF8');
        });
    });
});
