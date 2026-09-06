@extends('layouts.app')

@section('title', 'Monitoring settings · Clockwork')

@section('content')
    <x-page-header title="Monitoring"
        subtitle="Global settings applied to every monitored site. Disable monitoring on individual sites from the per-site Settings tab." />

    @include('monitoring._tabs')

    @if (session('status'))
        <div class="card p-4 mb-6 flex items-start gap-2 border-l-4 border-[var(--color-status-green)]">
            <i class="fa-solid fa-circle-check text-[var(--color-status-green)] mt-0.5"></i>
            <span class="text-sm text-[var(--color-ink-strong)]">{{ session('status') }}</span>
        </div>
    @endif

    <form method="POST" action="{{ route('monitoring.settings.update') }}" class="card p-6 max-w-3xl mb-6">
        @csrf
        @method('PATCH')

        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-4">General</h2>

        {{-- Probe interval --}}
        <div class="mb-6">
            <label class="block text-sm font-medium text-[var(--color-ink-strong)] mb-2">Monitoring interval</label>
            @php $intervals = [1, 5, 10, 15]; @endphp
            <div class="flex flex-wrap gap-2">
                @foreach ($intervals as $opt)
                    <label class="cursor-pointer">
                        <input type="radio" name="interval_minutes" value="{{ $opt }}" class="peer sr-only" {{ $intervalMin === $opt ? 'checked' : '' }}>
                        <span class="inline-block px-4 py-2 rounded-md border text-sm font-medium font-data
                                     border-[var(--color-border)] text-[var(--color-ink-muted)]
                                     peer-checked:border-[var(--color-primary-600)] peer-checked:bg-[var(--color-primary-600)] peer-checked:text-white">
                            {{ $opt === 1 ? 'Every minute' : "Every {$opt} minutes" }}
                        </span>
                    </label>
                @endforeach
            </div>
            <p class="text-xs text-[var(--color-ink-muted)] mt-2">
                How often Clockwork probes each site. <strong>Changes apply on the next scheduler restart</strong>
                (<code class="font-data">php artisan schedule:work</code>). Default: every 5 minutes.
            </p>
        </div>

        {{-- Failure threshold --}}
        <div class="mb-6">
            <label class="block text-sm font-medium text-[var(--color-ink-strong)] mb-2">Failure threshold before alerting</label>
            @php $thresholds = [1, 2, 3, 4, 5, 6]; @endphp
            <div class="flex flex-wrap gap-2">
                @foreach ($thresholds as $opt)
                    <label class="cursor-pointer">
                        <input type="radio" name="failure_threshold" value="{{ $opt }}" class="peer sr-only" {{ $failureThreshold === $opt ? 'checked' : '' }}>
                        <span class="inline-block px-4 py-2 rounded-md border text-sm font-medium font-data
                                     border-[var(--color-border)] text-[var(--color-ink-muted)]
                                     peer-checked:border-[var(--color-primary-600)] peer-checked:bg-[var(--color-primary-600)] peer-checked:text-white">
                            {{ $opt }} failure{{ $opt === 1 ? '' : 's' }}
                        </span>
                    </label>
                @endforeach
            </div>
            <p class="text-xs text-[var(--color-ink-muted)] mt-2">
                A site has to fail this many probes in a row before transitioning to "down" and firing a Mattermost
                alert. At a 5-minute interval, 2 failures ≈ 10 minutes of real downtime. Lower = more sensitive
                (but more false alerts on transient blips); higher = fewer alerts. Recovery is always instant on
                the first successful probe. Default: 2.
            </p>
        </div>

        <div class="flex justify-end pt-2 border-t border-[var(--color-border-light)]">
            <button type="submit" class="px-4 py-2 rounded-md bg-[var(--color-primary-600)] text-white text-sm font-medium hover:bg-[var(--color-primary-700)]">
                Save settings
            </button>
        </div>
    </form>

    <div class="card p-6 max-w-3xl">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-2">Per-site overrides</h2>
        <p class="text-sm text-[var(--color-ink-muted)] mb-4">
            Sites with monitoring disabled. The probe runner skips these entirely — no checks, no alerts.
            Re-enable from each site's Settings tab.
        </p>
        @if ($disabledSites->isEmpty())
            <p class="text-sm text-[var(--color-ink-muted)]">All monitored sites are active. Nothing's been opted out.</p>
        @else
            <ul class="space-y-2">
                @foreach ($disabledSites as $site)
                    <li class="flex items-center justify-between gap-3 py-2 border-b border-[var(--color-border-light)] last:border-b-0">
                        <div>
                            <a href="{{ route('sites.show', [$site, 'settings']) }}" class="text-sm text-[var(--color-primary-600)] hover:underline font-data">{{ $site->domain }}</a>
                            <span class="text-xs text-[var(--color-ink-muted)] ml-2">{{ $site->server?->name }}</span>
                        </div>
                        <span class="text-xs text-[var(--color-ink-muted)]">Disabled</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
