@extends('layouts.app')

@section('title', 'SEO settings · Clockwork')

@section('content')
<x-page-header title="SEO" subtitle="Search Console and GA4 sync for SEO clients." />

@include('seo::_nav')
@include('seo::_flash')

<div class="max-w-4xl space-y-6">
    <x-card title="Google connection" icon="fa-plug"
        subtitle="One read-only connection for the whole agency. Connect once as {{ $loginHint }}: whatever that account sees in Search Console and GA4 is what Control can sync.">
        <dl class="grid grid-cols-1 sm:grid-cols-[12rem_1fr] gap-x-4 gap-y-2 text-sm mb-5">
            <dt class="text-[var(--color-ink-muted)]">Status</dt>
            <dd>
                @if ($connection === null)
                    <span class="status-pill status-unknown text-[10px]">Not connected</span>
                @elseif ($connection->isRevoked())
                    <span class="status-pill status-red text-[10px]">Revoked</span>
                    <span class="text-xs text-[var(--color-ink-muted)] ml-2">The nightly sync is stopped. Reconnect below.</span>
                @else
                    <span class="status-pill status-green text-[10px]">Connected</span>
                @endif
            </dd>

            @if ($connection)
                <dt class="text-[var(--color-ink-muted)]">Account</dt>
                <dd class="font-data">{{ $connection->account_email ?? 'unknown' }}</dd>

                <dt class="text-[var(--color-ink-muted)]">Permissions</dt>
                <dd class="text-xs">
                    @foreach ($connection->scopes ?? [] as $scope)
                        <span class="inline-block font-data mr-2">{{ \Illuminate\Support\Str::after($scope, 'auth/') }}</span>
                    @endforeach
                </dd>

                <dt class="text-[var(--color-ink-muted)]">Last token refresh</dt>
                <dd>{{ $connection->last_refreshed_at?->diffForHumans() ?? 'never' }}</dd>

                <dt class="text-[var(--color-ink-muted)]">Search Console properties</dt>
                <dd>{{ $gscPropertyCount }} visible · {{ $profileCount }} {{ \Illuminate\Support\Str::plural('site', $profileCount) }} set up</dd>

                @if ($connection->last_error)
                    <dt class="text-[var(--color-ink-muted)]">Last error</dt>
                    <dd class="text-xs text-[var(--color-status-red)]">
                        {{ $connection->last_error }}
                        <span class="text-[var(--color-ink-soft)]">({{ $connection->last_error_at?->diffForHumans() }})</span>
                    </dd>
                @endif
            @endif

            <dt class="text-[var(--color-ink-muted)]">OAuth client</dt>
            <dd class="text-xs">
                @if ($clientSource === 'seo')
                    SEO client from <a href="{{ route('settings.integrations.index') }}" class="text-[var(--color-primary-600)] hover:underline">Settings → Integrations</a>
                @elseif ($clientSource === 'login')
                    Falling back to the Google login client. Add a separate SEO client on <a href="{{ route('settings.integrations.index') }}" class="text-[var(--color-primary-600)] hover:underline">Settings → Integrations</a>, or add the redirect URI below to the login client.
                @else
                    <span class="text-[var(--color-status-red)]">Not set.</span> Add the client ID and secret on <a href="{{ route('settings.integrations.index') }}" class="text-[var(--color-primary-600)] hover:underline">Settings → Integrations</a> (SEO).
                @endif
            </dd>

            <dt class="text-[var(--color-ink-muted)]">Redirect URI to register</dt>
            <dd class="font-data text-xs break-all select-all">{{ $redirectUri }}</dd>
        </dl>

        <div class="flex flex-wrap gap-2">
            @if ($isConfigured)
                <a href="{{ route('seo.google.connect') }}" class="btn-primary text-sm px-4 py-2">
                    <i class="fa-brands fa-google mr-1"></i>
                    {{ $connection ? 'Reconnect' : 'Connect Google' }}
                </a>
            @endif
            @if ($connection)
                <form method="POST" action="{{ route('seo.google.test') }}">
                    @csrf
                    <button type="submit" class="btn-pill-nav text-sm">Test connection</button>
                </form>
                <form method="POST" action="{{ route('seo.google.disconnect') }}"
                      onsubmit="return confirm('Disconnect Google? The nightly sync stops; stored search data is kept.')">
                    @csrf
                    <button type="submit" class="btn-pill-nav text-sm text-[var(--color-status-red)]">Disconnect</button>
                </form>
            @endif
        </div>
        @if ($connection)
            <p class="text-xs text-[var(--color-ink-soft)] mt-3">
                Reconnect only when needed: Google keeps at most 100 refresh tokens per account and client, and silently drops the oldest.
            </p>
        @endif
    </x-card>

    <x-card title="Sync settings" icon="fa-sliders">
        <form method="POST" action="{{ route('seo.settings.update') }}" class="space-y-4">
            @csrf
            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Google account to connect</span>
                <input type="email" name="login_hint" value="{{ old('login_hint', $loginHint) }}" required
                       class="mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full max-w-sm font-data">
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Pre-fills Google's sign-in and limits it to this account's Workspace domain.</span>
            </label>

            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">Days after month end before numbers are final</span>
                <input type="number" name="final_after_days" min="2" max="15" value="{{ old('final_after_days', $finalAfterDays) }}" required
                       class="mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-24 font-data">
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Search Console keeps filling in late data for about two days. Until then a month is shown as provisional.</span>
            </label>

            <label class="block text-sm">
                <span class="font-medium text-[var(--color-ink-strong)]">AI referrer pattern (GA4 session source)</span>
                <textarea name="ai_referrer_regex" rows="3"
                          class="mt-1 text-xs px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full font-data">{{ old('ai_referrer_regex', $aiReferrerRegex) }}</textarea>
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Counts AI assistant visits before GA4's AI Assistant channel existed (2026-05-13). Leave empty for the default.</span>
            </label>

            <button type="submit" class="btn-primary text-sm px-4 py-2">Save</button>
        </form>
    </x-card>
</div>
@endsection
