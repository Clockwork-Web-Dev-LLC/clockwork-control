<?php

namespace App\Http\Controllers;

use App\Models\ActionLog;
use App\Models\BlockedIp;
use App\Models\Server;
use App\Services\ActionLog\ActionLogger;
use App\Services\Chat\ChatNotifier;
use App\Services\Fail2ban\Fail2banClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class BlockedIpsController extends Controller
{
    /**
     * Assemble the data array used by the Bans → Active tab. Returns an array
     * (NOT a View) — BansController is the only renderer; the legacy
     * /blocked-ips GET URL is now a 301 redirect closure in routes/web.php.
     *
     * @return array<string, mixed>
     */
    public function assembleData(Request $request): array
    {
        $q = trim((string) $request->query('q', ''));

        $active = BlockedIp::query()
            ->with(['server', 'site'])
            ->whereNull('unbanned_at')
            ->when($q !== '', function ($query) use ($q) {
                $like = '%'.$q.'%';
                $query->where(function ($w) use ($like) {
                    $w->where('ip', 'like', $like)
                        ->orWhere('reason', 'like', $like)
                        ->orWhereHas('server', fn ($s) => $s->where('name', 'like', $like));
                });
            })
            ->orderByDesc('banned_at')
            ->paginate(50)
            ->withQueryString();

        $recent = BlockedIp::query()
            ->with(['server', 'site'])
            ->whereNotNull('unbanned_at')
            ->orderByDesc('unbanned_at')
            ->limit(20)
            ->get();

        return [
            'active' => $active,
            'recent' => $recent,
            'q' => $q,
        ];
    }

    public function ban(Request $request, Server $server, Fail2banClient $client, ChatNotifier $chat, ActionLogger $logger): RedirectResponse
    {
        $validated = $request->validate([
            'ip' => ['required', 'string', 'ip'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $client->banIp($server, $validated['ip']);

        if (! $result['ok']) {
            $logger->record(
                actionType: ActionLog::TYPE_MANUAL_BAN,
                summary: "Manual ban failed for {$validated['ip']} on {$server->name}.",
                server: $server,
                target: $validated['ip'],
                ok: false,
                error: $result['message'].' — '.$result['output'],
            );

            return back()->withInput()->with('status_error', $result['message'].' — '.$result['output']);
        }

        $blocked = BlockedIp::create([
            'ip' => $validated['ip'],
            'server_id' => $server->id,
            'site_id' => null,
            'source' => BlockedIp::SOURCE_MANUAL,
            'reason' => $validated['reason'] ?: 'Manual ban from dashboard',
            'llm_verdict' => null,
            'llm_reasoning' => null,
            'decision' => BlockedIp::DECISION_APPROVED,
            'decided_by' => 'manual',
            'banned_at' => Carbon::now(),
            'expires_at' => null,
            'unbanned_at' => null,
        ]);

        $chat->ipBlocked($blocked);

        $logger->record(
            actionType: ActionLog::TYPE_MANUAL_BAN,
            summary: "Banned {$validated['ip']} on {$server->name}.",
            server: $server,
            target: $validated['ip'],
            details: ['reason' => $validated['reason'] ?? null],
        );

        return back()->with('status', "Banned {$validated['ip']} on {$server->name}.");
    }

    public function unban(BlockedIp $blockedIp, Fail2banClient $client, ActionLogger $logger): RedirectResponse
    {
        if (! $blockedIp->server) {
            return back()->with('status_error', 'Cannot unban — server record missing.');
        }

        $result = $client->unbanIp($blockedIp->server, $blockedIp->ip);

        if (! $result['ok']) {
            $logger->record(
                actionType: ActionLog::TYPE_MANUAL_UNBAN,
                summary: "Unban failed for {$blockedIp->ip} on {$blockedIp->server->name}.",
                site: $blockedIp->site,
                server: $blockedIp->server,
                target: $blockedIp->ip,
                ok: false,
                error: $result['message'].' — '.$result['output'],
            );

            return back()->with('status_error', $result['message'].' — '.$result['output']);
        }

        $blockedIp->update(['unbanned_at' => Carbon::now()]);

        $logger->record(
            actionType: ActionLog::TYPE_MANUAL_UNBAN,
            summary: "Unbanned {$blockedIp->ip} on {$blockedIp->server->name}.",
            site: $blockedIp->site,
            server: $blockedIp->server,
            target: $blockedIp->ip,
        );

        return back()->with('status', "Unbanned {$blockedIp->ip}.");
    }
}
