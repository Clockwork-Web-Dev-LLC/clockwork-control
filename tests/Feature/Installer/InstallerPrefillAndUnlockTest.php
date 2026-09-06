<?php

use App\Http\Middleware\EnforceInstallerGate;
use App\Models\User;

describe('Installer Prefill and Unlock', function () {
    beforeEach(function () {
        EnforceInstallerGate::fake(false);
        @unlink(EnforceInstallerGate::sentinelPath());
        @unlink(storage_path('installer_reopened'));
    });

    afterEach(function () {
        EnforceInstallerGate::fake(null);
        @unlink(EnforceInstallerGate::sentinelPath());
        @unlink(storage_path('installer_reopened'));
    });

    it('prefills google oauth step from configuration when session is empty', function () {
        config([
            'services.google.client_id' => 'test-prefilled-client-id',
            'services.google.client_secret' => 'test-prefilled-secret',
            'services.google.hosted_domain' => 'test-agency.com',
        ]);

        $response = $this->get(route('install.google'));

        $response->assertOk()
            ->assertSee('value="test-prefilled-client-id"', false)
            ->assertSee('value="test-prefilled-secret"', false)
            ->assertSee('value="test-agency.com"', false);
    });

    it('prefills admin step from existing user in database', function () {
        User::factory()->create([
            'name' => 'Existing Operator',
            'email' => 'operator@agency.com',
            'revoked_at' => null,
        ]);

        $response = $this->get(route('install.admin'));

        $response->assertOk()
            ->assertSee('value="Existing Operator"', false)
            ->assertSee('value="operator@agency.com"', false);
    });

    it('allows unlocking an existing installation via POST /install/unlock', function () {
        User::factory()->create([
            'name' => 'Existing Operator',
            'email' => 'operator@agency.com',
            'revoked_at' => null,
        ]);

        $sentinel = EnforceInstallerGate::sentinelPath();
        expect(file_exists($sentinel))->toBeFalse();

        $response = $this->post(route('install.unlock'));

        $response->assertRedirect(route('login'));
        expect(file_exists($sentinel))->toBeTrue();

        $data = json_decode((string) file_get_contents($sentinel), true);
        expect($data['unlocked_from_existing'])->toBeTrue()
            ->and($data['admin_email'])->toBe('operator@agency.com');
    });

    it('auto-heals and allows access to login when active users exist in database', function () {
        // Unfake installer gate and enable real-world gate evaluation
        EnforceInstallerGate::fake(null);
        EnforceInstallerGate::$ignoreUnitTestBypass = true;

        User::factory()->create([
            'email' => 'operator@agency.com',
            'revoked_at' => null,
        ]);

        $sentinel = EnforceInstallerGate::sentinelPath();
        @unlink($sentinel);
        expect(file_exists($sentinel))->toBeFalse();

        $response = $this->get('/login');

        $response->assertOk();
        expect(file_exists($sentinel))->toBeTrue();

        $data = json_decode((string) file_get_contents($sentinel), true);
        expect($data['auto_healed'])->toBeTrue();

        @unlink($sentinel);
        EnforceInstallerGate::$ignoreUnitTestBypass = false;
    });

    it('renders categorized hosting providers matching /setup without Forge, RunCloud, or Custom VPS', function () {
        $response = $this->get(route('install.hosting'));

        $response->assertOk()
            ->assertSee('Server Management Panels')
            ->assertSee('Managed WordPress Hosts')
            ->assertSee('SpinupWP')
            ->assertSee('Cloudways')
            ->assertSee('GridPane')
            ->assertSee('Pressable')
            ->assertSee('WP Engine')
            ->assertSee('Kinsta')
            ->assertDontSee('Laravel Forge')
            ->assertDontSee('RunCloud')
            ->assertDontSee('Custom VPS')
            ->assertDontSee('Direct Server Access');
    });

    it('allows selecting multiple hosting providers like SpinupWP and Pressable', function () {
        $response = $this->post(route('install.hosting.save'), [
            'providers' => ['spinupwp', 'pressable'],
        ]);

        $response->assertRedirect(route('install.vps'));

        expect(session('install.wizard.hosting.providers'))->toBe(['spinupwp', 'pressable'])
            ->and(session('install.wizard.hosting.provider'))->toBe('spinupwp');
    });

    it('renders step 8 cloud infrastructure & VPS screen', function () {
        $response = $this->get(route('install.vps'));

        $response->assertOk()
            ->assertSee('Cloud Infrastructure &amp; VPS', false)
            ->assertSee('Hardware Telemetry Pairing')
            ->assertSee('DigitalOcean')
            ->assertSee('Hetzner')
            ->assertSee('Vultr')
            ->assertSee('Linode')
            ->assertSee('Azure');
    });

    it('allows selecting cloud infrastructure providers and redirects to review', function () {
        $response = $this->post(route('install.vps.save'), [
            'providers' => ['digitalocean', 'hetzner'],
        ]);

        $response->assertRedirect(route('install.review'));

        expect(session('install.wizard.vps.providers'))->toBe(['digitalocean', 'hetzner'])
            ->and(session('install.wizard.vps.provider'))->toBe('digitalocean');
    });

    it('renders hosting and cloud infrastructure cards on review screen', function () {
        $this->withSession([
            'install.wizard' => [
                'database' => [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'clockwork',
                    'username' => 'root',
                    'password' => '',
                ],
                'app' => [
                    'name' => 'Agency Fleet',
                    'url' => 'http://localhost:8000',
                    'timezone' => 'UTC',
                ],
                'google' => [
                    'client_id' => 'test-id',
                    'client_secret' => 'test-secret',
                    'hd' => null,
                ],
                'admin' => [
                    'name' => 'Operator',
                    'email' => 'op@agency.test',
                ],
                'hosting' => [
                    'providers' => ['spinupwp', 'pressable'],
                ],
                'vps' => [
                    'providers' => ['digitalocean', 'hetzner'],
                ],
            ],
        ]);

        $response = $this->get(route('install.review'));

        $response->assertOk()
            ->assertSee('Step 9: Review Configuration')
            ->assertSee('Hosting Infrastructure')
            ->assertSee('SpinupWP')
            ->assertSee('Pressable')
            ->assertSee('Cloud Infrastructure &amp; VPS', false)
            ->assertSee('DigitalOcean')
            ->assertSee('Hetzner');
    });
});
