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
    {{-- Exploit Shield Status Banner (Only visible if Exploit Shield module is active) --}}
    @if ($exploitShieldActive ?? false)
        @if (! ($exploitShieldGloballyEnabled ?? false))
            <div class="card p-4 mb-5 border-l-4 border-l-[var(--color-ink-muted)] bg-[var(--color-surface-alt)]/40 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-[var(--color-ink-soft)]/20 text-[var(--color-ink-soft)] flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-shield-slash"></i>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-[var(--color-ink-strong)]">Exploit Shield is Globally Inactive</div>
                        <div class="text-[11px] text-[var(--color-ink-muted)]">Honeypot monitoring and origin exploit defense are disabled system-wide.</div>
                    </div>
                </div>
                <a href="{{ route('settings.exploit-shield.index') }}" class="btn-pill-nav text-xs">
                    Configure Exploit Shield
                </a>
            </div>
        @elseif (! ($exploitShieldServerEnabled ?? false))
            <div class="card p-4 mb-5 border-l-4 border-l-[var(--color-status-yellow)] bg-[var(--color-surface-alt)]/40 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-[var(--color-status-yellow)]/20 text-[var(--color-status-yellow)] flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-shield"></i>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-[var(--color-ink-strong)]">Exploit Shield Disabled on {{ $server->name }}</div>
                        <div class="text-[11px] text-[var(--color-ink-muted)]">Exploit Shield is active fleet-wide, but turned off for this specific server. Malicious probes targeting sites here will not be blocked.</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('settings.exploit-shield.servers.toggle', $server) }}">
                    @csrf
                    <input type="hidden" name="enabled" value="1">
                    <button type="submit" class="btn-pill-primary text-xs">
                        <i class="fa-solid fa-shield-check mr-1"></i> Enable on this Server
                    </button>
                </form>
            </div>
        @else
            <div class="card p-4 mb-5 border-l-4 border-l-[var(--color-status-green)] bg-[var(--color-surface-alt)]/40 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-8 h-8 rounded-full bg-[var(--color-status-green)]/20 text-[var(--color-status-green)] flex items-center justify-center shrink-0">
                        <i class="fa-solid fa-shield-check"></i>
                    </div>
                    <div>
                        <div class="text-xs font-semibold text-[var(--color-ink-strong)]">Exploit Shield Active on {{ $server->name }}</div>
                        <div class="text-[11px] text-[var(--color-ink-muted)]">Honeypot probe defense is live. Attackers scanning for sensitive files (.env, web shells) are automatically blocked.</div>
                    </div>
                </div>
                <form method="POST" action="{{ route('settings.exploit-shield.servers.toggle', $server) }}" onsubmit="return confirm('Turn off Exploit Shield on {{ $server->name }}?');">
                    @csrf
                    <input type="hidden" name="enabled" value="0">
                    <button type="submit" class="btn-pill-nav text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-status-red)]">
                        <i class="fa-solid fa-power-off mr-1"></i> Turn Off for this Server
                    </button>
                </form>
            </div>
        @endif
    @endif

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
                            <th class="text-left px-5 py-2">Reason / Malicious Probe</th>
                            <th class="text-left px-5 py-2">Banned</th>
                            <th class="text-left px-5 py-2">Expires</th>
                            <th class="px-5 py-2 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($bannedIps as $ban)
                            <tr class="hover:bg-[var(--color-surface-alt)]/40 transition-colors">
                                <td class="px-5 py-2 font-mono">
                                    @if (($exploitShieldActive ?? false) && ($exploitShieldGloballyEnabled ?? false))
                                        <button type="button"
                                                @click="$dispatch('open-ip-inspector', { ip: '{{ $ban->ip }}' })"
                                                class="hover:text-[var(--color-brand)] inline-flex items-center gap-1.5 focus:outline-none"
                                                title="Inspect geolocation and threat details for {{ $ban->ip }}">
                                            <span>{{ $ban->ip }}</span>
                                            <i class="fa-solid fa-circle-info text-[10px] text-[var(--color-ink-soft)]"></i>
                                        </button>
                                    @else
                                        <x-ip-link :ip="$ban->ip" />
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-xs">
                                    @if ($ban->source === 'exploit_shield')
                                        <span class="status-pill status-red text-[10px]">
                                            <i class="fa-solid fa-shield-halved text-[9px]"></i> Exploit Shield
                                        </span>
                                    @else
                                        <span class="text-[var(--color-ink-muted)]">{{ $ban->source }}</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] truncate max-w-md" title="{{ $ban->reason }}">
                                    {{ $ban->reason }}
                                </td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)] font-data">
                                    {{ $ban->banned_at?->diffForHumans() }}
                                </td>
                                <td class="px-5 py-2 text-xs font-data">
                                    @if ($ban->expires_at)
                                        <span class="text-[var(--color-status-yellow)]" title="{{ $ban->expires_at }}">
                                            in {{ $ban->expires_at->diffForHumans(null, true) }}
                                        </span>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">Permanent</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-right">
                                    <div class="inline-flex items-center gap-2 justify-end">
                                        @if (($exploitShieldActive ?? false) && ($exploitShieldGloballyEnabled ?? false))
                                            <button type="button"
                                                    @click="$dispatch('open-ip-inspector', { ip: '{{ $ban->ip }}' })"
                                                    class="btn-pill-nav text-xs px-2 py-0.5"
                                                    title="Inspect estimated geolocation">
                                                <i class="fa-solid fa-location-dot text-[10px]"></i> Geo
                                            </button>
                                        @endif
                                        <form method="POST" action="{{ route('blocked-ips.unban', $ban) }}" class="inline">
                                            @csrf
                                            <button type="submit" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-status-green)]">
                                                <i class="fa-solid fa-rotate-left"></i> Unban
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Exploit Shield IP Inspector Modal (Only rendered when Exploit Shield is enabled) --}}
    @if (($exploitShieldActive ?? false) && ($exploitShieldGloballyEnabled ?? false))
        <x-exploit-shield-ip-inspector />
    @endif
@endif
