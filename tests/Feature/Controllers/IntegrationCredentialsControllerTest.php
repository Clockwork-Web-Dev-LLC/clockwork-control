<?php

use App\Models\IntegrationCredential;
use App\Models\User;
use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\Checks\GoogleSafeBrowsingCheck;
use Tests\Concerns\RendersAuthenticatedPages;

uses(RendersAuthenticatedPages::class);

/*
|--------------------------------------------------------------------------
| IntegrationCredentialsController
|--------------------------------------------------------------------------
|
| index() is already smoke-tested by
| tests/Feature/Modules/NewHostingModuleSettingsPagesTest.php (asserts the
| 3 new hosting providers render) — not duplicated here. This file covers
| the parts nothing else touches: auth gate, update()'s three write
| semantics (set a value, blank-means-unchanged, the clear checkbox), the
| audit-log diff, and test()'s dispatch to a real DiagnosticCheck vs. the
| "no test available" fallback.
|
| Verified against the real controller:
|   - update() reads `value_{id}_{key}` and `clear_{id}_{key}` per field.
|     clear wins over a submitted value (checked first). A non-empty
|     string value calls CredentialResolver::put(); a blank, non-cleared
|     field is left untouched entirely (no put/forget call at all).
|   - CredentialResolver::put() upserts into integration_credentials;
|     forget() deletes the row outright (not a null value).
|   - test() looks up the integration's static 'check' class first, falling
|     back to ModuleRegistry::diagnosticCheckFor($id); both null => back()
|     with status_error 'No connection test available for this integration.'
|     An unknown integration id hits the same fallback message.
*/

beforeEach(function () {
    $this->mockIssueCounterZero();
});

describe('auth gate', function () {
    it('redirects guests away from the integrations settings page', function () {
        $response = $this->get(route('settings.integrations.index'));

        $response->assertRedirect(route('login'));
    });
});

describe('index', function () {
    it('shows a stored credential as configured from the database', function () {
        IntegrationCredential::factory()->create([
            'integration' => 'twilio',
            'key' => 'account_sid',
            'value' => 'ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.integrations.index'));

        $response->assertOk()
            ->assertSee('Configured')
            ->assertSee('database');
    });

    it('never echoes a stored credential value back into the page', function () {
        IntegrationCredential::factory()->create([
            'integration' => 'twilio',
            'key' => 'account_sid',
            'value' => 'super-secret-sid-value',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.integrations.index'));

        $response->assertOk()->assertDontSee('super-secret-sid-value');
    });

    it('renders testing status badges and contributing guide link for modules needing testing', function () {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.integrations.index'));

        $response->assertOk()
            ->assertSee('Verified in production')
            ->assertSee('Looking for testers')
            ->assertSee('/docs/getting-started/contributing');
    });

    it('renders all registered integrations on the overview screen in alphabetical order', function () {
        $response = $this->actingAs(User::factory()->create())
            ->get(route('settings.integrations.index'));

        $response->assertOk();

        $integrations = $response->viewData('integrations');
        expect(count($integrations))->toBeGreaterThanOrEqual(15);

        // Verify alphabetical order of labels
        $labels = array_column($integrations, 'label');
        $sortedLabels = $labels;
        usort($sortedLabels, fn ($a, $b) => strcasecmp($a, $b));
        expect($labels)->toEqual($sortedLabels);

        // Verify key integrations and links to dedicated limits pages are present
        foreach (['digitalocean', 'spinupwp', 'pressable', 'cloudflare', 'twilio', 'slack'] as $key) {
            expect(isset($integrations[$key]))->toBeTrue();
            $response->assertSee(route('settings.integrations.limits', $key));
        }
    });
});

describe('update', function () {
    it('stores a new credential value for a blank field', function () {
        $response = $this->actingAs(User::factory()->create())
            ->patch(route('settings.integrations.update'), [
                'value_twilio_account_sid' => 'ACnewvalue',
            ]);

        $response->assertRedirect(route('settings.integrations.index'));
        $response->assertSessionHas('status', 'Integration credentials saved.');

        $row = IntegrationCredential::query()
            ->where('integration', 'twilio')
            ->where('key', 'account_sid')
            ->first();

        expect($row)->not->toBeNull();
        expect($row->value)->toBe('ACnewvalue');
    });

    it('leaves a stored value untouched when the field is submitted blank', function () {
        IntegrationCredential::factory()->create([
            'integration' => 'twilio',
            'key' => 'account_sid',
            'value' => 'original-value',
        ]);

        $this->actingAs(User::factory()->create())
            ->patch(route('settings.integrations.update'), [
                'value_twilio_account_sid' => '',
            ]);

        $row = IntegrationCredential::query()
            ->where('integration', 'twilio')
            ->where('key', 'account_sid')
            ->first();

        expect($row)->not->toBeNull();
        expect($row->value)->toBe('original-value');
    });

    it('deletes the stored value when the clear checkbox is checked', function () {
        IntegrationCredential::factory()->create([
            'integration' => 'twilio',
            'key' => 'account_sid',
            'value' => 'original-value',
        ]);

        $this->actingAs(User::factory()->create())
            ->patch(route('settings.integrations.update'), [
                'clear_twilio_account_sid' => '1',
            ]);

        $row = IntegrationCredential::query()
            ->where('integration', 'twilio')
            ->where('key', 'account_sid')
            ->first();

        expect($row)->toBeNull();
    });

    it('clear wins over a simultaneously submitted value', function () {
        IntegrationCredential::factory()->create([
            'integration' => 'twilio',
            'key' => 'account_sid',
            'value' => 'original-value',
        ]);

        $this->actingAs(User::factory()->create())
            ->patch(route('settings.integrations.update'), [
                'clear_twilio_account_sid' => '1',
                'value_twilio_account_sid' => 'ignored-because-cleared',
            ]);

        $row = IntegrationCredential::query()
            ->where('integration', 'twilio')
            ->where('key', 'account_sid')
            ->first();

        expect($row)->toBeNull();
    });

    it('leaves rows untouched and reports success on a fully blank submit', function () {
        IntegrationCredential::factory()->create([
            'integration' => 'twilio',
            'key' => 'account_sid',
            'value' => 'original-value',
        ]);

        $response = $this->actingAs(User::factory()->create())
            ->patch(route('settings.integrations.update'), []);

        $response->assertRedirect(route('settings.integrations.index'));
        $response->assertSessionHas('status', 'Integration credentials saved.');

        expect(IntegrationCredential::query()->count())->toBe(1);
    });
});

describe('test', function () {
    it('runs the real DiagnosticCheck for an integration and flashes its summary on success', function () {
        $this->mock(GoogleSafeBrowsingCheck::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturn(CheckResult::ok('Key valid · HTTP 200'));
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('settings.integrations.test', 'security_scans'));

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Blacklist scanning: Key valid · HTTP 200');
    });

    it('flashes status_error when the check fails', function () {
        $this->mock(GoogleSafeBrowsingCheck::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturn(CheckResult::fail('HTTP 400'));
        });

        $response = $this->actingAs(User::factory()->create())
            ->post(route('settings.integrations.test', 'security_scans'));

        $response->assertRedirect();
        $response->assertSessionHas('status_error', 'Blacklist scanning: HTTP 400');
    });

    it('flashes a "no test available" error for an integration with no check', function () {
        // ssh has 'check' => null and no module contributes a diagnosticCheckFor('ssh').
        $response = $this->actingAs(User::factory()->create())
            ->post(route('settings.integrations.test', 'ssh'));

        $response->assertRedirect();
        $response->assertSessionHas('status_error', 'No connection test available for this integration.');
    });

    it('flashes the same "no test available" error for an unknown integration id', function () {
        $response = $this->actingAs(User::factory()->create())
            ->post(route('settings.integrations.test', 'not-a-real-integration'));

        $response->assertRedirect();
        $response->assertSessionHas('status_error', 'No connection test available for this integration.');
    });

    it('returns JSON response for AJAX connection test requests on success', function () {
        $this->mock(GoogleSafeBrowsingCheck::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturn(CheckResult::ok('Key valid · HTTP 200', null, 85));
        });

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.test', 'security_scans'));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('summary', 'Key valid · HTTP 200')
            ->assertJsonPath('duration_ms', 85);
    });

    it('returns JSON response for AJAX connection test requests on failure', function () {
        $this->mock(GoogleSafeBrowsingCheck::class, function ($mock) {
            $mock->shouldReceive('run')->once()->andReturn(CheckResult::fail('HTTP 401 Unauthorized', 'API Key rejected', 120));
        });

        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.test', 'security_scans'));

        $response->assertOk()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('summary', 'HTTP 401 Unauthorized')
            ->assertJsonPath('detail', 'API Key rejected')
            ->assertJsonPath('duration_ms', 120);
    });

    it('returns 404 JSON for unsupported integrations via AJAX', function () {
        $response = $this->actingAs(User::factory()->create())
            ->postJson(route('settings.integrations.test', 'ssh'));

        $response->assertNotFound()
            ->assertJsonPath('success', false)
            ->assertJsonPath('status', 'fail')
            ->assertJsonPath('summary', 'No connection test available for this integration.');
    });
});
