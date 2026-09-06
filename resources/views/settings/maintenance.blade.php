@extends('layouts.app')

@section('title', 'Maintenance · Clockwork')

@section('content')
    <x-page-header title="Maintenance"
        subtitle="Operator utilities for keeping the Clockwork app itself healthy. Database backup is the big one today — download a snapshot here and store it securely.">
        <x-slot:actions>
            <a href="{{ route('settings.updates.index') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-arrows-rotate text-[var(--color-ink-muted)]"></i>
                <span>Updates</span>
            </a>
            <a href="{{ route('settings.diagnostics.index') }}" class="btn-pill-nav text-sm">
                <i class="fa-solid fa-stethoscope text-[var(--color-ink-muted)]"></i>
                <span>Diagnostics</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Roll-up metric tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6 max-w-3xl">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Database Name</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data truncate" title="{{ $dbInfo['name'] }}">{{ $dbInfo['name'] }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">{{ $dbInfo['driver'] }} engine</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Estimated Size</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ number_format($totalBytes / 1024 / 1024, 1) }} MB</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Fleet metadata + history</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">DB Host</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data truncate" title="{{ $dbInfo['host'] }}">{{ $dbInfo['host'] }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">MySQL connection</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Export Format</div>
            <div class="text-2xl font-display text-[var(--color-status-green)] font-data">Gzip SQL</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Direct browser stream</div>
        </div>
    </div>

    <div class="card p-6 max-w-3xl mb-6">
        <div class="flex items-start justify-between gap-4 mb-4 flex-wrap">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1">
                    <i class="fa-solid fa-database text-[var(--color-ink-muted)] mr-1"></i>
                    Database backup
                </h2>
                <div class="text-xs text-[var(--color-ink-muted)] font-data">
                    {{ $dbInfo['driver'] }} · {{ $dbInfo['host'] }} · <strong>{{ $dbInfo['name'] }}</strong>
                    · approx {{ number_format($totalBytes / 1024 / 1024, 1) }} MB
                </div>
            </div>
            <a href="{{ route('settings.maintenance.backup') }}"
               class="px-4 py-2 rounded-md bg-[var(--color-primary-600)] text-white text-sm font-medium hover:bg-[var(--color-primary-700)] inline-flex items-center gap-2">
                <i class="fa-solid fa-download"></i> Download backup
            </a>
        </div>

        <div class="text-sm text-[var(--color-ink-muted)] space-y-2">
            <p>
                Downloads a <strong>gzipped <code class="font-data text-xs">mysqldump</code></strong>
                of the full database, streamed straight to your browser. No copy is left on the server.
                Filename includes a timestamp so consecutive downloads don't collide.
            </p>
            <p>
                The dump uses <code class="font-data text-xs">--single-transaction</code> so InnoDB
                stays consistent without locking writes. Restoring is the standard
                <code class="font-data text-xs">gunzip -c file.sql.gz | mysql clockwork</code>.
            </p>
        </div>

        <div class="mt-4 pt-4 border-t border-[var(--color-border-light)] text-xs text-[var(--color-ink-soft)]">
            <strong class="text-[var(--color-status-yellow)]"><i class="fa-solid fa-triangle-exclamation"></i> Sensitive contents.</strong>
            The dump contains encrypted SSH keys, per-site DB credentials, and Companion HMAC secrets.
            Encrypted columns can only be decrypted with this app's <code class="font-data">APP_KEY</code>
            (in <code class="font-data">.env</code>), so the dump is useless on its own — but pair it
            with a stolen <code class="font-data">.env</code> and someone has the keys to your fleet.
            Store backups encrypted (e.g. <code class="font-data">age</code>, <code class="font-data">gpg</code>,
            or in an encrypted disk image) and never ship the dump and the .env to the same place.
        </div>
    </div>

    <div class="card p-6 max-w-3xl mb-6">
        <div class="flex items-start justify-between gap-4 mb-4 flex-wrap">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1">
                    <i class="fa-solid fa-chart-line text-[var(--color-ink-muted)] mr-1"></i>
                    Anonymous usage telemetry
                </h2>
                <div class="text-xs text-[var(--color-ink-muted)] max-w-lg">
                    Sends a bucketed site count, hosting-platform mix, and enabled-module list — about once a week, nothing else. No site URLs, credentials, content, or IP data. Off by default.
                </div>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <form method="POST" action="{{ route('settings.maintenance.telemetry.sendNow') }}">
                    @csrf
                    <button type="submit" class="px-4 py-2 rounded-md border border-[var(--color-border-light)] text-sm font-medium hover:bg-[var(--color-surface-alt)] inline-flex items-center gap-2">
                        <i class="fa-solid fa-paper-plane"></i> Send report now
                    </button>
                </form>
                <form method="POST" action="{{ route('settings.maintenance.telemetry.update') }}">
                    @csrf
                    @method('PATCH')
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="enabled" value="1"
                               {{ app(\App\Support\Settings::class)->get('telemetry.enabled', false) ? 'checked' : '' }}
                               onchange="this.form.submit()"
                               class="rounded border-[var(--color-border-light)]">
                        <span class="text-sm font-medium">Enabled</span>
                    </label>
                </form>
            </div>
        </div>
    </div>
@endsection
