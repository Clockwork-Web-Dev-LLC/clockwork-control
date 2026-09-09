<?php

use App\Jobs\PushCompanionBrandingJob;
use App\Mail\SiteVulnerabilityReportMail;
use App\Models\Site;
use App\Models\User;
use App\Services\Companion\CompanionBrandingManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
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
        $this->post(route('settings.companion.reset-reports'))->assertRedirect(route('login'));
        $this->post(route('settings.companion.reset-email'))->assertRedirect(route('login'));
        $this->post(route('settings.companion.test-email'), [])->assertRedirect(route('login'));
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
                ->assertSee('White Label &amp; Styling Hub', false)
                ->assertDontSee('White Label &amp;amp;', false)
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

        it('rejects SVG uploads', function () {
            Storage::fake('public');
            $user = User::factory()->create();

            $file = UploadedFile::fake()->create('agency-logo.svg', 20, 'image/svg+xml');

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

    describe('reports branding', function () {
        it('renders the reports tab with branding configuration and preview', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->get(route('settings.companion.index', ['tab' => 'reports']));

            $response->assertOk()
                ->assertSee('Client Reports')
                ->assertSee('Enable Custom Reports Styling')
                ->assertSee('Primary Brand Color')
                ->assertSee('Accent Strip')
                ->assertSee('Executive Summary');
        });

        it('saves custom reports branding and redirects to reports tab', function () {
            $user = User::factory()->create();

            $payload = [
                'tab' => 'reports',
                'enabled' => '1',
                'company_name' => 'Acme Reports Agency',
                'support_email' => 'reports@acmeagency.com',
                'support_url' => 'https://acmeagency.com/help',
                'primary_color' => '#1a365d',
                'accent_color' => '#38b2ac',
                'footer_text' => 'Confidential Client SLA Report',
            ];

            $response = $this->actingAs($user)->patch(route('settings.companion.update'), $payload);

            $response->assertRedirect(route('settings.companion.index', ['tab' => 'reports']));
            $response->assertSessionHas('status', 'Client Reports styling and branding saved successfully.');

            $manager = app(CompanionBrandingManager::class);
            $reportsBranding = $manager->getReportsBranding();

            expect($reportsBranding['enabled'])->toBeTrue()
                ->and($reportsBranding['company_name'])->toBe('Acme Reports Agency')
                ->and($reportsBranding['support_email'])->toBe('reports@acmeagency.com')
                ->and($reportsBranding['primary_color'])->toBe('#1a365d')
                ->and($reportsBranding['accent_color'])->toBe('#38b2ac')
                ->and($reportsBranding['footer_text'])->toBe('Confidential Client SLA Report');
        });

        it('returns JSON response for reports tab updates via AJAX', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->patchJson(route('settings.companion.update'), [
                'tab' => 'reports',
                'company_name' => 'JSON Reports Inc',
                'primary_color' => '#123456',
            ]);

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('branding.company_name', 'JSON Reports Inc')
                ->assertJsonPath('branding.primary_color', '#123456');
        });

        it('validates reports support email', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->patch(route('settings.companion.update'), [
                'tab' => 'reports',
                'support_email' => 'invalid-email',
            ]);

            $response->assertSessionHasErrors(['support_email']);
        });

        it('resets reports styling to defaults', function () {
            $user = User::factory()->create();
            $manager = app(CompanionBrandingManager::class);

            $manager->saveReportsBranding([
                'enabled' => true,
                'primary_color' => '#ff0000',
                'footer_text' => 'Custom SLA',
            ]);

            $response = $this->actingAs($user)->post(route('settings.companion.reset-reports'));

            $response->assertRedirect(route('settings.companion.index', ['tab' => 'reports']));
            $response->assertSessionHas('status', 'Client Reports styling reset to shared defaults.');

            $fresh = $manager->getReportsBranding();
            expect($fresh['enabled'])->toBeFalse()
                ->and($fresh['primary_color'])->toBe('#2D2062')
                ->and($fresh['footer_text'])->toBe('');
        });
    });

    describe('email branding and notifications', function () {
        it('renders the email tab with branding configuration and preview', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->get(route('settings.companion.index', ['tab' => 'email']));

            $response->assertOk()
                ->assertSee('Plugin Notification Email')
                ->assertSee('Enable Custom Email Notification Branding')
                ->assertSee('Header Banner Background Color')
                ->assertSee('Accent Strip Color')
                ->assertSee('Send Test Email')
                ->assertSee('Security Alert');
        });

        it('saves custom email branding and redirects to email tab', function () {
            $user = User::factory()->create();

            $payload = [
                'tab' => 'email',
                'enabled' => '1',
                'company_name' => 'Sentinel WP Care',
                'sender_name' => 'Sentinel Security Desk',
                'reply_to' => 'alerts@sentinelwp.com',
                'header_bg' => '#0f172a',
                'accent_color' => '#10b981',
                'badge_text' => 'CVE Incident Alert',
                'footer_text' => 'Direct emergency hotline: 1-800-555-CARE',
                'use_logo' => '1',
            ];

            $response = $this->actingAs($user)->patch(route('settings.companion.update'), $payload);

            $response->assertRedirect(route('settings.companion.index', ['tab' => 'email']));
            $response->assertSessionHas('status', 'Plugin notification email branding saved successfully.');

            $manager = app(CompanionBrandingManager::class);
            $emailBranding = $manager->getEmailBranding();

            expect($emailBranding['enabled'])->toBeTrue()
                ->and($emailBranding['company_name'])->toBe('Sentinel WP Care')
                ->and($emailBranding['sender_name'])->toBe('Sentinel Security Desk')
                ->and($emailBranding['reply_to'])->toBe('alerts@sentinelwp.com')
                ->and($emailBranding['header_bg'])->toBe('#0f172a')
                ->and($emailBranding['accent_color'])->toBe('#10b981')
                ->and($emailBranding['badge_text'])->toBe('CVE Incident Alert')
                ->and($emailBranding['footer_text'])->toBe('Direct emergency hotline: 1-800-555-CARE');
        });

        it('returns JSON response for email tab updates via AJAX', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->patchJson(route('settings.companion.update'), [
                'tab' => 'email',
                'badge_text' => 'Urgent CVE Notice',
                'header_bg' => '#111827',
            ]);

            $response->assertOk()
                ->assertJsonPath('success', true)
                ->assertJsonPath('branding.badge_text', 'Urgent CVE Notice')
                ->assertJsonPath('branding.header_bg', '#111827');
        });

        it('validates email reply_to address', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->patch(route('settings.companion.update'), [
                'tab' => 'email',
                'reply_to' => 'not-an-email',
            ]);

            $response->assertSessionHasErrors(['reply_to']);
        });

        it('resets email branding to defaults', function () {
            $user = User::factory()->create();
            $manager = app(CompanionBrandingManager::class);

            $manager->saveEmailBranding([
                'enabled' => true,
                'header_bg' => '#ff0000',
                'badge_text' => 'Custom Alert',
            ]);

            $response = $this->actingAs($user)->post(route('settings.companion.reset-email'));

            $response->assertRedirect(route('settings.companion.index', ['tab' => 'email']));
            $response->assertSessionHas('status', 'Plugin notification email branding reset to defaults.');

            $fresh = $manager->getEmailBranding();
            expect($fresh['enabled'])->toBeFalse()
                ->and($fresh['header_bg'])->toBe('#2D2062')
                ->and($fresh['badge_text'])->toBe('Security Alert');
        });

        it('sends test vulnerability report email to specified recipient', function () {
            Mail::fake();
            $user = User::factory()->create();
            $site = Site::factory()->create(['domain' => 'test-client.com']);

            $response = $this->actingAs($user)->postJson(route('settings.companion.test-email'), [
                'recipient' => 'test-recipient@example.com',
            ]);

            $response->assertOk()
                ->assertJsonPath('ok', true)
                ->assertJsonPath('recipient', 'test-recipient@example.com');

            Mail::assertSent(SiteVulnerabilityReportMail::class, function ($mail) {
                return $mail->hasTo('test-recipient@example.com');
            });
        });

        it('validates test email recipient format', function () {
            $user = User::factory()->create();

            $response = $this->actingAs($user)->postJson(route('settings.companion.test-email'), [
                'recipient' => 'not-a-valid-email',
            ]);

            $response->assertStatus(422)
                ->assertJsonValidationErrors(['recipient']);
        });

        it('renders SiteVulnerabilityReportMail with dynamic branding properties and reply-to', function () {
            $site = Site::factory()->create(['domain' => 'vulnerable-site.test']);

            $customBranding = [
                'enabled' => true,
                'company_name' => 'Custom Care Ops',
                'company_url' => 'https://customcareops.test',
                'sender_name' => 'Ops Bot',
                'reply_to' => 'care-ops@customcareops.test',
                'header_bg' => '#0b3c5d',
                'accent_color' => '#328cc1',
                'badge_text' => 'Urgent CVE Alert',
                'footer_text' => '24/7 Security Hotline: (800) 555-0199',
                'use_logo' => false,
            ];

            $mail = new SiteVulnerabilityReportMail(
                site: $site,
                vulns: [
                    [
                        'plugin_name' => 'Vulnerable Plugin',
                        'plugin_slug' => 'vuln-plug',
                        'current_version' => '1.0.0',
                        'active' => true,
                        'vulnerability' => (object) [
                            'title' => 'SQL Injection in Admin',
                            'cve' => 'CVE-2024-99999',
                            'cvss_score' => 9.8,
                            'cvss_severity' => 'Critical',
                            'patched_in' => '1.0.1',
                        ],
                    ],
                ],
                senderName: 'Ops Bot',
                branding: $customBranding,
            );

            $envelope = $mail->envelope();
            expect($envelope->replyTo)->toHaveCount(1)
                ->and($envelope->replyTo[0]->address)->toBe('care-ops@customcareops.test');

            $renderedHtml = $mail->render();
            expect($renderedHtml)->toContain('#0b3c5d')
                ->and($renderedHtml)->toContain('#328cc1')
                ->and($renderedHtml)->toContain('Urgent CVE Alert')
                ->and($renderedHtml)->toContain('Custom Care Ops')
                ->and($renderedHtml)->toContain('24/7 Security Hotline: (800) 555-0199');
        });
    });
});
