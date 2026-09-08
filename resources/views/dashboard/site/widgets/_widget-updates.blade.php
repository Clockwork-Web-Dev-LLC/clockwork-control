@php
    $hasCompanion = $site->companion_snapshot !== null;

    $corePending = $site->isPressable()
        ? ($hasCompanion ? $site->core_update_available : null)
        : $site->wp_core_update;

    $pluginCount = $hasCompanion ? $site->plugin_updates_available : null;
    $themeCount = $hasCompanion ? $site->theme_updates_available : null;

    $pluginsPending = $hasCompanion
        ? ($pluginCount > 0)
        : (bool) $site->wp_plugin_updates;

    $themesPending = $hasCompanion
        ? ($themeCount > 0)
        : (bool) $site->wp_theme_updates;

    $hasPending = ($corePending === true) || $pluginsPending || $themesPending;
    $totalPending = ($corePending ? 1 : 0) + ($pluginCount ?? ($pluginsPending ? 1 : 0)) + ($themeCount ?? ($themesPending ? 1 : 0));
    $isUpToDate = ! $hasPending && ($corePending === false || $corePending === null);
@endphp

<div class="card p-5 flex flex-col justify-between h-full">
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-arrow-up-from-bracket text-[var(--color-brand)]"></i>
                Updates
            </h3>
            @if ($isUpToDate)
                <span class="status-pill status-green text-[10px]">
                    <span class="status-dot"></span> Up to date
                </span>
            @elseif ($hasPending)
                <span class="status-pill status-yellow text-[10px]">
                    <span class="status-dot"></span> {{ $totalPending > 0 ? $totalPending . ' Pending' : 'Updates Pending' }}
                </span>
            @else
                <span class="status-pill status-unknown text-[10px]">Unknown</span>
            @endif
        </div>

        @if ($isUpToDate)
            <div class="text-center py-5">
                <div class="w-12 h-12 rounded-full bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto mb-3 text-xl">
                    <i class="fa-solid fa-check"></i>
                </div>
                <div class="font-semibold text-sm text-[var(--color-ink-strong)]">Everything is up to date</div>
                <p class="text-xs text-[var(--color-ink-muted)] mt-1 max-w-xs mx-auto">
                    Core, plugins, and themes are running the latest releases.
                </p>
            </div>
        @else
            <div class="space-y-2 py-2">
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <span class="flex items-center gap-2 text-[var(--color-ink-strong)]">
                        <i class="fa-brands fa-wordpress text-[var(--color-brand)]"></i> WordPress Core
                    </span>
                    @if ($corePending)
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-800">Update available</span>
                    @elseif ($corePending === false)
                        <span class="text-[10px] text-emerald-700 font-medium"><i class="fa-solid fa-check text-[9px]"></i> Current</span>
                    @else
                        <span class="text-[10px] text-[var(--color-ink-muted)]">—</span>
                    @endif
                </div>
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <span class="flex items-center gap-2 text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-plug text-purple-600"></i> Plugins
                    </span>
                    @if ($pluginCount !== null && $pluginCount > 0)
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-800">{{ $pluginCount }} update{{ $pluginCount === 1 ? '' : 's' }}</span>
                    @elseif ($pluginsPending)
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-800">Update available</span>
                    @elseif ($pluginsPending === false)
                        <span class="text-[10px] text-emerald-700 font-medium"><i class="fa-solid fa-check text-[9px]"></i> Current</span>
                    @else
                        <span class="text-[10px] text-[var(--color-ink-muted)]">—</span>
                    @endif
                </div>
                <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                    <span class="flex items-center gap-2 text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-paintbrush text-indigo-600"></i> Themes
                    </span>
                    @if ($themeCount !== null && $themeCount > 0)
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-800">{{ $themeCount }} update{{ $themeCount === 1 ? '' : 's' }}</span>
                    @elseif ($themesPending)
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-800">Update available</span>
                    @elseif ($themesPending === false)
                        <span class="text-[10px] text-emerald-700 font-medium"><i class="fa-solid fa-check text-[9px]"></i> Current</span>
                    @else
                        <span class="text-[10px] text-[var(--color-ink-muted)]">—</span>
                    @endif
                </div>
            </div>
        @endif

        <div class="mt-3 pt-3 border-t border-[var(--color-border-light)] text-[11px] text-[var(--color-ink-muted)] flex items-center justify-between">
            <span>Auto-updates:</span>
            @if ($site->auto_updates_paused)
                <span class="text-amber-700 font-medium" title="{{ $site->auto_updates_paused_reason }}">
                    <i class="fa-solid fa-pause text-[10px]"></i> Paused
                </span>
            @else
                <span class="text-emerald-700 font-medium">
                    <i class="fa-solid fa-play text-[9px]"></i> Active (Nightly)
                </span>
            @endif
        </div>
    </div>

    <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
        <span class="text-[11px] text-[var(--color-ink-muted)]">
            @if ($site->wp_updates_checked_at)
                Checked {{ $site->wp_updates_checked_at->diffForHumans() }}
            @endif
        </span>
        <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'updates']) }}"
           class="btn-pill-nav text-xs font-medium text-[var(--color-brand)] hover:underline">
            Manage Updates <i class="fa-solid fa-chevron-right text-[10px] ml-0.5"></i>
        </a>
    </div>
</div>
