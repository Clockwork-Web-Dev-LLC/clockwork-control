@extends('layouts.app')

@section('title', 'Capacity Settings · Clockwork')

@section('content')
    <x-page-header title="Capacity Settings"
        subtitle="Configure shared-server visit quotas, lookback windows, and server pressure thresholds.">
        <x-slot:actions>
            <a href="{{ route('capacity.index') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Back to Capacity</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    @include('settings._tabs')

    @if (session('status'))
        <div class="card p-4 mb-6 flex items-start gap-2 border-l-4 border-[var(--color-status-green)]">
            <i class="fa-solid fa-circle-check text-[var(--color-status-green)] mt-0.5"></i>
            <span class="text-sm text-[var(--color-ink-strong)]">{{ session('status') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="card p-4 mb-6 flex items-start gap-2 border-l-4 border-[var(--color-status-red)]">
            <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-red)] mt-0.5"></i>
            <div class="text-sm text-[var(--color-status-red)]">
                <p class="font-medium">Please correct the errors below:</p>
                <ul class="list-disc list-inside mt-1 space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('capacity.settings.update') }}"
          x-data="{
              visitThreshold: {{ (int) old('visit_threshold', $visitThreshold) }},
              formatNumber(num) {
                  const n = Number(num);
                  return isNaN(n) ? '0' : new Intl.NumberFormat().format(n);
              }
          }"
          class="space-y-6 max-w-3xl">
        @csrf
        @method('PATCH')

        {{-- Visit Quota / Threshold Card --}}
        <div class="card p-6">
            <div class="flex items-start justify-between gap-4 mb-4">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] flex items-center gap-2">
                        <i class="fa-solid fa-traffic-light text-[var(--color-primary-600)] text-base"></i>
                        Shared-Server Visit Quota
                    </h2>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1">
                        Monthly visit limit for sites hosted on servers carrying the <code>Shared</code> tag.
                    </p>
                </div>
                <div class="text-right">
                    <span class="text-xs font-semibold px-2.5 py-1 rounded-full bg-[var(--color-primary-50)] text-[var(--color-primary-700)] border border-[var(--color-primary-200)] font-data"
                          x-text="formatNumber(visitThreshold) + ' visits'">
                        {{ number_format($visitThreshold) }} visits
                    </span>
                </div>
            </div>

            <div class="mb-4">
                <label for="visit_threshold" class="block text-sm font-medium text-[var(--color-ink-strong)] mb-1.5">
                    Visit threshold (per site / rolling window)
                </label>
                <div class="relative max-w-sm">
                    <input type="number"
                           name="visit_threshold"
                           id="visit_threshold"
                           x-model.number="visitThreshold"
                           min="1000"
                           max="50000000"
                           step="1000"
                           required
                           class="w-full px-3 py-2 rounded-lg border border-[var(--color-border)] bg-white dark:bg-[var(--color-surface)] text-[var(--color-ink-strong)] font-data text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] focus:border-transparent">
                </div>
                <p class="text-xs text-[var(--color-ink-muted)] mt-1.5">
                    Sites exceeding this count in the rolling lookback window appear in the <strong>Over visit threshold</strong> table and increment the navigation issue badge.
                </p>
            </div>

            <div>
                <label class="block text-xs font-medium text-[var(--color-ink-soft)] uppercase tracking-wide mb-2">
                    Quick Presets
                </label>
                <div class="flex flex-wrap gap-2">
                    @php
                        $presets = [
                            ['val' => 10000, 'label' => '10k', 'note' => 'Micro / Tier 1'],
                            ['val' => 25000, 'label' => '25k', 'note' => 'WPE Starter'],
                            ['val' => 30000, 'label' => '30k', 'note' => 'Clockwork default'],
                            ['val' => 50000, 'label' => '50k', 'note' => 'Tier 2'],
                            ['val' => 100000, 'label' => '100k', 'note' => 'Growth / Tier 3'],
                        ];
                    @endphp
                    @foreach ($presets as $p)
                        <button type="button"
                                @click="visitThreshold = {{ $p['val'] }}"
                                :class="visitThreshold === {{ $p['val'] }}
                                    ? 'bg-[var(--color-primary-600)] text-white border-[var(--color-primary-600)]'
                                    : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)] border-[var(--color-border-light)] hover:bg-[var(--color-surface)]'"
                                class="px-3 py-1.5 rounded-lg border text-xs font-medium transition-colors flex items-center gap-1.5">
                            <span class="font-data font-semibold">{{ $p['label'] }}</span>
                            <span class="text-[10px] opacity-75">({{ $p['note'] }})</span>
                        </button>
                    @endforeach
                </div>
            </div>
        </div>

        {{-- Calculation Windows Card --}}
        <div class="card p-6">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1 flex items-center gap-2">
                <i class="fa-solid fa-calendar-days text-[var(--color-primary-600)] text-base"></i>
                Calculation Windows
            </h2>
            <p class="text-xs text-[var(--color-ink-muted)] mb-5">
                Define the timeframes used for early-warning evaluation and predictive extrapolation.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                <div>
                    <label for="rolling_days" class="block text-sm font-medium text-[var(--color-ink-strong)] mb-1">
                        Rolling lookback window (days)
                    </label>
                    <input type="number"
                           name="rolling_days"
                           id="rolling_days"
                           value="{{ old('rolling_days', $rollingDays) }}"
                           min="7"
                           max="90"
                           required
                           class="w-full px-3 py-2 rounded-lg border border-[var(--color-border)] bg-white dark:bg-[var(--color-surface)] text-[var(--color-ink-strong)] font-data text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] focus:border-transparent">
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1.5">
                        Number of trailing days summed to evaluate current visit velocity. Default: <strong>30 days</strong>.
                    </p>
                </div>

                <div>
                    <label for="trending_window_days" class="block text-sm font-medium text-[var(--color-ink-strong)] mb-1">
                        Trending projection window (days)
                    </label>
                    <input type="number"
                           name="trending_window_days"
                           id="trending_window_days"
                           value="{{ old('trending_window_days', $trendingWindow) }}"
                           min="1"
                           max="30"
                           required
                           class="w-full px-3 py-2 rounded-lg border border-[var(--color-border)] bg-white dark:bg-[var(--color-surface)] text-[var(--color-ink-strong)] font-data text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] focus:border-transparent">
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1.5">
                        Recent days sampled to project full 30-day run-rate in the "Trending toward overage" table. Default: <strong>7 days</strong>.
                    </p>
                </div>
            </div>
        </div>

        {{-- Server Pressure Limits Card --}}
        <div class="card p-6">
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1 flex items-center gap-2">
                <i class="fa-solid fa-server text-[var(--color-primary-600)] text-base"></i>
                Shared Server Pressure Thresholds
            </h2>
            <p class="text-xs text-[var(--color-ink-muted)] mb-5">
                A shared server is classified as <strong>Pressure</strong> (instead of <strong>Headroom</strong>) when its 24-hour average exceeds any of these yellow thresholds.
            </p>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
                <div>
                    <label for="cpu_threshold" class="block text-sm font-medium text-[var(--color-ink-strong)] mb-1">
                        CPU threshold (%)
                    </label>
                    <div class="relative">
                        <input type="number"
                               name="cpu_threshold"
                               id="cpu_threshold"
                               value="{{ old('cpu_threshold', $cpuThreshold) }}"
                               min="10"
                               max="100"
                               step="0.1"
                               required
                               class="w-full px-3 py-2 rounded-lg border border-[var(--color-border)] bg-white dark:bg-[var(--color-surface)] text-[var(--color-ink-strong)] font-data text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] focus:border-transparent">
                    </div>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1.5">Default: 70%</p>
                </div>

                <div>
                    <label for="memory_threshold" class="block text-sm font-medium text-[var(--color-ink-strong)] mb-1">
                        Memory threshold (%)
                    </label>
                    <div class="relative">
                        <input type="number"
                               name="memory_threshold"
                               id="memory_threshold"
                               value="{{ old('memory_threshold', $memoryThreshold) }}"
                               min="10"
                               max="100"
                               step="0.1"
                               required
                               class="w-full px-3 py-2 rounded-lg border border-[var(--color-border)] bg-white dark:bg-[var(--color-surface)] text-[var(--color-ink-strong)] font-data text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] focus:border-transparent">
                    </div>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1.5">Default: 80%</p>
                </div>

                <div>
                    <label for="disk_threshold" class="block text-sm font-medium text-[var(--color-ink-strong)] mb-1">
                        Disk threshold (%)
                    </label>
                    <div class="relative">
                        <input type="number"
                               name="disk_threshold"
                               id="disk_threshold"
                               value="{{ old('disk_threshold', $diskThreshold) }}"
                               min="10"
                               max="100"
                               step="0.1"
                               required
                               class="w-full px-3 py-2 rounded-lg border border-[var(--color-border)] bg-white dark:bg-[var(--color-surface)] text-[var(--color-ink-strong)] font-data text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-500)] focus:border-transparent">
                    </div>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1.5">Default: 85%</p>
                </div>
            </div>
        </div>

        {{-- Form Actions --}}
        <div class="flex items-center justify-between pt-2">
            <a href="{{ route('capacity.index') }}" class="text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                Cancel
            </a>
            <button type="submit" class="px-5 py-2.5 rounded-lg bg-[var(--color-primary-600)] text-white text-sm font-semibold hover:bg-[var(--color-primary-700)] shadow-sm transition-colors">
                Save capacity settings
            </button>
        </div>
    </form>

    {{-- Context Card --}}
    <div class="card p-6 max-w-3xl mt-8">
        <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-2 flex items-center gap-2">
            <i class="fa-solid fa-circle-info text-[var(--color-ink-muted)]"></i>
            Fleet & Collection Context
        </h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs text-[var(--color-ink-muted)] mt-3">
            <div class="p-3.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                <div class="font-medium text-[var(--color-ink-strong)] mb-1">Shared Server Scope</div>
                <div>Currently <strong>{{ $sharedServerCount }}</strong> server{{ $sharedServerCount === 1 ? '' : 's' }} tagged <code>Shared</code>. Capacity analysis only evaluates servers with this tag.</div>
                <a href="{{ route('settings.tags.index') }}" class="text-[var(--color-primary-600)] hover:underline inline-block mt-1.5">Manage server tags &rarr;</a>
            </div>
            <div class="p-3.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                <div class="font-medium text-[var(--color-ink-strong)] mb-1">Per-Site CPU Collection</div>
                <div>
                    Status:
                    @if ($siteMetricsEnabled)
                        <span class="status-pill status-green text-[10px] ml-1">Active</span>
                    @else
                        <span class="status-pill status-yellow text-[10px] ml-1">Paused</span>
                    @endif
                </div>
                <div class="mt-1">Controls the 15-minute Companion <code class="font-data">getrusage()</code> ingest for the CPU leaderboard.</div>
                <a href="{{ route('capacity.index') }}#site-leaderboard" class="text-[var(--color-primary-600)] hover:underline inline-block mt-1.5">Toggle from Capacity page &rarr;</a>
            </div>
        </div>
    </div>
@endsection
