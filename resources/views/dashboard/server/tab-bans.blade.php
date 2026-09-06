@if (! $server->clockwork_jail_provisioned_at)
    <div class="card p-6 text-center text-[var(--color-ink-muted)]">
        <i class="fa-solid fa-shield-halved text-2xl text-[var(--color-ink-soft)] mb-2"></i>
        <div class="font-medium text-[var(--color-ink-strong)] mb-1">fail2ban not provisioned</div>
        <div class="text-sm">Bans are issued via fail2ban over SSH. Provision the clockwork jail first to enable banning on this server.</div>
        <a href="{{ route('servers.show', ['server' => $server, 'tab' => 'settings']) }}" class="btn-pill-nav mt-3">
            <i class="fa-solid fa-arrow-right"></i> Go to Settings
        </a>
    </div>
@else
    <div class="card p-5">
        <div class="flex items-center justify-between mb-4">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">Manual ban</h2>
            <span class="text-xs text-[var(--color-ink-soft)]">{{ $bannedIps->count() }} active {{ Str::plural('ban', $bannedIps->count()) }}</span>
        </div>

        @if (session('ban_status'))
            <div class="status-pill status-green mb-3">
                <i class="fa-solid fa-circle-check"></i> {{ session('ban_status') }}
            </div>
        @endif
        @if (session('ban_error'))
            <div class="card p-3 mb-3 status-red text-sm">
                <i class="fa-solid fa-circle-xmark"></i> {{ session('ban_error') }}
            </div>
        @endif

        <form method="POST" action="{{ route('servers.ban', $server) }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <label class="flex-1 min-w-[12rem]">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">IP address</span>
                <input type="text" name="ip" value="{{ old('ip') }}" required placeholder="1.2.3.4 or 2001:db8::1"
                       class="mt-1 w-full font-data text-sm border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                @error('ip') <span class="text-xs text-[var(--color-status-red)] mt-1 block">{{ $message }}</span> @enderror
            </label>
            <label class="flex-[2] min-w-[16rem]">
                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Reason (optional)</span>
                <input type="text" name="reason" value="{{ old('reason') }}" placeholder="e.g. brute-force on /wp-login.php"
                       class="mt-1 w-full text-sm border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
            </label>
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-ban"></i> Ban
            </button>
        </form>

        @if ($bannedIps->isNotEmpty())
            <div class="mt-5 border-t border-[var(--color-border-light)] -mx-5 -mb-5">
                <table class="w-full text-sm">
                    <thead class="text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                        <tr>
                            <th class="text-left px-5 py-2">IP</th>
                            <th class="text-left px-5 py-2">Source</th>
                            <th class="text-left px-5 py-2">Reason</th>
                            <th class="text-left px-5 py-2">Banned</th>
                            <th class="px-5 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($bannedIps as $ban)
                            <tr>
                                <td class="px-5 py-2"><x-ip-link :ip="$ban->ip" /></td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $ban->source }}</td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] truncate max-w-md">{{ $ban->reason }}</td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">{{ $ban->banned_at?->diffForHumans() }}</td>
                                <td class="px-5 py-2 text-right">
                                    <form method="POST" action="{{ route('blocked-ips.unban', $ban) }}" class="inline">
                                        @csrf
                                        <button type="submit" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-status-green)]">
                                            <i class="fa-solid fa-rotate-left"></i> Unban
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif
