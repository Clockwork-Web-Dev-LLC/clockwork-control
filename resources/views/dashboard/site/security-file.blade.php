@extends('layouts.app')

@section('title', $path.' · '.$site->domain.' · Clockwork')

@section('content')
    <div class="mb-4 text-sm">
        <a href="{{ route('sites.show', [$site, 'security']) }}" class="text-[var(--color-primary-600)] hover:underline">
            <i class="fa-solid fa-arrow-left text-xs"></i> Back to {{ $site->domain }} security
        </a>
    </div>

    <div class="mb-6">
        <h1 class="display-heading text-2xl text-[var(--color-ink-strong)] mb-1">
            <i class="fa-solid fa-file-code text-[var(--color-ink-muted)] mr-1"></i>
            <code class="font-data text-xl">{{ $path }}</code>
        </h1>
        <p class="text-[var(--color-ink-muted)] text-sm">
            Flagged by <code class="font-data text-xs">wp core verify-checksums</code> as
            <strong>{{ $bucket }}</strong>
            on <strong>{{ $site->domain }}</strong>.
            Read live over SSH from
            <code class="font-data text-xs">{{ $absolutePath }}</code>.
            @if ($scannedAt)
                Scan ran {{ $scannedAt->diffForHumans() }}.
            @endif
        </p>
    </div>

    @if (session('flash'))
        <div class="card p-3 mb-5 flex items-center gap-2 border-l-4 border-[var(--color-status-yellow)] text-sm">
            <i class="fa-solid fa-circle-exclamation text-[var(--color-status-yellow)]"></i>
            <span class="text-[var(--color-ink-strong)]">{{ session('flash') }}</span>
        </div>
    @endif

    {{-- Allowlist control card --}}
    <div class="card p-5 mb-5 max-w-3xl">
        @if ($isAllowlisted)
            <div class="flex items-center justify-between gap-4 flex-wrap">
                <div class="text-sm">
                    <span class="text-[var(--color-status-green)] font-medium">
                        <i class="fa-solid fa-circle-check"></i> Allowlisted
                    </span>
                    <span class="text-[var(--color-ink-muted)]">— this path is suppressed from /issues for this site.</span>
                </div>
                @php
                    $entry = \App\Models\SiteCoreChecksumAllowlist::where('site_id', $site->id)
                        ->where('path', $path)
                        ->whereIn('bucket', [$bucket, '*'])
                        ->first();
                @endphp
                @if ($entry)
                    <form method="POST" action="{{ route('security.scans.allowlist.remove', [$site, $entry]) }}">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="text-xs px-3 py-1.5 rounded-full border border-[var(--color-border-light)] text-[var(--color-ink-muted)] hover:text-[var(--color-status-red)] hover:border-[var(--color-status-red)]">
                            <i class="fa-solid fa-trash"></i> Remove from allowlist
                        </button>
                    </form>
                @endif
            </div>
            @if ($entry?->reason)
                <div class="mt-2 text-xs text-[var(--color-ink-muted)]">
                    <span class="text-[var(--color-ink-soft)]">Reason:</span> {{ $entry->reason }}
                </div>
            @endif
        @else
            <form method="POST" action="{{ route('security.scans.allowlist.add', $site) }}" class="space-y-3">
                @csrf
                <input type="hidden" name="path" value="{{ $path }}">
                <input type="hidden" name="bucket" value="{{ $bucket }}">

                <div class="text-sm text-[var(--color-ink-strong)]">
                    Allowlist this path so it stops showing as an issue.
                </div>
                <div>
                    <label for="reason" class="block text-xs text-[var(--color-ink-muted)] mb-1">Reason (recommended — future-you will thank you)</label>
                    <input type="text" id="reason" name="reason" maxlength="1000"
                           placeholder="e.g. Imunify360 hardening file, confirmed benign 2026-05-05"
                           class="w-full text-sm px-3 py-2 rounded border border-[var(--color-border-light)] focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-600)]">
                </div>
                <div class="flex items-center justify-between gap-3 pt-1">
                    <span class="text-xs text-[var(--color-ink-soft)]">
                        Scoped to <strong>{{ $bucket }}</strong> findings only — same path in a different bucket would still flag.
                    </span>
                    <button type="submit"
                            class="px-4 py-2 rounded-md bg-[var(--color-primary-600)] text-white text-sm font-medium hover:bg-[var(--color-primary-700)] inline-flex items-center gap-2">
                        <i class="fa-solid fa-check"></i> Allow this file
                    </button>
                </div>
            </form>
        @endif
    </div>

    {{-- File metadata --}}
    <div class="card p-5 mb-5 max-w-3xl">
        <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-3">
            <i class="fa-solid fa-circle-info text-[var(--color-ink-soft)] mr-1"></i>
            File metadata
        </h2>
        <dl class="grid grid-cols-1 md:grid-cols-2 gap-3 text-xs">
            <div>
                <dt class="text-[var(--color-ink-soft)]">ls -la</dt>
                <dd class="font-data text-[var(--color-ink-strong)] break-all">{{ $sections['ls'] ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-[var(--color-ink-soft)]">Last modified</dt>
                <dd class="font-data text-[var(--color-ink-strong)]">{{ $sections['stat'] ?: '—' }}</dd>
            </div>
            <div>
                <dt class="text-[var(--color-ink-soft)]">Size</dt>
                <dd class="font-data text-[var(--color-ink-strong)]">{{ $sections['size'] ?: '—' }}</dd>
            </div>
        </dl>
    </div>

    {{-- File contents --}}
    <div class="card p-5">
        <h2 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-3">
            <i class="fa-solid fa-file-lines text-[var(--color-ink-soft)] mr-1"></i>
            File contents
            <span class="text-xs text-[var(--color-ink-soft)] ml-2 font-normal">(first 64 KB)</span>
        </h2>
        @if (trim($sections['head']) === '')
            <p class="text-sm text-[var(--color-ink-muted)]">File is empty or unreadable.</p>
        @else
            <pre class="text-xs font-data text-[var(--color-ink-strong)] bg-[var(--color-surface-alt)] p-4 rounded overflow-x-auto whitespace-pre-wrap break-all">{{ $sections['head'] }}</pre>
        @endif
    </div>
@endsection
