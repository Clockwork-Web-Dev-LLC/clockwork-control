@extends('layouts.app')

@section('title', 'Updates · Clockwork')

@section('content')
    @php
        $tabs = [
            'plugins' => ['label' => 'Plugins', 'count' => $stats['plugins']],
            'themes' => ['label' => 'Themes', 'count' => $stats['themes']],
            'core' => ['label' => 'WordPress', 'count' => $stats['core']],
            'translations' => ['label' => 'Translations', 'count' => $stats['translations']],
        ];

        $filterUrl = function (array $overrides = []) use ($filters, $activeTab) {
            $merged = array_merge([
                'tab' => $activeTab,
                'care_plan' => $filters['care_plan'],
                'tags' => $filters['tags'],
                'show_ignored' => $filters['show_ignored'] ? 1 : null,
            ], $overrides);
            $merged = array_filter($merged, fn ($v, $k) => ! ($v === null
                || ($k === 'care_plan' && $v === 'on')
                || ($k === 'tags' && (! is_array($v) || $v === []))
                || ($k === 'show_ignored' && ! $v)
            ), ARRAY_FILTER_USE_BOTH);

            return route('updates.index', $merged);
        };

        $toggleTagUrl = function (string $slug) use ($filters, $filterUrl) {
            $current = $filters['tags'];
            $next = in_array($slug, $current, true)
                ? array_values(array_diff($current, [$slug]))
                : array_values(array_merge($current, [$slug]));

            return $filterUrl(['tags' => $next]);
        };

        $hasActiveFilters = $filters['care_plan'] !== 'on' || ! empty($filters['tags']) || $filters['show_ignored'];
    @endphp

    @if (session('flash'))
        <div class="card p-3 mb-4 flex items-center gap-2 border-l-4 border-[var(--color-status-green)] text-sm">
            <i class="fa-solid fa-circle-check text-[var(--color-status-green)]"></i>
            <span class="text-[var(--color-ink-strong)]">{{ session('flash') }}</span>
        </div>
    @endif

    <x-page-header title="Updates"
        subtitle="Pending plugin, theme, WordPress core, and translation updates across the fleet. Cached Companion snapshots refresh nightly at 03:00 (and immediately after any update batch finishes).">
        <x-slot:actions>
            <a href="{{ route('maintenance-history.index', ['action_type' => '_updates']) }}"
               class="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]"
               title="Full log of every plugin, theme, core, and translation update ever run">
                <i class="fa-solid fa-clock-rotate-left"></i> History
            </a>
            <a href="{{ route('updates.carePlan') }}"
               class="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] ml-3"
               title="{{ \App\Models\Site::areCarePlansEnabled() ? 'Manage which care-plan sites are on the nightly auto-update path' : 'Manage which sites are on the nightly auto-update path' }}">
                <i class="fa-solid fa-moon"></i> {{ \App\Models\Site::areCarePlansEnabled() ? 'Care-plan auto-updates' : 'Nightly auto-updates' }}
            </a>
            <a href="{{ route('settings.updates.index') }}"
               class="text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] ml-3"
               title="Clockwork Control system updates and companion rollout">
                <i class="fa-solid fa-arrows-rotate"></i> App updates
            </a>
            @if ($hasActiveFilters)
                <a href="{{ route('updates.index', ['tab' => $activeTab]) }}"
                   class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-status-red)] ml-3">
                    <i class="fa-solid fa-xmark"></i> Reset filters
                </a>
            @endif
        </x-slot:actions>
    </x-page-header>

    @if ($batchInProgress)
        {{-- Live batch progress widget. Polls /batches/{id}/status every 3s,
             renders progress count, currently-running jobs with click-to-expand
             wp-cli log, and recently-finished timeline. When complete_flag
             flips true, auto-reloads the page so post-update snapshot counts
             replace the pre-update ones.

             Worker-idle detection: if no jobs are running AND none have
             completed AND total > 0 after the first poll, the queue worker
             isn't draining. We surface this explicitly instead of leaving
             the user staring at "Batch starting…" forever. --}}
        <div class="card p-4 mb-4"
             x-data="batchProgress('{{ $batchInProgress }}')"
             x-init="start()"
             x-show="!hidden">
            <div class="flex items-center gap-3 flex-wrap mb-2">
                @php
                    // Pre-render the pill so its background color flips on worker_idle.
                @endphp
                <span class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-sm font-medium"
                      :class="state.complete
                              ? 'bg-[var(--color-status-green)]/10 text-[var(--color-status-green)]'
                              : (state.worker_idle
                                  ? 'bg-[var(--color-status-yellow)]/10 text-[var(--color-status-yellow)]'
                                  : 'bg-[var(--color-status-green)]/10 text-[var(--color-status-green)]')">
                    <i class="fa-solid"
                       :class="state.complete ? 'fa-circle-check'
                               : (state.worker_idle ? 'fa-pause' : 'fa-spinner fa-spin')"></i>
                    <span x-show="!state.loaded">Batch starting…</span>
                    <span x-show="state.loaded && state.worker_idle"
                          x-text="`Queued · ${state.total} pending — no worker draining`"></span>
                    <span x-show="state.loaded && !state.complete && !state.worker_idle"
                          x-text="`${state.complete_count} / ${state.total} done`"></span>
                    <span x-show="state.complete">Batch complete · reloading…</span>
                </span>
                <span x-show="state.failed_count > 0"
                      class="inline-flex items-center gap-1 text-xs text-[var(--color-status-red)]"
                      x-text="`${state.failed_count} failed`"></span>
                <span class="ml-auto text-[10px] text-[var(--color-ink-soft)]" x-show="state.last_polled_label">
                    last refresh <span x-text="state.last_polled_label"></span>
                </span>
            </div>

            {{-- Worker-idle hint with how-to-fix. Only shows if total > 0 but
                 nothing has moved. Helpful for catching "I started a batch but
                 forgot to run queue:work". --}}
            <div x-show="state.loaded && state.worker_idle"
                 class="text-xs text-[var(--color-ink-muted)] mb-2 border-l-2 border-[var(--color-status-yellow)] pl-3">
                Jobs are queued but nothing is processing them. Start a worker in a separate terminal:
                <code class="font-data text-[var(--color-ink-strong)]">php artisan queue:work --queue=plugin-updates,default --tries=3 --timeout=600</code>
            </div>

            {{-- Currently running jobs with click-to-expand wp-cli log. The
                 server emits up to 3 running rows; each has its `messages`
                 array growing as wp-cli progresses through download / unpack
                 / install / activate. --}}
            <div x-show="state.running_jobs.length > 0" class="border-t border-[var(--color-border-light)] pt-2 mt-2">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Running now</div>
                <template x-for="r in state.running_jobs" :key="r.id">
                    <div class="text-xs">
                        <button type="button" @click="toggleLog(r.id)"
                                class="flex items-center gap-2 w-full text-left py-1 hover:bg-[var(--color-surface-alt)] rounded px-1.5">
                            <i class="fa-solid fa-spinner fa-spin text-[var(--color-status-green)] text-[10px]"></i>
                            <span x-text="r.name" class="font-medium text-[var(--color-ink-strong)]"></span>
                            <span class="text-[var(--color-ink-soft)]" x-text="`on ${r.site_domain || ('site #' + r.site_id)}`"></span>
                            <span class="ml-auto text-[var(--color-ink-soft)]" x-text="`${r.messages.length} step${r.messages.length === 1 ? '' : 's'}`"></span>
                            <i class="fa-solid text-[9px]"
                               :class="openLogs[r.id] ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                        </button>
                        <pre x-show="openLogs[r.id]"
                             x-text="renderLog(r)"
                             class="text-[11px] font-data text-[var(--color-ink-muted)] bg-[var(--color-surface-alt)] p-2 rounded mb-1 whitespace-pre-wrap break-words max-h-60 overflow-y-auto"></pre>
                    </div>
                </template>
            </div>

            {{-- Recently finished jobs. Each row click-to-expands its log.
                 Completed jobs show their full wp-cli output; failed jobs
                 show error + any partial messages captured before the failure. --}}
            <div x-show="state.recent.length > 0" class="border-t border-[var(--color-border-light)] pt-2 mt-2">
                <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">Recently finished</div>
                <template x-for="r in state.recent.slice(0, 10)" :key="r.id">
                    <div class="text-xs">
                        <button type="button" @click="toggleLog(r.id)"
                                class="flex items-center gap-2 w-full text-left py-1 hover:bg-[var(--color-surface-alt)] rounded px-1.5"
                                :class="{
                                    'text-[var(--color-status-green)]': r.status === 'complete',
                                    'text-[var(--color-status-red)]': r.status === 'failed',
                                    'text-[var(--color-ink-soft)]': r.status === 'skipped' || r.status === 'cancelled',
                                }">
                            <i class="fa-solid text-[10px]"
                               :class="{
                                   'fa-circle-check': r.status === 'complete',
                                   'fa-circle-xmark': r.status === 'failed',
                                   'fa-circle-minus': r.status === 'skipped' || r.status === 'cancelled',
                               }"></i>
                            <span x-text="r.name" class="font-medium"></span>
                            <span class="text-[var(--color-ink-soft)]" x-text="`on ${r.site_domain || ('site #' + r.site_id)}`"></span>
                            <span class="text-[var(--color-ink-soft)]"
                                  x-show="r.before || r.after"
                                  x-text="` · ${r.before || '?'} → ${r.after || '?'}`"></span>
                            <span class="ml-auto text-[var(--color-ink-soft)]"
                                  x-show="r.elapsed_ms"
                                  x-text="`${(r.elapsed_ms / 1000).toFixed(1)}s`"></span>
                            <i class="fa-solid text-[9px]"
                               :class="openLogs[r.id] ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                        </button>
                        <pre x-show="openLogs[r.id]"
                             x-text="renderLog(r)"
                             class="text-[11px] font-data text-[var(--color-ink-muted)] bg-[var(--color-surface-alt)] p-2 rounded mb-1 whitespace-pre-wrap break-words max-h-60 overflow-y-auto"></pre>
                    </div>
                </template>
            </div>
        </div>

        <script>
            // Polls the batch-status JSON endpoint and surfaces live progress.
            // Stops polling on complete_flag, then reloads the page so the new
            // (post-update) snapshot counts replace the stale ones in the
            // stat bar below.
            //
            // Worker-idle: after the first poll, if total > 0 and no jobs are
            // either running or terminal, the queue worker isn't processing.
            // Surface this as an explicit yellow "Queued — no worker draining"
            // state with a how-to-start-the-worker hint.
            function batchProgress(batchId) {
                return {
                    hidden: false,
                    openLogs: {},
                    // Track whether we ever saw the batch in-flight in THIS
                    // browser session. Auto-reload should only fire on a live
                    // → complete transition, not when we land on a historical
                    // `?batch=<id>` URL that's already complete (which would
                    // otherwise infinite-loop).
                    sawInFlight: false,
                    state: {
                        loaded: false,
                        complete: false,
                        worker_idle: false,
                        total: 0,
                        complete_count: 0,
                        failed_count: 0,
                        running_jobs: [],
                        recent: [],
                        last_polled_label: '',
                    },
                    toggleLog(id) {
                        this.openLogs[id] = !this.openLogs[id];
                    },
                    renderLog(r) {
                        // For a running/finished job, stitch together: timing line,
                        // wp-cli messages, and any error text. Strip HTML entities
                        // WP injects so the pre block is readable.
                        const lines = [];
                        if (r.started_at) lines.push(`▸ started ${new Date(r.started_at).toLocaleString()}`);
                        if (r.completed_at) lines.push(`▸ finished ${new Date(r.completed_at).toLocaleString()}`);
                        if (typeof r.elapsed_ms === 'number') lines.push(`▸ elapsed ${(r.elapsed_ms / 1000).toFixed(2)}s`);
                        if (r.before || r.after) lines.push(`▸ ${r.before || '?'} → ${r.after || '?'}`);
                        if (lines.length) lines.push('');
                        const decode = (s) => (s || '').replace(/&[#a-zA-Z0-9]+;/g, m => {
                            const map = { '&amp;': '&', '&#8230;': '…', '&lt;': '<', '&gt;': '>', '&quot;': '"', '&#039;': "'" };
                            return map[m] || m;
                        });
                        for (const m of (r.messages || [])) lines.push(decode(m));
                        if (r.error) {
                            if (lines.length && lines[lines.length - 1] !== '') lines.push('');
                            lines.push('ERROR: ' + r.error);
                        }
                        if (!r.messages?.length && !r.error) {
                            lines.push('(no wp-cli output captured yet)');
                        }
                        return lines.join('\n');
                    },
                    polling: false,
                    start() {
                        this.poll();
                        this.timer = setInterval(() => this.poll(), 3000);
                    },
                    async poll() {
                        // Gate against overlapping polls — if the previous
                        // request hasn't returned yet (slow DB on a big
                        // dispatch), skip this tick instead of stacking
                        // requests on the endpoint.
                        if (this.polling) return;
                        this.polling = true;
                        try {
                            const res = await fetch(`/updates/batches/${batchId}/status`, {
                                headers: { 'Accept': 'application/json' },
                            });
                            if (!res.ok) return;
                            const d = await res.json();
                            const done = d.complete + d.failed + d.skipped + d.cancelled;
                            const running = d.running_jobs || [];
                            const inFlight = (d.pending || 0) + running.length > 0;
                            const workerIdle = d.total > 0 && running.length === 0 && done === 0;
                            if (inFlight) this.sawInFlight = true;
                            this.state = {
                                loaded: true,
                                complete: !!d.complete_flag,
                                worker_idle: workerIdle,
                                total: d.total,
                                complete_count: done,
                                failed_count: d.failed,
                                running_jobs: running,
                                recent: d.recent || [],
                                last_polled_label: new Date().toLocaleTimeString(),
                            };
                            // Auto-reload only on a live → complete transition.
                            // Skip when landing on a historical `?batch=<id>` URL
                            // (sawInFlight stayed false), which used to infinite-loop.
                            if (d.complete_flag) {
                                clearInterval(this.timer);
                                if (this.sawInFlight) {
                                    setTimeout(() => window.location.reload(), 1500);
                                }
                            }
                        } catch (e) {
                            // Silent fail — next tick retries.
                        } finally {
                            this.polling = false;
                        }
                    },
                };
            }
        </script>
    @endif

    <div class="card overflow-hidden">

        {{-- Stats bar: 4 stat columns. Each is a tab switcher. --}}
        <div class="px-5 py-5 border-b border-[var(--color-border-light)] flex items-end gap-4">
            @foreach ($tabs as $key => $tab)
                @php $isActive = $activeTab === $key; @endphp
                <a href="{{ $filterUrl(['tab' => $key]) }}"
                   class="flex-1 text-center relative pb-2 hover:opacity-90">
                    <div class="text-3xl font-display font-bold leading-none {{ $isActive ? 'text-[var(--color-primary-600)]' : 'text-[var(--color-ink-strong)]' }}">{{ number_format($tab['count']) }}</div>
                    <div class="text-sm mt-2 {{ $isActive ? 'text-[var(--color-primary-600)] font-medium' : 'text-[var(--color-ink-muted)]' }}">{{ $tab['label'] }}</div>
                    @if ($isActive)
                        <div class="absolute left-1/2 -bottom-1 -translate-x-1/2 w-0 h-0
                                    border-l-[6px] border-l-transparent
                                    border-r-[6px] border-r-transparent
                                    border-b-[6px] border-b-[var(--color-primary-600)]"></div>
                    @endif
                </a>
            @endforeach
        </div>

        {{-- Inline filter chip strip --}}
        <div class="px-5 py-2 border-b border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/40 flex items-center gap-2 flex-wrap text-xs">
            @if (\App\Models\Site::areCarePlansEnabled())
                <span class="uppercase tracking-wide text-[var(--color-ink-soft)]">Care plan</span>
                @php
                    // Same chip pattern as tags: colored dot when inactive, solid fill when active.
                    // 'On plan' = green (covered customers), 'Not on plan' = amber (the risk pool),
                    // 'All' = neutral gray (no filter — the catch-all).
                    $carePlanChips = [
                        'on' => ['label' => 'On plan', 'color' => '#10b981'],
                        'off' => ['label' => 'Not on plan', 'color' => '#d97706'],
                        'all' => ['label' => 'All', 'color' => '#9ca3af'],
                    ];
                @endphp
                @foreach ($carePlanChips as $val => $chip)
                    @php $active = ($filters['care_plan'] ?? 'on') === $val; @endphp
                    <a href="{{ $filterUrl(['care_plan' => $val]) }}"
                       class="px-2.5 py-0.5 rounded-full border inline-flex items-center gap-1.5 text-xs font-medium transition-colors {{ $active ? 'text-white' : 'bg-[var(--color-surface)] border-[var(--color-border)] text-[var(--color-ink-strong)] hover:border-[var(--color-ink-soft)]' }}"
                       @if ($active) style="background-color: {{ $chip['color'] }}; border-color: {{ $chip['color'] }};" @endif>
                        @if (! $active)
                            <span class="inline-block w-2 h-2 rounded-full" style="background-color: {{ $chip['color'] }};"></span>
                        @endif
                        {{ $chip['label'] }}
                    </a>
                @endforeach
            @endif

            @if ($availableTags->isNotEmpty())
                @if (\App\Models\Site::areCarePlansEnabled())
                    <span class="border-l border-[var(--color-border-light)] h-4 mx-1"></span>
                @endif
                <span class="uppercase tracking-wide text-[var(--color-ink-soft)]">Tags</span>
                @foreach ($availableTags as $tag)
                    @php
                        $active = in_array($tag->slug, $filters['tags'], true);
                        $color = $tag->color ?: '#6b7280';
                    @endphp
                    <a href="{{ $toggleTagUrl($tag->slug) }}"
                       class="px-2.5 py-0.5 rounded-full border inline-flex items-center gap-1.5 text-xs font-medium transition-colors {{ $active ? 'text-white' : 'bg-[var(--color-surface)] border-[var(--color-border)] text-[var(--color-ink-strong)] hover:border-[var(--color-ink-soft)]' }}"
                       @if ($active) style="background-color: {{ $color }}; border-color: {{ $color }};" @endif>
                        @if (! $active)
                            <span class="inline-block w-2 h-2 rounded-full" style="background-color: {{ $color }};"></span>
                        @endif
                        {{ $tag->name }}
                    </a>
                @endforeach
            @endif

            <a href="{{ $filterUrl(['show_ignored' => $filters['show_ignored'] ? null : 1]) }}"
               class="ml-auto px-2 py-0.5 rounded-full border {{ $filters['show_ignored'] ? 'bg-[var(--color-nav-active-bg)] text-[var(--color-nav-active-ink)] border-[var(--color-nav-active-border)]' : 'bg-[var(--color-surface)] border-[var(--color-border-light)] text-[var(--color-ink-muted)] hover:border-[var(--color-ink-strong)]' }}">
                <i class="fa-solid fa-eye{{ $filters['show_ignored'] ? '' : '-slash' }}"></i>
                {{ $filters['show_ignored'] ? 'Hide ignored' : 'Show ignored' }}
            </a>
        </div>

        {{-- Active tab content + footer bar --}}
        @switch($activeTab)
            @case('plugins')
                @include('dashboard.updates._compact-grouped', ['kind' => 'plugin', 'data' => $plugins])
                @break
            @case('themes')
                @include('dashboard.updates._compact-grouped', ['kind' => 'theme', 'data' => $themes])
                @break
            @case('core')
                @include('dashboard.updates._compact-flat', ['kind' => 'core', 'data' => $core, 'label' => 'WordPress core'])
                @break
            @case('translations')
                @include('dashboard.updates._compact-flat', ['kind' => 'translation', 'data' => $translations, 'label' => 'Translations'])
                @break
        @endswitch
    </div>

@endsection
