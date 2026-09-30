<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Models\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    /**
     * Unified global search across servers and sites for the command palette.
     */
    public function global(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([
                'servers' => [],
                'sites' => [],
            ]);
        }

        $servers = Server::query()
            ->where(function ($query) use ($q) {
                $query->where('name', 'like', "%{$q}%")
                    ->orWhere('hostname', 'like', "%{$q}%");
            })
            ->limit(6)
            ->get(['id', 'name', 'hostname', 'status', 'provider'])
            ->map(fn (Server $s) => [
                'id' => 'server-'.$s->id,
                'section' => 'Servers',
                'label' => $s->display_name ?: $s->name,
                'sublabel' => $s->hostname.' · '.ucfirst($s->provider),
                'icon' => match ($s->status) {
                    'green' => 'fa-solid fa-server text-[var(--color-status-green)]',
                    'yellow' => 'fa-solid fa-server text-[var(--color-status-yellow)]',
                    default => 'fa-solid fa-server text-rose-500',
                },
                'url' => route('servers.show', $s),
            ]);

        $sites = Site::notArchived()
            ->with('server:id,name')
            ->where('domain', 'like', "%{$q}%")
            ->orderByRaw('CASE WHEN domain LIKE ? THEN 0 ELSE 1 END', [$q.'%'])
            ->orderBy('domain')
            ->limit(10)
            ->get(['id', 'domain', 'server_id', 'hosting_provider', 'is_wordpress'])
            ->map(fn (Site $s) => [
                'id' => 'site-'.$s->id,
                'section' => 'Sites',
                'label' => $s->domain,
                'sublabel' => $s->server?->display_name ?: $s->server?->name ?: ucfirst($s->hosting_provider),
                'icon' => 'fa-solid fa-globe text-[var(--color-brand)]',
                'url' => route('sites.show', $s),
            ]);

        return response()->json([
            'servers' => $servers,
            'sites' => $sites,
        ]);
    }
}
