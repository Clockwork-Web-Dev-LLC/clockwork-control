{{-- Compact grouped list — Plugins / Themes tab. Mirrors ManageWP Orion's
     row layout: count badge · name · before → after · per-row checkbox.
     Click anywhere on the row to expand and see per-site detail.

     Alignment trick: parent and child rows share identical column widths
     via fixed-width "slot" spans (w-4, w-9). Child rows leave the cbox
     column empty and put their checkbox in the count slot, so per-site
     domains line up exactly under the parent's plugin name. No arbitrary
     pl-[Npx] values that may or may not have made it through Tailwind. --}}

<form method="POST" action="{{ route('updates.bulkUpdate', ['tab' => $activeTab]) }}" id="updates-form">
    @csrf

    @if ($data['total'] === 0)
        <div class="px-5 py-12 text-center text-[var(--color-ink-muted)]">
            <i class="fa-solid fa-circle-check text-4xl text-[var(--color-status-green)] mb-3"></i>
            <p class="text-sm">All {{ $kind === 'plugin' ? 'plugins' : 'themes' }} are up to date with the current filter.</p>
        </div>
    @else
        {{-- Sort controls --}}
        <div class="px-5 py-2 flex items-center gap-3 border-b border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/60 text-[11px] text-[var(--color-ink-muted)] select-none">
            <span class="w-4 flex-shrink-0"></span>
            <span class="w-9 flex-shrink-0 inline-flex items-center justify-center">
                <button type="button" data-sort-by="count"
                        class="hover:text-[var(--color-ink-strong)]"
                        title="Sort by number of sites">
                    <i class="fa-solid fa-sort" data-sort-icon="count"></i>
                </button>
            </span>
            <span class="flex-1">
                <button type="button" data-sort-by="name"
                        class="hover:text-[var(--color-ink-strong)]"
                        title="Sort alphabetically by {{ $kind }} name">
                    <i class="fa-solid fa-sort" data-sort-icon="name"></i>
                </button>
            </span>
        </div>

        <div class="divide-y divide-[var(--color-border-light)]" id="sort-list">
            @foreach ($data['groups'] as $group)
                @php
                    $hasLive = collect($group['sites'])->contains(fn ($s) => $s['has_live_job']);
                    $hasRunning = collect($group['sites'])->contains(fn ($s) => ($s['live_job_status'] ?? null) === \App\Models\PluginUpdateJob::STATUS_RUNNING);
                    $allIgnored = collect($group['sites'])->every(fn ($s) => $s['is_ignored']);

                    $first = $group['sites'][0] ?? null;
                    $versionsHomogeneous = collect($group['sites'])
                        ->every(fn ($s) => $s['before_version'] === ($first['before_version'] ?? null) && $s['target_version'] === ($first['target_version'] ?? null));
                @endphp
                <details class="group" data-group="{{ $group['slug'] }}"
                         data-sort-name="{{ strtolower($group['name']) }}"
                         data-sort-count="{{ $group['count'] }}">
                    <summary class="list-none cursor-pointer px-5 py-3 flex items-center gap-3 hover:bg-[var(--color-surface-alt)] {{ $allIgnored ? 'opacity-60' : '' }}">
                        {{-- Slot 1: parent group checkbox (toggles all children) --}}
                        <label class="cursor-pointer w-4 flex-shrink-0 inline-flex items-center justify-center" onclick="event.stopPropagation()">
                            <input type="checkbox" class="rounded" data-group-toggle="{{ $group['slug'] }}"
                                   {{ $hasLive ? 'disabled' : '' }}>
                        </label>

                        {{-- Slot 2: count badge --}}
                        <span class="w-9 flex-shrink-0 inline-flex items-center justify-center h-6 px-1.5 rounded-full bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs font-data tabular-nums">{{ $group['count'] }}</span>

                        {{-- Slot 3: plugin/theme name (flex-1, takes the rest) --}}
                        <span class="font-display font-medium text-[var(--color-ink-strong)] flex-1 truncate" title="{{ $group['name'] }}">{{ $group['name'] }}</span>

                        {{-- Before → After (representative; opacity drops when sites diverge) --}}
                        @if ($first)
                            <span class="text-sm font-data tabular-nums text-[var(--color-ink-strong)] {{ $versionsHomogeneous ? '' : 'opacity-50' }}"
                                  title="{{ $versionsHomogeneous ? '' : 'Sites have mixed before-versions; expand to see per-site' }}">
                                <span class="text-[var(--color-ink-soft)]">{{ $first['before_version'] }}</span>
                                <i class="fa-solid fa-arrow-right text-[var(--color-ink-soft)] mx-1.5 text-xs"></i>
                                <span class="text-[var(--color-primary-600)]">{{ $first['target_version'] }}</span>
                            </span>
                        @endif

                        @if ($hasRunning)
                            <i class="fa-solid fa-spinner fa-spin text-[var(--color-status-amber,#d97706)] text-sm" title="Update in progress"></i>
                        @elseif ($hasLive)
                            <i class="fa-solid fa-clock text-[var(--color-ink-soft)] text-sm" title="Update queued"></i>
                        @endif

                        @unless ($hasLive)
                            <button type="button" data-update-group="{{ $group['slug'] }}"
                                    onclick="event.stopPropagation(); event.preventDefault();"
                                    class="text-xs font-medium px-2.5 py-1 rounded-md bg-[var(--color-primary-600)] text-white hover:bg-[var(--color-primary-700)]"
                                    title="Update this {{ $kind }} on all {{ $group['count'] }} listed site{{ $group['count'] === 1 ? '' : 's' }}">
                                <i class="fa-solid fa-arrow-up-from-bracket text-[10px]"></i>
                                Update {{ $group['count'] === 1 ? '' : 'all ' . $group['count'] }}
                            </button>
                        @endunless

                        <button type="button" data-ignore-group="{{ $group['slug'] }}"
                                onclick="event.stopPropagation(); event.preventDefault();"
                                class="text-[10px] text-[var(--color-ink-soft)] hover:text-[var(--color-status-red)] px-1.5 py-0.5 rounded hover:bg-[var(--color-surface-alt)]"
                                title="Ignore this {{ $kind }} on all {{ $group['count'] }} listed site{{ $group['count'] === 1 ? '' : 's' }}">
                            <i class="fa-solid fa-eye-slash"></i> Ignore everywhere
                        </button>

                        <i class="fa-solid fa-chevron-down text-xs text-[var(--color-ink-soft)] transition-transform group-open:rotate-180"></i>
                    </summary>

                    {{-- Per-site rows. Same px-5 / gap-3 / slot widths as the
                         parent so domains line up under the plugin name slot.
                         Slot 1 (parent's cbox column) is empty; the per-site
                         checkbox lives in slot 2 (parent's count column). --}}
                    <div class="bg-[var(--color-surface-alt)]/50 border-t border-[var(--color-border-light)]">
                        @foreach ($group['sites'] as $row)
                            @php $target = "{$kind}:{$row['site_id']}:{$group['slug']}"; @endphp
                            <label class="relative flex items-center gap-3 px-5 py-2.5 hover:bg-[var(--color-surface-alt)] cursor-pointer border-b border-[var(--color-border-light)] last:border-b-0 {{ $row['is_ignored'] ? 'opacity-50' : '' }}">
                                {{-- Slot 1: empty (under parent cbox column) — the
                                     vertical rail rides through this slot to
                                     show the nesting depth. --}}
                                <span aria-hidden="true" class="w-4 flex-shrink-0 relative">
                                    <span class="absolute left-1/2 top-0 bottom-0 w-px bg-[var(--color-border-light)] -translate-x-1/2"></span>
                                </span>

                                {{-- Slot 2: per-site checkbox under parent count --}}
                                <span class="w-9 flex-shrink-0 inline-flex items-center justify-center">
                                    <input type="checkbox" name="targets[]" value="{{ $target }}"
                                           class="rounded" data-group-row="{{ $group['slug'] }}"
                                           {{ $row['has_live_job'] ? 'disabled' : '' }}>
                                </span>

                                {{-- Slot 3: domain + server (under parent name) --}}
                                <span class="flex-1 min-w-0">
                                    <a href="{{ route('sites.show', $row['site_id']) }}"
                                       onclick="event.stopPropagation()"
                                       class="text-sm font-data text-[var(--color-ink-strong)] hover:underline truncate block">{{ $row['domain'] }}</a>
                                    @if ($row['server_id'])
                                        <span class="text-[10px] text-[var(--color-ink-soft)]">
                                            {{ $row['server_name'] ?? '' }}@if ($row['server_tier']) · <span class="{{ $row['server_tier'] === 'Dedicated' ? 'text-[var(--color-primary-600)]' : '' }}">{{ $row['server_tier'] }}</span>@endif
                                        </span>
                                    @endif
                                </span>

                                @if ($row['care_plan_enabled'])
                                    @if (! empty($row['auto_updates_paused']))
                                        <span class="inline-flex items-center gap-1 text-[10px] text-[var(--color-status-yellow)] font-medium"
                                              title="Care plan ON; auto-updates paused{{ ! empty($row['auto_updates_paused_reason']) ? ' — '.$row['auto_updates_paused_reason'] : '' }}">
                                            <i class="fa-solid fa-pause"></i>
                                        </span>
                                    @else
                                        <span class="inline-flex items-center gap-1 text-[10px] text-[var(--color-status-green)] font-medium" title="Care plan ON · nightly auto-updates active">
                                            <i class="fa-solid fa-circle-check"></i>
                                            <i class="fa-solid fa-moon text-[8px]"></i>
                                        </span>
                                    @endif
                                @endif

                                <span class="text-xs font-data tabular-nums text-[var(--color-ink-strong)] whitespace-nowrap">
                                    <span class="text-[var(--color-ink-soft)]">{{ $row['before_version'] }}</span>
                                    <i class="fa-solid fa-arrow-right text-[var(--color-ink-soft)] mx-1 text-[10px]"></i>
                                    <span class="text-[var(--color-primary-600)]">{{ $row['target_version'] }}</span>
                                </span>

                                @if ($row['has_live_job'])
                                    @php $isRunning = $row['live_job_status'] === \App\Models\PluginUpdateJob::STATUS_RUNNING; @endphp
                                    <span class="text-[10px] whitespace-nowrap {{ $isRunning ? 'text-[var(--color-status-amber,#d97706)]' : 'text-[var(--color-ink-soft)]' }}">
                                        <i class="fa-solid {{ $isRunning ? 'fa-spinner fa-spin' : 'fa-clock' }}"></i> {{ $row['live_job_status'] }}
                                    </span>
                                @elseif ($row['is_ignored'])
                                    <span class="text-[10px] text-[var(--color-ink-soft)]">ignored</span>
                                @else
                                    <button type="button" data-update-row="{{ $target }}"
                                            onclick="event.stopPropagation(); event.preventDefault();"
                                            class="text-[11px] font-medium px-2 py-0.5 rounded border border-[var(--color-primary-600)] text-[var(--color-primary-600)] hover:bg-[var(--color-primary-600)] hover:text-white"
                                            title="Update just this site">
                                        Update
                                    </button>
                                @endif
                            </label>
                        @endforeach
                    </div>
                </details>
            @endforeach
        </div>

        @include('dashboard.updates._footer-bar', ['data' => $data])
    @endif
</form>

@include('dashboard.updates._form-script')
