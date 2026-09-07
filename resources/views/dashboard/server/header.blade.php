@php
    use App\Models\Server;
    use App\Services\CloudProvider\CloudProviderRegistry;

    $statusMeta = [
        Server::STATUS_GREEN => ['label' => 'Healthy', 'class' => 'status-green'],
        Server::STATUS_YELLOW => ['label' => 'Watch', 'class' => 'status-yellow'],
        Server::STATUS_RED => ['label' => 'Alert', 'class' => 'status-red'],
        Server::STATUS_UNKNOWN => ['label' => 'Unknown', 'class' => 'status-unknown'],
    ];
    $meta = $statusMeta[$server->status] ?? $statusMeta[Server::STATUS_UNKNOWN];

    $tier = app(CloudProviderRegistry::class)->resolve($server->provider)->sizeTier($server->size_slug);
    $hasSpecs = $server->vcpus || $server->memory_mb || $server->disk_gb;
@endphp

<div class="mb-6">
    <a href="{{ route('dashboard') }}" class="inline-flex items-center gap-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink)] mb-6">
        <i class="fa-solid fa-arrow-left"></i>
        All servers
    </a>

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i>
            {{ session('status') }}
        </div>
    @endif
    @if (session('status_error'))
        <div class="card p-4 mb-6 status-red flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i>
            {{ session('status_error') }}
        </div>
    @endif

    <div class="flex items-start justify-between gap-4 flex-wrap">
        <div>
            <h1 class="display-heading text-3xl text-[var(--color-ink-strong)] mb-1" title="{{ $server->name }}">{{ $server->display_name }}</h1>
            <div class="text-[var(--color-ink-muted)] font-data text-sm">
                {{ $server->hostname }}<span class="text-[var(--color-ink-soft)]">:{{ $server->ssh_port }}</span>
            </div>
            <div class="mt-2 flex items-center gap-1.5 flex-wrap">
                @forelse ($server->tags as $tag)
                    <a href="{{ route('dashboard', ['tag' => $tag->slug]) }}"
                       class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium border hover:opacity-80 transition-opacity"
                       style="border-color: {{ $tag->color }}; background: {{ $tag->color }}15; color: {{ $tag->color }};"
                       title="{{ $tag->description ?: 'Filter dashboard to ' . $tag->name }}">
                        <span class="inline-flex w-1.5 h-1.5 rounded-full" style="background: {{ $tag->color }}"></span>
                        {{ $tag->name }}
                    </a>
                @empty
                    <span class="text-xs text-[var(--color-ink-soft)] italic">No tags</span>
                @endforelse
                <a href="{{ route('servers.credentials.edit', $server) }}#tags"
                   class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)]"
                   title="Add or remove tags">
                    <i class="fa-solid fa-pen-to-square text-[10px]"></i>
                    {{ $server->tags->isEmpty() ? 'Add tag' : 'Edit' }}
                </a>
                @if ($server->isStaging())
                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[11px] font-medium border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] italic"
                          title="Tagged staging — sites on this server are excluded from uptime probes, security scans, plugin checks, and alerts.">
                        <i class="fa-solid fa-eye-slash text-[10px]"></i>
                        Not monitored
                    </span>
                @endif
            </div>
        </div>
        <div class="flex flex-col items-end gap-2">
            @if ($hasSpecs)
                {{-- Server specs at a glance. Populated daily by
                     clockwork:import-spinupwp from whichever cloud provider
                     owns this server (DigitalOcean, Hetzner, …). --}}
                <div class="text-xs text-[var(--color-ink-muted)] font-data flex items-center gap-2 flex-wrap justify-end"
                     title="From the {{ $server->provider_label }} payload — refreshed daily on the SpinupWP import.">
                    @if ($tier)
                        <span class="text-[var(--color-ink-strong)] font-medium">{{ $tier }}</span>
                        <span class="text-[var(--color-ink-soft)]">·</span>
                    @endif
                    @if ($server->vcpus)
                        <span>{{ $server->vcpus }} vCPU{{ $server->vcpus === 1 ? '' : 's' }}</span>
                    @endif
                    @if ($server->memory_mb)
                        <span class="text-[var(--color-ink-soft)]">·</span>
                        <span>{{ number_format($server->memory_mb / 1024, ($server->memory_mb % 1024 === 0 ? 0 : 1)) }} GB RAM</span>
                    @endif
                    @if ($server->disk_gb)
                        <span class="text-[var(--color-ink-soft)]">·</span>
                        <span>{{ $server->disk_gb }} GB Disk</span>
                    @endif
                </div>
            @endif
            <div class="flex items-center gap-3">
            {{-- SSH/jail badges link to the Settings tab where they're actionable. --}}
            <a href="{{ route('servers.show', ['server' => $server, 'tab' => 'settings']) }}"
               title="SSH: {{ $server->last_ssh_ok_at ? 'verified ' . $server->last_ssh_ok_at->diffForHumans() : 'never tested' }}"
               class="inline-flex items-center justify-center w-6 h-6"
               style="color: {{ $server->last_ssh_ok_at ? 'var(--color-status-green)' : 'var(--color-ink-soft)' }}">
                <i class="fa-solid fa-key text-sm"></i>
            </a>
            <a href="{{ route('servers.show', ['server' => $server, 'tab' => 'settings']) }}"
               title="Jail: {{ $server->clockwork_jail_provisioned_at ? 'provisioned ' . $server->clockwork_jail_provisioned_at->diffForHumans() : 'not provisioned' }}"
               class="inline-flex items-center justify-center w-6 h-6"
               style="color: {{ $server->clockwork_jail_provisioned_at ? 'var(--color-status-green)' : 'var(--color-ink-soft)' }}">
                <i class="fa-solid fa-shield-halved text-sm"></i>
            </a>
            {{-- Patch / reboot indicators — link to Updates tab so the user can act. --}}
            @unless ($server->is_ignored)
                @if ($server->upgrade_required)
                    <a href="{{ route('servers.show', ['server' => $server, 'tab' => 'updates']) }}"
                       class="status-pill status-yellow text-[10px]"
                       title="Patches available — click to run updates">
                        <i class="fa-solid fa-cube"></i> Patches
                    </a>
                @endif
                @if ($server->reboot_required)
                    <a href="{{ route('servers.show', ['server' => $server, 'tab' => 'updates']) }}"
                       class="status-pill status-yellow text-[10px]"
                       title="Reboot required — click to schedule">
                        <i class="fa-solid fa-power-off"></i> Reboot
                    </a>
                @endif
            @endunless
            @if ($server->is_ignored)
                <span class="status-pill status-unknown">
                    <i class="fa-solid fa-eye-slash"></i>
                    Ignored
                </span>
            @else
                <span class="status-pill {{ $meta['class'] }}">
                    <span class="status-dot"></span>
                    {{ $meta['label'] }}
                </span>
            @endif
            </div>
            {{-- On-demand SpinupWP refresh — picks up newly-added servers and
                 site moves between servers. Same command the scheduler runs at
                 03:30 daily, just triggered now. Takes 10–30 seconds. --}}
            <form method="POST" action="{{ route('servers.refreshFromSpinupWp') }}" class="mt-2">
                @csrf
                <button type="submit" class="btn-pill-nav text-xs"
                        title="Re-pull servers + sites from SpinupWP API. Reflects new servers and site moves immediately."
                        onclick="this.disabled=true; this.querySelector('i').classList.add('fa-spin'); this.querySelector('span').textContent = 'Refreshing…';">
                    <i class="fa-solid fa-rotate"></i> <span>Refresh from SpinupWP</span>
                </button>
            </form>
        </div>
    </div>

    @if ($server->provider_missing_since)
        <div class="mt-4 p-4 rounded-[var(--radius-card)] status-red flex items-start gap-3">
            <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
            <div class="flex-1">
                <div class="font-medium text-[var(--color-ink-strong)] mb-0.5">This server no longer exists at {{ $server->provider_label }}</div>
                <div>Missing from {{ $server->provider_label }}'s own inventory since {{ $server->provider_missing_since->diffForHumans() }} — it was likely decommissioned there. If that's expected, remove it from Clockwork below; it'll keep failing every poll until then.</div>
            </div>
            <form method="POST" action="{{ route('servers.destroy', $server) }}"
                  onsubmit="
                      var name = prompt('Type the server name to confirm deletion:\n\n{{ $server->name }}');
                      if (name === null) return false;
                      this.querySelector('input[name=confirm_name]').value = name;
                      return confirm('FINAL CONFIRMATION: permanently remove {{ $server->name }} from Clockwork? This cascade-deletes {{ $server->sites()->count() }} site(s) and all related data.');
                  ">
                @csrf
                @method('DELETE')
                <input type="hidden" name="confirm_name" value="">
                <button type="submit" class="btn-pill-nav" style="color: var(--color-status-red); border-color: var(--color-status-red);">
                    <i class="fa-solid fa-trash"></i> Remove from Clockwork
                </button>
            </form>
        </div>
    @endif

    @if ($server->is_ignored)
        <div class="mt-4 p-4 rounded-[var(--radius-card)] bg-[var(--color-surface-alt)] text-sm text-[var(--color-ink-muted)] flex items-start gap-3">
            <i class="fa-solid fa-circle-info text-[var(--color-ink-soft)] mt-0.5"></i>
            <div class="flex-1">
                <div class="font-medium text-[var(--color-ink-strong)] mb-0.5">This server is excluded from monitoring</div>
                <div>{{ $server->ignore_reason ?: 'Manually ignored — not polled, not counted in stats.' }}</div>
            </div>
            <form method="POST" action="{{ route('servers.toggleIgnore', $server) }}">
                @csrf
                <button type="submit" class="btn-pill-nav">
                    <i class="fa-solid fa-eye"></i> Stop ignoring
                </button>
            </form>
        </div>
    @endif
</div>
