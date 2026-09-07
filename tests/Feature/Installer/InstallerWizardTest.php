<?php

use App\Http\Middleware\EnforceInstallerGate;
use App\Installer\InstallerEnvWriter;
use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

describe('InstallerWizard', function () {
    $tempEnv = null;

    beforeEach(function () use (&$tempEnv) {
        EnforceInstallerGate::fake(false);
        $tempEnv = tempnam(sys_get_temp_dir(), 'installer_test_env_');
        file_put_contents($tempEnv, file_get_contents(base_path('.env.example')));
        app()->instance(InstallerEnvWriter::class, new InstallerEnvWriter($tempEnv));
    });

    afterEach(function () use (&$tempEnv) {
        EnforceInstallerGate::fake(null);
        @unlink(EnforceInstallerGate::sentinelPath());
        if ($tempEnv && file_exists($tempEnv)) {
            @unlink($tempEnv);
        }
        app()->forgetInstance(InstallerEnvWriter::class);
    });

    it('renders step 1 welcome and detects requirements', function () {
        $response = $this->get(route('install.welcome'));

        $response->assertOk()
            ->assertSee('Welcome to Clockwork Control')
            ->assertSee('Pre-flight Environment Check')
            ->assertSee('Get Started');
    });

    it('saves step 2 database preferences to session', function () {
        $response = $this->post(route('install.database.save'), [
            'host' => '127.0.0.1',
            'port' => 3306,
            'database' => 'clockwork_test',
            'username' => 'db_user',
            'password' => 'secret_pass',
        ]);

        $response->assertRedirect(route('install.app'));

        expect(session('install.wizard.database.database'))->toBe('clockwork_test');
    });

    it('saves step 3 app identity preferences to session', function () {
        $response = $this->post(route('install.app.save'), [
            'name' => 'Agency Fleet Panel',
            'url' => 'https://control.agency.test',
            'timezone' => 'America/New_York',
        ]);

        $response->assertRedirect(route('install.mail'));

        expect(session('install.wizard.app.name'))->toBe('Agency Fleet Panel');
        expect(session('install.wizard.app.timezone'))->toBe('America/New_York');
    });

    it('allows skipping step 4 mail', function () {
        $response = $this->post(route('install.mail.skip'));

        $response->assertRedirect(route('install.google'));

        expect(session('install.wizard.mail.skipped'))->toBeTrue();
    });

    it('saves step 5 google oauth configuration to session', function () {
        $response = $this->post(route('install.google.save'), [
            'client_id' => '123456.apps.googleusercontent.com',
            'client_secret' => 'test-google-client-secret',
            'hd' => 'agency.test',
        ]);

        $response->assertRedirect(route('install.admin'));

        expect(session('install.wizard.google.client_id'))->toBe('123456.apps.googleusercontent.com');
        expect(session('install.wizard.google.skipped'))->toBeFalse();
    });

    it('allows skipping step 5 google oauth', function () {
        $response = $this->post(route('install.google.skip'));

        $response->assertRedirect(route('install.admin'));

        expect(session('install.wizard.google.skipped'))->toBeTrue();
    });

    it('saves step 6 admin user configuration with local password to session', function () {
        $response = $this->post(route('install.admin.save'), [
            'email' => 'admin@agency.test',
            'name' => 'Lead Operator',
            'password' => 'secretPassword123!',
            'password_confirmation' => 'secretPassword123!',
        ]);

        $response->assertRedirect(route('install.hosting'));

        expect(session('install.wizard.admin.email'))->toBe('admin@agency.test');
        expect(session('install.wizard.admin.password'))->toBe('secretPassword123!');
    });

    it('allows omitting password when google oauth is configured', function () {
        $response = $this->withSession([
            'install.wizard.google' => [
                'client_id' => '123456.apps.googleusercontent.com',
                'client_secret' => 'test-secret',
                'skipped' => false,
            ],
        ])->post(route('install.admin.save'), [
            'email' => 'admin@agency.test',
            'name' => 'Lead Operator',
        ]);

        $response->assertRedirect(route('install.hosting'));

        expect(session('install.wizard.admin.email'))->toBe('admin@agency.test');
        expect(session('install.wizard.admin.password'))->toBeNull();
    });

    it('saves step 7 hosting quick-connect choice to session', function () {
        $response = $this->post(route('install.hosting.save'), [
            'provider' => 'spinupwp',
        ]);

        $response->assertRedirect(route('install.vps'));

        expect(session('install.wizard.hosting.provider'))->toBe('spinupwp');
    });

    it('saves step 8 cloud infrastructure choice to session', function () {
        $response = $this->post(route('install.vps.save'), [
            'providers' => ['digitalocean', 'hetzner'],
        ]);

        $response->assertRedirect(route('install.review'));

        expect(session('install.wizard.vps.providers'))->toBe(['digitalocean', 'hetzner']);
    });

    it('renders step 9 review with masked credentials', function () {
        $this->withSession([
            'install.wizard' => [
                'database' => [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'clockwork_prod',
                    'username' => 'root',
                    'password' => 'supersecret',
                ],
                'app' => [
                    'name' => 'Agency Control',
                    'url' => 'https://panel.agency.test',
                    'timezone' => 'UTC',
                ],
                'mail' => [
                    'skipped' => true,
                ],
                'google' => [
                    'client_id' => '123456.apps.googleusercontent.com',
                    'client_secret' => 'topsecretoauth',
                    'hd' => null,
                ],
                'admin' => [
                    'name' => 'Alex Admin',
                    'email' => 'alex@agency.test',
                ],
                'hosting' => [
                    'provider' => 'spinupwp',
                ],
            ],
        ]);

        $response = $this->get(route('install.review'));

        $response->assertOk()
            ->assertSee('clockwork_prod')
            ->assertSee('••••••••')
            ->assertDontSee('supersecret')
            ->assertDontSee('topsecretoauth')
            ->assertSee('alex@agency.test')
            ->assertSee('Install &amp; Complete Setup', false);
    });

    it('fails installation if disclaimer is not accepted', function () {
        $this->withSession([
            'install.wizard' => [
                'database' => [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'clockwork_prod',
                    'username' => 'root',
                    'password' => '',
                ],
                'admin' => [
                    'name' => 'Dev Ops',
                    'email' => 'devops@agency.test',
                ],
            ],
        ]);

        $response = $this->from(route('install.review'))->post(route('install.run'), []);

        $response->assertRedirect(route('install.review'));
        $response->assertSessionHasErrors('disclaimer_accepted');
        expect(file_exists(EnforceInstallerGate::sentinelPath()))->toBeFalse();
    });

    it('executes full installation on step 8 submit and provisions admin when disclaimer is accepted', function () use (&$tempEnv) {
        $this->withSession([
            'install.wizard' => [
                'database' => [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'clockwork_prod',
                    'username' => 'root',
                    'password' => '',
                ],
                'app' => [
                    'name' => 'Agency Control',
                    'url' => 'https://panel.agency.test',
                    'timezone' => 'UTC',
                ],
                'mail' => [
                    'skipped' => true,
                ],
                'google' => [
                    'client_id' => 'google-client-id-123',
                    'client_secret' => 'google-secret-456',
                    'hd' => null,
                ],
                'admin' => [
                    'name' => 'Dev Ops',
                    'email' => 'devops@agency.test',
                ],
                'hosting' => [
                    'provider' => 'spinupwp',
                ],
            ],
        ]);

        $response = $this->post(route('install.run'), [
            'disclaimer_accepted' => '1',
            'telemetry_opt_in' => '1',
        ]);

        $response->assertRedirect(route('install.done'));

        expect(file_exists(EnforceInstallerGate::sentinelPath()))->toBeTrue();

        $user = User::where('email', 'devops@agency.test')->first();
        expect($user)->not->toBeNull()
            ->and($user->name)->toBe('Dev Ops')
            ->and($user->revoked_at)->toBeNull();

        $settings = app(Settings::class);
        expect($settings->get('disclaimer.accepted_at'))->not->toBeNull()
            ->and($settings->get('disclaimer.accepted_version'))->toBe('1.2.2')
            ->and($settings->get('telemetry.enabled'))->toBeTrue();

        $envContents = file_get_contents($tempEnv);
        expect($envContents)->toContain('CLOCKWORK_TELEMETRY_ENABLED=true');
    });

    it('executes full installation with skipped google oauth and provisions admin with working local password', function () use (&$tempEnv) {
        $this->withSession([
            'install.wizard' => [
                'database' => [
                    'host' => '127.0.0.1',
                    'port' => 3306,
                    'database' => 'clockwork_prod',
                    'username' => 'root',
                    'password' => '',
                ],
                'app' => [
                    'name' => 'Karena Agency Fleet',
                    'url' => 'https://fleet.agency.test',
                    'timezone' => 'UTC',
                ],
                'mail' => [
                    'skipped' => true,
                ],
                'google' => [
                    'skipped' => true,
                    'client_id' => '',
                    'client_secret' => '',
                    'hd' => null,
                ],
                'admin' => [
                    'name' => 'Karena Operator',
                    'email' => 'karena@agency.test',
                    'password' => 'localSecretPass123!',
                ],
                'hosting' => [
                    'provider' => 'skip',
                ],
            ],
        ]);

        $response = $this->post(route('install.run'), [
            'disclaimer_accepted' => '1',
            'telemetry_opt_in' => '0',
        ]);

        $response->assertRedirect(route('install.done'));
        expect(file_exists(EnforceInstallerGate::sentinelPath()))->toBeTrue();

        $user = User::where('email', 'karena@agency.test')->firstOrFail();
        expect($user->name)->toBe('Karena Operator')
            ->and($user->password)->not->toBeNull()
            ->and(Hash::check('localSecretPass123!', $user->password))->toBeTrue();

        $envContents = file_get_contents($tempEnv);
        expect($envContents)->toContain('GOOGLE_CLIENT_ID=')
            ->and($envContents)->not->toContain('GOOGLE_CLIENT_ID=google-client-id');

        // Verify the operator can now immediately sign in with their new password
        EnforceInstallerGate::fake(true);
        $this->mockIssueCounterZero();
        $loginResponse = $this->post(route('login.attempt'), [
            'email' => 'karena@agency.test',
            'password' => 'localSecretPass123!',
        ]);

        $loginResponse->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    });

    it('renders step 9 done screen', function () {
        $response = $this->get(route('install.done'));

        $response->assertOk()
            ->assertSee('Clockwork Control is Installed!')
            ->assertSee('Go to Sign In');
    });
});
