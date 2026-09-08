@php
    $isPressable = $site->isPressable();
    $relayEnabled = (bool) $site->backup_relay_enabled;
    $lastArchived = $site->backup_relay_last_archived_at;
    $hasBackups = $isPressable || $relayEnabled || $lastArchived !== null;
@endphp

<div class="card p-5 flex flex-col justify-between h-full">
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-box-archive text-emerald-600"></i>
                Backups
            </h3>
            @if ($hasBackups)
                <span class="status-pill status-green text-[10px]">
                    <span class="status-dot"></span> Protected
                </span>
            @else
                <span class="status-pill status-unknown text-[10px]">
                    <span class="status-dot"></span> Unconfigured
                </span>
            @endif
        </div>

        @if ($hasBackups)
            <div class="py-2">
                <div class="flex items-start gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center shrink-0 text-base">
                        <i class="fa-solid fa-server"></i>
                    </div>
                    <div>
                        <div class="font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-1.5">
                            <i class="fa-solid fa-circle-check text-emerald-600 text-xs"></i>
                            Backups are successful
                        </div>
                        <div class="text-xs text-[var(--color-ink-muted)] mt-1">
                            @if ($lastArchived)
                                Last archive: <span class="font-medium text-[var(--color-ink-strong)]">{{ $lastArchived->diffForHumans() }}</span>
                                <div class="text-[10px] text-[var(--color-ink-muted)] font-mono mt-0.5">({{ $lastArchived->format('Y-m-d H:i:s') }})</div>
                            @elseif ($isPressable)
                                Automated daily snapshots managed by Pressable API.
                            @else
                                Automated relay snapshots configured.
                            @endif
                        </div>
                    </div>
                </div>

                <div class="mt-4 p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60 text-xs space-y-1">
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Destination:</span>
                        <span class="font-medium text-[var(--color-ink-strong)]">
                            @if ($isPressable)
                                Pressable Cloud Snapshot
                            @elseif ($relayEnabled)
                                AWS S3 Glacier Relay
                            @else
                                Host Snapshot
                            @endif
                        </span>
                    </div>
                    <div class="flex items-center justify-between text-[11px]">
                        <span class="text-[var(--color-ink-muted)]">Cadence:</span>
                        <span class="font-medium text-[var(--color-ink-strong)]">Daily (Nightly)</span>
                    </div>
                </div>
            </div>
        @else
            <div class="text-center py-6">
                <div class="w-12 h-12 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center mx-auto mb-3 text-lg">
                    <i class="fa-solid fa-box-archive"></i>
                </div>
                <div class="font-semibold text-sm text-[var(--color-ink-strong)]">No automated backup relay</div>
                <p class="text-xs text-[var(--color-ink-muted)] mt-1 max-w-xs mx-auto">
                    Enable S3 Glacier backup relay in site settings to archive this site off-site daily.
                </p>
            </div>
        @endif
    </div>

    <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
        <span class="text-[11px] text-[var(--color-ink-muted)]">
            {{ $site->hosting_provider }} hosting
        </span>
        <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'settings']) }}#backup-relay-card"
           class="btn-pill-nav text-xs font-medium text-emerald-700 hover:underline">
            View Backups <i class="fa-solid fa-chevron-right text-[10px] ml-0.5"></i>
        </a>
    </div>
</div>
