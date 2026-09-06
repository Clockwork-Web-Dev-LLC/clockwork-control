@extends('layouts.install')

@section('title', 'Application Identity')

@section('content')
<div class="card p-6 md:p-8 shadow-sm">
    <div class="mb-6">
        <h1 class="font-display text-2xl font-bold tracking-tight text-[var(--color-ink-strong)]">
            Step 3: Application Identity
        </h1>
        <p class="text-sm text-[var(--color-ink-muted)] mt-1">
            Configure your panel's title, public URL, and primary timezone for scheduling and cron intervals.
        </p>
    </div>

    @if ($errors->any())
        <div class="p-4 rounded-xl border border-[var(--color-status-red)]/30 bg-[var(--color-status-red-bg)] text-xs text-[var(--color-status-red)] mb-6">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i>
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('install.app.save') }}">
        @csrf

        <div class="mb-5">
            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                Panel Name
            </label>
            <input type="text"
                   name="name"
                   value="{{ old('name', $data['name'] ?? 'Clockwork Control') }}"
                   required
                   placeholder="Clockwork Control"
                   class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
        </div>

        <div class="mb-5">
            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                Application URL
            </label>
            <input type="url"
                   name="url"
                   value="{{ old('url', $data['url'] ?? request()->getSchemeAndHttpHost()) }}"
                   required
                   placeholder="https://control.youragency.com"
                   class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm font-mono focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]" />
            <p class="text-[11px] text-[var(--color-ink-soft)] mt-1">This canonical URL is used for OAuth redirects and webhook validation.</p>
        </div>

        <div class="mb-6">
            <label class="block text-xs font-semibold text-[var(--color-ink-strong)] uppercase tracking-wide mb-1.5">
                Primary Timezone
            </label>
            <select name="timezone"
                    class="w-full px-3.5 py-2.5 rounded-lg border border-[var(--color-border)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--color-brand)]">
                @php $selectedTz = old('timezone', $data['timezone'] ?? 'UTC'); @endphp
                @foreach ($timezones as $tz)
                    <option value="{{ $tz }}" {{ $tz === $selectedTz ? 'selected' : '' }}>{{ $tz }}</option>
                @endforeach
            </select>
        </div>

        <div class="flex items-center justify-between pt-5 border-t border-[var(--color-border-light)]">
            <a href="{{ route('install.database') }}"
               class="inline-flex items-center gap-2 text-xs font-medium text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-arrow-left text-[10px]"></i>
                <span>Back</span>
            </a>

            <button type="submit"
                    class="inline-flex items-center gap-2 px-5 py-2.5 rounded-full bg-[var(--color-brand)] text-white font-medium text-sm hover:bg-[var(--color-brand)]/90 transition-all shadow-sm cursor-pointer">
                <span>Continue</span>
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </button>
        </div>
    </form>
</div>
@endsection
