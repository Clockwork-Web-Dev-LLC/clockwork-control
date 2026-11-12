@extends('layouts.app')

@section('title', 'SEO settings · Clockwork')

@section('content')
<x-page-header title="SEO" subtitle="Search Console and GA4 sync for SEO clients." />

@include('seo::_nav')
@include('seo::_flash')

<div class="max-w-4xl space-y-6">
    <x-card title="Google connection" icon="fa-plug"
        subtitle="One read-only connection for the whole agency. Connect once as a shared agency Google account{{ $loginHint ? ' ('.$loginHint.')' : '' }}: whatever that account sees in Search Console and GA4 is what Control can sync.">
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
                <input type="email" name="login_hint" value="{{ old('login_hint', $loginHint) }}" placeholder="seo@your-agency.com"
                       class="mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-full max-w-sm font-data">
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">Optional. Pre-fills Google's sign-in and limits it to this account's Workspace domain, so nobody connects a personal account by mistake. Use a shared account that already has access to your clients' properties.</span>
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

    <x-card title="DataForSEO" icon="fa-coins"
        subtitle="The paid data source for keyword metrics, rank tracking, site audits, backlinks, local and AI visibility. You pay per call; these caps stop spending before it runs away.">
        <div class="flex items-center justify-between gap-3 flex-wrap mb-4" data-dataforseo-state="{{ $dataForSeo['configured'] ? 'configured' : 'missing' }}">
            <p class="text-sm text-[var(--color-ink)]">
                @if ($dataForSeo['configured'])
                    <span class="status-pill status-green text-[10px] mr-1">Login saved</span>
                    Use Test to check it works and see the balance.
                @else
                    <span class="status-pill status-unknown text-[10px] mr-1">Not set up</span>
                    Add the API login and password under <strong>SEO</strong> on
                    <a href="{{ route('settings.integrations.index') }}" class="text-[var(--color-primary-600)] hover:underline">Settings → Integrations</a>.
                    They are the API ones from DataForSEO's API Access page, not the account sign-in.
                @endif
            </p>
            <form method="POST" action="{{ route('seo.dataforseo.test') }}">
                @csrf
                <button type="submit" class="btn-pill-nav text-sm" @disabled(! $dataForSeo['configured'])>
                    <i class="fa-solid fa-plug-circle-check mr-1.5"></i> Test
                </button>
            </form>
        </div>

        @if ($dataForSeo['sandbox'])
            <p class="text-sm mb-4 p-3 rounded status-yellow">
                <i class="fa-solid fa-flask mr-1"></i>
                Sandbox mode is on. Calls go to DataForSEO's free sandbox, cost nothing, and return sample data, not real data. Turn it off in Control's settings file (CLOCKWORK_SEO_DATAFORSEO_SANDBOX) before relying on any number.
            </p>
        @endif

        <form method="POST" action="{{ route('seo.dataforseo.budget') }}">
            @csrf
            @method('PUT')
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wide text-[var(--color-ink-muted)]">
                        <tr>
                            <th class="py-2 pr-4 font-semibold">Used for</th>
                            <th class="py-2 pr-4 font-semibold text-right">Spent this month</th>
                            <th class="py-2 font-semibold text-right">Monthly cap ($)</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($dataForSeo['features'] as $row)
                            <tr data-dataforseo-feature="{{ $row['feature'] }}">
                                <td class="py-2 pr-4 text-[var(--color-ink-strong)]">
                                    <label for="cap-{{ $row['feature'] }}">{{ $row['label'] }}</label>
                                </td>
                                <td class="py-2 pr-4 text-right font-data {{ $row['cap'] > 0 && $row['spent'] >= $row['cap'] * 0.8 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink)]' }}">
                                    ${{ number_format($row['spent'], 2) }}
                                </td>
                                <td class="py-2 text-right">
                                    <input type="number" step="0.01" min="0" max="5000" id="cap-{{ $row['feature'] }}" name="caps[{{ $row['feature'] }}]"
                                           value="{{ old('caps.'.$row['feature'], number_format($row['cap'], 2, '.', '')) }}"
                                           class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-28 text-right font-data">
                                </td>
                            </tr>
                        @endforeach
                        <tr>
                            <td class="py-2 pr-4 font-semibold text-[var(--color-ink-strong)]"><label for="total_cap">Everything together</label></td>
                            <td class="py-2 pr-4 text-right font-data font-semibold text-[var(--color-ink-strong)]">${{ number_format($dataForSeo['spent'], 2) }}</td>
                            <td class="py-2 text-right">
                                <input type="number" step="0.01" min="0" max="5000" id="total_cap" name="total_cap"
                                       value="{{ old('total_cap', number_format($dataForSeo['totalCap'], 2, '.', '')) }}"
                                       class="text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-28 text-right font-data">
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <label class="block text-sm mt-4">
                <span class="font-medium text-[var(--color-ink-strong)]">Monthly allowance per person ($)</span>
                <input type="number" step="0.01" min="0" max="5000" name="person_allowance"
                       value="{{ old('person_allowance', number_format($dataForSeo['personAllowance'], 2, '.', '')) }}"
                       class="mt-1 text-sm px-3 py-1.5 bg-[var(--color-surface)] border border-[var(--color-border)] rounded w-28 font-data">
                <span class="block text-xs text-[var(--color-ink-soft)] mt-1">What someone who isn't an admin can spend on lookups they ask for, through Claude or in Control. Admins and scheduled jobs are held by the caps above only.</span>
            </label>

            <p class="text-xs text-[var(--color-ink-muted)] mt-3">
                "Spent" is what DataForSEO itself reported for each call, from Control's ledger. A call that would pass a cap is not made. When a scheduled job hits its cap it stops and reports the failure, so a cap that is too low for rank tracking leaves gaps in the history: raise it here if that happens.
            </p>

            <button type="submit" class="btn-primary text-sm px-4 py-2 mt-4">Save caps</button>
        </form>
    </x-card>
</div>
@endsection
