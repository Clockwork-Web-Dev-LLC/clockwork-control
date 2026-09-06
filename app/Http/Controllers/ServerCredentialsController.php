<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Tag;
use App\Services\Ssh\CredentialFeedParser;
use App\Services\Ssh\SshClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServerCredentialsController extends Controller
{
    public function bulk(): View
    {
        $servers = Server::query()
            ->where('is_ignored', false)
            ->orderBy('name')
            ->get();

        return view('dashboard.credentials-bulk', compact('servers'));
    }

    public function bulkUpdate(Request $request, SshClient $ssh): RedirectResponse
    {
        $validated = $request->validate([
            'ssh_user' => ['nullable', 'string', 'max:255'],
            'passwords' => ['array'],
            'passwords.*' => ['nullable', 'string'],
        ]);

        // Use ?? not ?: — Laravel's nullable validator omits missing keys from
        // $validated entirely, which makes ?: throw "Undefined array key".
        $defaultUser = ($validated['ssh_user'] ?? null) ?: (string) config('clockwork.ssh.default_user');

        $updated = 0;
        $verified = 0;
        $failed = 0;
        foreach ($validated['passwords'] ?? [] as $serverId => $password) {
            if ($password === null || $password === '') {
                continue;
            }

            $server = Server::find((int) $serverId);
            if (! $server) {
                continue;
            }

            $server->ssh_password = $password;
            if ($server->ssh_user === '' || $server->ssh_user === null) {
                $server->ssh_user = $defaultUser;
            }
            $server->save();
            $updated++;

            $result = $ssh->test($server->fresh());
            if (! empty($result['ok'])) {
                $verified++;
            } else {
                $failed++;
            }
        }

        $msg = "Updated SSH credentials on {$updated} server(s). Verified {$verified}";
        if ($failed > 0) {
            $msg .= ", {$failed} failed verification";
        }
        $msg .= '.';

        return redirect()
            ->route('servers.credentials.bulk')
            ->with('status', $msg);
    }

    public function edit(Server $server): View
    {
        $server->load('tags');
        $allTags = Tag::orderBy('sort_order')->orderBy('name')->get();

        return view('dashboard.credentials-edit', compact('server', 'allTags'));
    }

    public function update(Request $request, Server $server, SshClient $ssh): RedirectResponse
    {
        $validated = $request->validate([
            'ssh_user' => ['required', 'string', 'max:255'],
            'ssh_port' => ['required', 'integer', 'min:1', 'max:65535'],
            'ssh_password' => ['nullable', 'string'],
            'clear_password' => ['nullable', 'boolean'],
        ]);

        $credentialsChanged = false;
        $server->ssh_user = $validated['ssh_user'];
        $server->ssh_port = $validated['ssh_port'];

        if (! empty($validated['clear_password'])) {
            $server->ssh_password = null;
        } elseif (! empty($validated['ssh_password'])) {
            $server->ssh_password = $validated['ssh_password'];
            $credentialsChanged = true;
        }

        if ($server->isDirty(['ssh_user', 'ssh_port'])) {
            $credentialsChanged = true;
        }

        $server->save();

        $message = 'Credentials updated.';
        if ($credentialsChanged && $server->ssh_password) {
            $result = $ssh->test($server->fresh());
            $message .= ' '.($result['ok']
                ? 'SSH verified.'
                : 'SSH test failed: '.($result['message'] ?? 'unknown error'));
        }

        return redirect()
            ->route('servers.show', $server)
            ->with('status', $message);
    }

    public function test(Server $server, SshClient $ssh): JsonResponse
    {
        $result = $ssh->test($server);

        return response()->json($result);
    }

    public function feed(): View
    {
        return view('dashboard.credentials-feed', [
            'entries' => null,
            'feed' => '',
        ]);
    }

    public function feedParse(Request $request, CredentialFeedParser $parser): View
    {
        $validated = $request->validate([
            'feed' => ['required', 'string'],
        ]);

        $parsed = $parser->parse($validated['feed']);

        $servers = Server::query()
            ->get(['id', 'name', 'hostname', 'ssh_user', 'ssh_password', 'is_ignored']);

        $byName = $servers->keyBy(fn ($s) => strtolower($s->name));
        $byHostname = $servers->groupBy(fn ($s) => strtolower($s->hostname));

        $entries = collect($parsed)->map(function (array $entry) use ($byName, $byHostname) {
            $byNameMatch = $byName->get(strtolower($entry['name']));
            $byHostMatches = $byHostname->get(strtolower($entry['ip']), collect());

            $match = $byNameMatch ?? ($byHostMatches->count() === 1 ? $byHostMatches->first() : null);

            $status = match (true) {
                $byNameMatch && $byHostMatches->isNotEmpty() && ! $byHostMatches->contains('id', $byNameMatch->id) => 'name_ip_conflict',
                $match !== null => 'matched',
                $byHostMatches->count() > 1 => 'ambiguous',
                default => 'unmatched',
            };

            return [
                'name' => $entry['name'],
                'ip' => $entry['ip'],
                'user' => $entry['user'],
                'port' => $entry['port'],
                'password' => $entry['password'],
                'server_id' => $match?->id,
                'matched_name' => $match?->name,
                'matched_hostname' => $match?->hostname,
                'matched_user' => $match?->ssh_user,
                'has_password_already' => (bool) $match?->ssh_password,
                'status' => $status,
                'user_mismatch' => $match && $match->ssh_user !== $entry['user'],
                'ignored' => (bool) $match?->is_ignored,
            ];
        })->all();

        return view('dashboard.credentials-feed', [
            'entries' => $entries,
            'feed' => $validated['feed'],
        ]);
    }

    public function feedApply(Request $request, SshClient $ssh): RedirectResponse
    {
        $entries = $request->input('entries', []);

        $applied = 0;
        $created = 0;
        $skipped = 0;
        $userUpdates = 0;
        $verified = 0;
        $failed = 0;

        foreach ($entries as $row) {
            if (empty($row['apply'])) {
                $skipped++;

                continue;
            }

            // Branch: existing server (server_id set) → update creds.
            // Otherwise (status was unmatched) → create a new Server row.
            if (! empty($row['server_id'])) {
                $server = Server::find((int) $row['server_id']);
                if (! $server) {
                    $skipped++;

                    continue;
                }

                if (! empty($row['password'])) {
                    $server->ssh_password = $row['password'];
                }

                if (! empty($row['user']) && ! empty($row['update_user']) && $row['user'] !== $server->ssh_user) {
                    $server->ssh_user = $row['user'];
                    $userUpdates++;
                }

                if (! empty($row['port']) && (int) $row['port'] !== (int) $server->ssh_port) {
                    $server->ssh_port = (int) $row['port'];
                }

                $server->save();
                $applied++;
            } else {
                // Defensive checks before creating — feed was supposed to validate
                // these upstream but a hand-crafted POST could skip server_id without
                // having all the create-required fields.
                if (empty($row['name']) || empty($row['ip']) || empty($row['user'])) {
                    $skipped++;

                    continue;
                }

                // Skip if a server with this name OR IP showed up between parse and
                // apply (race avoidance). Operator can re-paste.
                $existing = Server::query()
                    ->where(function ($q) use ($row) {
                        $q->where('name', $row['name'])->orWhere('hostname', $row['ip']);
                    })
                    ->first();
                if ($existing) {
                    $skipped++;

                    continue;
                }

                $server = Server::create([
                    'name' => $row['name'],
                    'hostname' => $row['ip'],
                    'ssh_user' => $row['user'],
                    'ssh_port' => (int) ($row['port'] ?? 22),
                    'ssh_password' => $row['password'] ?? '',
                    'status' => Server::STATUS_UNKNOWN,
                    'is_ignored' => false,
                ]);
                $created++;
            }

            $result = $ssh->test($server->fresh());
            if (! empty($result['ok'])) {
                $verified++;
            } else {
                $failed++;
            }
        }

        $parts = [];
        if ($applied > 0) {
            $parts[] = "Updated credentials on {$applied} server(s)";
        }
        if ($created > 0) {
            $parts[] = "Created {$created} new server(s)";
        }
        if ($parts === []) {
            $parts[] = 'No changes';
        }
        $msg = implode('. ', $parts).'.';

        $msg .= " Verified {$verified}";
        if ($failed > 0) {
            $msg .= ", {$failed} failed";
        }
        $msg .= '.';
        if ($userUpdates > 0) {
            $msg .= " Updated SSH user on {$userUpdates}.";
        }
        if ($skipped > 0) {
            $msg .= " Skipped {$skipped}.";
        }

        return redirect()
            ->route('servers.credentials.bulk')
            ->with('status', $msg);
    }
}
