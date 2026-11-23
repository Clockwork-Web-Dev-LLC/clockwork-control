<?php

namespace Modules\Mcp\Support;

use App\Models\User;
use App\Support\Settings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use RuntimeException;
use Throwable;

/**
 * Everything behind the Claude access settings page and the
 * clockwork:mcp-setup command: the on/off switch, the signing keys, the one
 * OAuth client hosted Claude signs in through, and who is connected.
 *
 * There is exactly one client, created here and never through a public
 * registration endpoint, so a stranger can't mint one.
 */
class ClaudeConnector
{
    public const SETTING_ENABLED = 'mcp.enabled';

    public const CLIENT_NAME = 'Claude';

    /** Where hosted Claude (claude.ai, Cowork, synced Claude Code connectors) sends people back to. */
    public const REDIRECT_URIS = [
        'https://claude.ai/api/mcp/auth_callback',
        'https://claude.com/api/mcp/auth_callback',
    ];

    public function __construct(
        private readonly Settings $settings,
        private readonly ClientRepository $clients,
    ) {}

    /** The switch an admin flips. Routes exist only while it is on. */
    public function enabled(): bool
    {
        return (bool) $this->settings->get(self::SETTING_ENABLED, false);
    }

    public function setEnabled(bool $enabled): void
    {
        $this->settings->put(self::SETTING_ENABLED, $enabled);
    }

    /** The address to give Claude when adding the connector. */
    public function endpointUrl(): string
    {
        return url('/mcp');
    }

    public function keysExist(): bool
    {
        if (filled(config('passport.private_key')) && filled(config('passport.public_key'))) {
            return true;
        }

        return is_file(Passport::keyPath('oauth-private.key')) && is_file(Passport::keyPath('oauth-public.key'));
    }

    /**
     * Create the token signing keys if there are none.
     *
     * @return bool whether keys were created
     *
     * @throws RuntimeException when only one of the two key files exists
     */
    public function ensureKeys(): bool
    {
        if ($this->keysExist()) {
            return false;
        }

        $private = is_file(Passport::keyPath('oauth-private.key'));
        $public = is_file(Passport::keyPath('oauth-public.key'));
        if ($private !== $public) {
            throw new RuntimeException('Only one of storage/oauth-private.key and storage/oauth-public.key exists. Remove it, or restore the other, then try again.');
        }

        // Written here, not through "php artisan passport:keys": Passport
        // only registers that command on the command line, so it doesn't
        // exist when setup is run from the settings page.
        $key = openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false || ! openssl_pkey_export($key, $privatePem)) {
            throw new RuntimeException('Could not generate the signing keys on this server (OpenSSL failed).');
        }
        $publicPem = openssl_pkey_get_details($key)['key'] ?? null;

        $privatePath = Passport::keyPath('oauth-private.key');
        $publicPath = Passport::keyPath('oauth-public.key');

        // Create the private key file closed to everyone else before the
        // key goes into it.
        if (! is_string($publicPem)
            || @touch($privatePath) === false
            || @chmod($privatePath, 0600) === false
            || @file_put_contents($privatePath, $privatePem) === false
            || @file_put_contents($publicPath, $publicPem) === false) {
            @unlink($privatePath);
            @unlink($publicPath);

            throw new RuntimeException('Could not write the signing keys to storage/. Check that the folder is writable.');
        }
        @chmod($publicPath, 0660);

        return true;
    }

    public function client(): ?Client
    {
        if (! $this->tablesExist()) {
            return null;
        }

        return Passport::client()->newQuery()
            ->where('name', self::CLIENT_NAME)
            ->where('revoked', false)
            ->oldest()
            ->first();
    }

    /**
     * Create the client if it doesn't exist. The plain secret is readable
     * from the returned client only when it was just created: Passport
     * stores it hashed.
     *
     * @return array{client: Client, created: bool}
     */
    public function ensureClient(): array
    {
        $existing = $this->client();
        if ($existing !== null) {
            return ['client' => $existing, 'created' => false];
        }

        $client = $this->clients->createAuthorizationCodeGrantClient(
            name: self::CLIENT_NAME,
            redirectUris: self::REDIRECT_URIS,
            confidential: true,
        );

        return ['client' => $client, 'created' => true];
    }

    /**
     * Issue a new secret. The old one stops working at once, so Claude's
     * connector must be given the new one. People already connected stay
     * connected until their refresh token is next used.
     *
     * @return string the new plain secret, shown once
     */
    public function rotateSecret(): string
    {
        $client = $this->client();
        if ($client === null) {
            throw new RuntimeException('There is no Claude client yet. Run setup first.');
        }

        $this->clients->regenerateSecret($client);

        return (string) $client->plainSecret;
    }

    /** Whether the endpoint could work if switched on. */
    public function ready(): bool
    {
        return $this->keysExist() && $this->client() !== null;
    }

    /**
     * Who is connected: one row per person and client, not per token (every
     * refresh makes a new token row).
     *
     * @param  ?int  $onlyUserId  limit to one person
     * @return list<array{user_id: int, user_name: ?string, user_email: ?string, client_id: string, client_name: ?string, connected_at: ?string, last_token_at: ?string}>
     */
    public function authorizations(?int $onlyUserId = null): array
    {
        if (! $this->tablesExist()) {
            return [];
        }

        $rows = DB::table('oauth_access_tokens as t')
            ->join('oauth_refresh_tokens as r', 'r.access_token_id', '=', 't.id')
            ->leftJoin('oauth_clients as c', 'c.id', '=', 't.client_id')
            ->leftJoin('users as u', 'u.id', '=', 't.user_id')
            // Still usable: the refresh token is what keeps a connection
            // alive after the hour-long access token runs out.
            ->where('r.revoked', false)
            ->where('r.expires_at', '>', now())
            ->whereNotNull('t.user_id')
            ->when($onlyUserId !== null, fn ($query) => $query->where('t.user_id', $onlyUserId))
            ->groupBy('t.user_id', 't.client_id', 'u.name', 'u.email', 'c.name')
            ->orderBy('u.name')
            ->selectRaw('t.user_id, t.client_id, u.name as user_name, u.email as user_email, c.name as client_name, MIN(t.created_at) as connected_at, MAX(t.created_at) as last_token_at')
            ->get();

        return $rows->map(fn (object $row) => [
            'user_id' => (int) $row->user_id,
            'user_name' => $row->user_name,
            'user_email' => $row->user_email,
            'client_id' => (string) $row->client_id,
            'client_name' => $row->client_name,
            'connected_at' => $row->connected_at,
            'last_token_at' => $row->last_token_at,
        ])->all();
    }

    /** Disconnect one person from one client. */
    public function revoke(User $user, string $clientId): int
    {
        return $user->revokeClaudeAccess($clientId);
    }

    /** Disconnect everyone. */
    public function revokeAll(): int
    {
        if (! $this->tablesExist()) {
            return 0;
        }

        Passport::refreshToken()->newQuery()->where('revoked', false)->update(['revoked' => true]);

        return Passport::token()->newQuery()->where('revoked', false)->update(['revoked' => true]);
    }

    private function tablesExist(): bool
    {
        try {
            return Schema::hasTable('oauth_clients');
        } catch (Throwable) {
            return false;
        }
    }
}
