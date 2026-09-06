<?php

use App\Models\IntegrationCredential;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Support\Facades\Crypt;

/*
|--------------------------------------------------------------------------
| ReencryptSecrets — round-trips encrypted columns across a key rotation
|--------------------------------------------------------------------------
|
| All keys here are randomly generated per test run, never real APP_KEY
| values. The technique: swap config('app.key')/config('app.previous_keys')
| and rebuild the 'encrypter' singleton (container binding + the Crypt
| facade's separately-cached resolved instance -- Facade::resolveFacadeInstance
| caches by accessor name regardless of container rebinding, so both must be
| cleared) to simulate "APP_KEY rotated, old key still in APP_PREVIOUS_KEYS"
| and then "old key removed entirely". The command must leave every secret
| decryptable under ONLY the new key by the time it's done, proving the
| column was actually rewritten (not just readable via the fallback).
*/

function reencryptSecretsSetKeys(string $key, array $previousKeys = []): void
{
    config(['app.key' => $key, 'app.previous_keys' => $previousKeys]);
    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

describe('ReencryptSecrets', function () {
    beforeEach(function () {
        $this->originalKey = config('app.key');
        $this->originalPreviousKeys = config('app.previous_keys');
    });

    afterEach(function () {
        // Restore the real test-suite key so every other test file (which relies on
        // 'encrypted' casts working under .env.testing's APP_KEY) isn't left broken.
        reencryptSecretsSetKeys($this->originalKey, $this->originalPreviousKeys);
    });

    it('re-encrypts server, site, and integration-credential secrets so they decrypt under the new key with the old key no longer available', function () {
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        reencryptSecretsSetKeys($oldKey);

        $server = Server::factory()->create([
            'ssh_password' => 'fixture-old-key-ssh-password',
            'ssh_private_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nfixture-only-not-real\n-----END OPENSSH PRIVATE KEY-----",
        ]);
        $site = Site::factory()->create([
            'db_password' => 'fixture-old-key-db-password',
            'companion_secret' => 'fixture-old-key-companion-secret',
        ]);
        $credential = IntegrationCredential::factory()->create([
            'value' => 'fixture-old-key-integration-token',
        ]);

        // Rotate: new key becomes primary, old key kept as a fallback -- mirrors the
        // real APP_KEY / APP_PREVIOUS_KEYS state this command is meant to run under.
        reencryptSecretsSetKeys($newKey, [$oldKey]);

        $this->artisan('clockwork:reencrypt-secrets')->assertSuccessful();

        // Drop the old key entirely. If the command only read-through the fallback
        // without actually rewriting the columns, every assertion below would now
        // throw a DecryptException instead of returning the original plaintext.
        reencryptSecretsSetKeys($newKey);

        expect($server->fresh()->ssh_password)->toBe('fixture-old-key-ssh-password');
        expect($server->fresh()->ssh_private_key)->toBe("-----BEGIN OPENSSH PRIVATE KEY-----\nfixture-only-not-real\n-----END OPENSSH PRIVATE KEY-----");
        expect($site->fresh()->db_password)->toBe('fixture-old-key-db-password');
        expect($site->fresh()->companion_secret)->toBe('fixture-old-key-companion-secret');
        expect($credential->fresh()->value)->toBe('fixture-old-key-integration-token');
    });

    it('skips null secret columns without error and reports zero rows re-encrypted when nothing needs it', function () {
        $oldKey = 'base64:'.base64_encode(random_bytes(32));
        $newKey = 'base64:'.base64_encode(random_bytes(32));

        reencryptSecretsSetKeys($oldKey);

        // Server/Site factories don't set ssh_password/ssh_private_key/db_password/
        // companion_secret by default, so every target column is NULL here.
        Server::factory()->create();
        Site::factory()->create();

        reencryptSecretsSetKeys($newKey, [$oldKey]);

        $this->artisan('clockwork:reencrypt-secrets')
            ->expectsOutputToContain('servers.ssh_password: re-encrypted 0 row(s).')
            ->expectsOutputToContain('sites.db_password: re-encrypted 0 row(s).')
            ->expectsOutputToContain('integration_credentials.value: re-encrypted 0 row(s).')
            ->assertSuccessful();
    });
});
