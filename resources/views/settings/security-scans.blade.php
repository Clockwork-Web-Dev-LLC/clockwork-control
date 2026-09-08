@extends('layouts.app')

@section('title', 'Security scans · Clockwork')

@section('content')
    @include('settings._tabs')

    <x-page-header title="Security scans"
        subtitle="Toggle scheduled security scans and trigger one-off runs across the fleet. Sucuri SiteCheck and core checksums target care-plan sites; domain blacklist monitoring protects every site.">
        <x-slot:actions>
            <a href="{{ route('security.scans') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-shield-halved text-[var(--color-ink-muted)]"></i>
                <span>View Scan Results</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    @if (session('status'))
        <div class="card p-4 mb-6 status-green flex items-center gap-2">
            <i class="fa-solid fa-circle-check"></i> {{ session('status') }}
        </div>
    @endif
    @if (session('queue_error'))
        <div class="card p-4 mb-6 status-red flex items-center gap-2">
            <i class="fa-solid fa-triangle-exclamation"></i> {{ session('queue_error') }}
        </div>
    @endif

    {{-- Roll-up metric tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 max-w-3xl">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Scan Engines</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ count($sources) }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Configured checks</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Active Scheduled</div>
            <div class="text-2xl font-display text-[var(--color-status-green)] font-data">
                {{ collect($sources)->where('enabled', true)->count() }}
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Automated scans</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Care Plan Only</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">2</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Sucuri &amp; Checksums</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Fleet-Wide</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">1</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Google Safe Browsing</div>
        </div>
    </div>

    <form method="POST" action="{{ route('settings.security-scans.update') }}" class="card p-6 max-w-3xl mb-6">
        @csrf
        @method('PATCH')

        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-4">Scheduled scans</h2>

        <div class="space-y-5">
            @foreach ($sources as $key => $src)
                <div class="border border-[var(--color-border-light)] rounded-md p-4">
                    <div class="flex items-start justify-between gap-4 flex-wrap">
                        <label class="flex items-start gap-3 cursor-pointer flex-1 min-w-[16rem]">
                            <input type="checkbox" name="sources[{{ $key }}][enabled]" value="1"
                                   {{ $src['enabled'] ? 'checked' : '' }}
                                   class="mt-1 rounded border-[var(--color-border)]">
                            <div>
                                <div class="font-medium text-[var(--color-ink-strong)]">{{ $src['label'] }}</div>
                                <div class="text-xs text-[var(--color-ink-soft)] mt-0.5">{!! $src['description'] !!}</div>
                                <div class="text-xs text-[var(--color-ink-muted)] mt-1.5 font-data">
                                    <i class="fa-solid fa-clock text-[10px] mr-0.5"></i> {{ $src['cadence'] }}
                                </div>
                                <div class="text-xs text-[var(--color-ink-soft)] mt-1">
                                    Last run:
                                    <span class="font-data">
                                        {{ $src['last_run_at'] ? $src['last_run_at']->diffForHumans() : 'never' }}
                                    </span>
                                </div>
                            </div>
                        </label>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="mt-6 flex justify-end">
            <button type="submit"
                    class="px-4 py-2 rounded-md bg-[var(--color-primary-600)] text-white text-sm font-medium hover:bg-[var(--color-primary-700)]">
                Save settings
            </button>
        </div>
    </form>

    <div class="card p-6 max-w-3xl">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-3">Run now</h2>
        <p class="text-sm text-[var(--color-ink-muted)] mb-4">Queues the artisan command in the background. Refresh <a class="text-[var(--color-primary-600)] hover:underline" href="{{ route('security.scans') }}">/security/scans</a> after a minute to see results.</p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
            @foreach ($sources as $key => $src)
                <form method="POST" action="{{ route('settings.security-scans.runNow') }}">
                    @csrf
                    <input type="hidden" name="source" value="{{ $key }}">
                    <button type="submit"
                            class="w-full text-left px-4 py-3 rounded-md border border-[var(--color-border-light)] hover:bg-[var(--color-surface-alt)]">
                        <div class="text-sm font-medium text-[var(--color-ink-strong)]">
                            <i class="fa-solid fa-rotate mr-1 text-[var(--color-ink-soft)]"></i>
                            Run {{ $src['label'] }}
                        </div>
                        <div class="text-xs text-[var(--color-ink-soft)] mt-0.5 font-data">{{ $src['command'] }}</div>
                    </button>
                </form>
            @endforeach
        </div>
    </div>
@endsection
