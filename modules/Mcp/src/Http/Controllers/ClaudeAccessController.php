<?php

namespace Modules\Mcp\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActionLog\ActionLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Core\ModuleRegistry;
use Modules\Mcp\Support\ClaudeConnector;
use Modules\Mcp\Tools\ControlWhoAmI;
use Throwable;

/**
 * /settings/claude-access: the switch, the one-time setup, the details to
 * paste into Claude, and who is connected.
 *
 * Everyone can open it and sees their own connection. The switch, setup,
 * other people's connections and "Disconnect everyone" are for admins.
 */
class ClaudeAccessController extends Controller
{
    public const LOG_ENABLED = 'claude_access_enabled';

    public const LOG_DISABLED = 'claude_access_disabled';

    public const LOG_SETUP = 'claude_access_setup';

    public const LOG_SECRET_ROTATED = 'claude_access_secret_rotated';

    public const LOG_REVOKED = 'claude_access_revoked';

    public function __construct(
        private readonly ClaudeConnector $connector,
        private readonly ActionLogger $logger,
    ) {}

    public function index(Request $request, ModuleRegistry $registry): Response
    {
        /** @var User $user */
        $user = $request->user();
        $isAdmin = $user->isAdmin();
        $client = $this->connector->client();

        $tools = array_map(function (string $class) {
            $tool = app($class);

            return ['name' => $tool->name(), 'title' => $tool->title(), 'writes' => ! ($tool->toArray()['annotations']['readOnlyHint'] ?? false)];
        }, [ControlWhoAmI::class, ...$registry->mcpTools()]);

        // no-store: the page can show the client secret once, and a browser
        // must not keep a copy of that page.
        return response()->view('claude-access::settings', [
            'isAdmin' => $isAdmin,
            'enabled' => $this->connector->enabled(),
            'keysExist' => $this->connector->keysExist(),
            'client' => $client,
            'ready' => $this->connector->ready(),
            'endpointUrl' => $this->connector->endpointUrl(),
            'isHttps' => str_starts_with($this->connector->endpointUrl(), 'https://'),
            'authorizations' => $this->connector->authorizations($isAdmin ? null : $user->id),
            'localUser' => config('clockwork.mcp.local_user'),
            'tools' => $tools,
            // Present only on the request right after setup or a rotation.
            'newSecret' => session('claude_access_secret'),
        ])->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request): RedirectResponse
    {
        $enable = $request->boolean('enabled');

        if ($enable && ! $this->connector->ready()) {
            return back()->with('warning', 'Run the one-time setup first. Without the signing keys and the Claude client, the endpoint would only return errors.');
        }

        if ($enable === $this->connector->enabled()) {
            return back();
        }

        $this->connector->setEnabled($enable);

        $this->log(
            $request,
            $enable ? self::LOG_ENABLED : self::LOG_DISABLED,
            $enable ? 'Turned Claude access on.' : 'Turned Claude access off.',
        );

        // Turning it off closes the door but keeps the keys people hold, so
        // a short pause doesn't make everyone sign in again. "Disconnect
        // everyone" is the separate, deliberate step.
        return back()->with('status', $enable
            ? 'Claude access is on. People can now connect Claude to Control.'
            : 'Claude access is off. Nobody can reach Control from Claude until it is turned on again.');
    }

    public function setup(Request $request): RedirectResponse
    {
        try {
            $keysCreated = $this->connector->ensureKeys();
            $result = $this->connector->ensureClient();
        } catch (Throwable $e) {
            return back()->with('warning', 'Setup did not finish: '.$e->getMessage());
        }

        if (! $keysCreated && ! $result['created']) {
            return back()->with('status', 'Setup was already done. Nothing changed.');
        }

        $this->log($request, self::LOG_SETUP, 'Set up Claude access'
            .($keysCreated ? ' (signing keys created)' : '')
            .($result['created'] ? ' (Claude client created)' : '').'.');

        $redirect = back()->with('status', 'Setup is done.');

        // The only moment the secret exists in readable form.
        return $result['created']
            ? $redirect->with('claude_access_secret', (string) $result['client']->plainSecret)
            : $redirect;
    }

    public function rotateSecret(Request $request): RedirectResponse
    {
        try {
            $secret = $this->connector->rotateSecret();
        } catch (Throwable $e) {
            return back()->with('warning', $e->getMessage());
        }

        $this->log($request, self::LOG_SECRET_ROTATED, 'Issued a new Claude client secret; the old one no longer works.');

        return back()
            ->with('status', 'New client secret issued. The old one no longer works, so update the connector in Claude.')
            ->with('claude_access_secret', $secret);
    }

    public function revoke(Request $request, User $user, string $client): RedirectResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        if (! $actor->isAdmin() && $actor->id !== $user->id) {
            abort(403, 'You can only disconnect your own Claude.');
        }

        $revoked = $this->connector->revoke($user, $client);

        if ($revoked > 0) {
            $this->log($request, self::LOG_REVOKED, "Disconnected Claude for {$user->email}.", $user->email);
        }

        return back()->with('status', $actor->id === $user->id
            ? 'Your Claude was disconnected. Connect again from Claude whenever you like.'
            : "Claude was disconnected for {$user->name}.");
    }

    public function revokeAll(Request $request): RedirectResponse
    {
        $revoked = $this->connector->revokeAll();

        $this->log($request, self::LOG_REVOKED, "Disconnected Claude for everyone ({$revoked} token(s) revoked).");

        return back()->with('status', 'Everyone was disconnected. Each person signs in again the next time they use Claude with Control.');
    }

    private function log(Request $request, string $type, string $summary, ?string $target = null): void
    {
        $this->logger->record(
            actionType: $type,
            summary: $summary,
            target: $target,
            actor: (string) ($request->user()?->email ?? 'manual'),
        );
    }
}
