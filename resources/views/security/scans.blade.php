@extends('layouts.app')

@section('title', 'Security scans · Clockwork')

@section('content')
    @php $activeTab = 'scans'; @endphp

    <x-page-header title="Security"
        subtitle="Fleet-wide malware + integrity scans, plus the firewall (bans queue, active bans, audit history)." />

    @include('security._tabs')

    <div class="mb-6">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)] mb-1">Fleet scan inventory</h2>
        <p class="text-[var(--color-ink-muted)] text-sm">
            Latest results from Sucuri SiteCheck (remote malware + blacklist) and <code class="font-data">wp core verify-checksums</code> (server-side core-file integrity). Sucuri runs weekly on Mondays at 02:00; checksums run daily at 02:30. <strong>Scheduled scans only target sites with a care plan enabled</strong> — sites without one are shown for visibility but skipped. <em>Re-scan</em> on any row runs both immediately regardless of care plan state (manual override).
        </p>
    </div>

    @if (session('flash'))
        <div class="mb-4 px-4 py-2 rounded-md bg-[var(--color-status-green)]/10 text-[var(--color-status-green)] text-sm">
            <i class="fa-solid fa-circle-check mr-1"></i> {{ session('flash') }}
        </div>
    @endif

    @php
        // Tiny inline list of affected sites, capped at 2 names + " + N more".
        // Each name links to that site's security tab so the operator can
        // jump straight to the finding.
        $affectedHint = function ($sites) {
            if ($sites->isEmpty()) {
                return null;
            }
            $cap = 2;
            $shown = $sites->take($cap);
            $rest = $sites->count() - $shown->count();
            $links = $shown->map(fn ($s) => sprintf(
                '<a href="%s" class="hover:underline">%s</a>',
                e(route('sites.show', [$s, 'security'])),
                e($s->domain),
            ))->implode(', ');
            if ($rest > 0) {
                $links .= ' <span class="text-[var(--color-ink-soft)]">+ '.$rest.' more</span>';
            }
            return $links;
        };
    @endphp

    @php $carePlansEnabled = \App\Models\Site::areCarePlansEnabled(); @endphp

    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">{{ $carePlansEnabled ? 'Care plan sites' : 'Monitored sites' }}</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)]">
                {{ $carePlansEnabled ? number_format($totals['care_plan_sites']) : number_format($totals['sites']) }}
                @if ($carePlansEnabled)
                    <span class="text-sm text-[var(--color-ink-soft)]">/ {{ number_format($totals['sites']) }}</span>
                @endif
            </div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Scanned daily/weekly</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Never scanned</div>
            <div class="text-2xl font-display {{ $totals['never_scanned'] > 0 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-status-green)]' }}">{{ number_format($totals['never_scanned']) }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">{{ $carePlansEnabled ? 'Of care plan sites' : 'Awaiting first run' }}</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Malware / blacklist</div>
            <div class="text-2xl font-display {{ $totals['malware_or_blacklist'] > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-status-green)]' }}">{{ number_format($totals['malware_or_blacklist']) }}</div>
            @if ($hint = $affectedHint($affected['malware']))
                <div class="text-[11px] text-[var(--color-status-red)] mt-0.5 font-data truncate">{!! $hint !!}</div>
            @endif
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Core tampering</div>
            <div class="text-2xl font-display {{ $totals['checksum_tampering'] > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-status-green)]' }}">{{ number_format($totals['checksum_tampering']) }}</div>
            @if ($hint = $affectedHint($affected['tampering']))
                <div class="text-[11px] text-[var(--color-status-red)] mt-0.5 font-data truncate">{!! $hint !!}</div>
            @endif
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Failed scans</div>
            <div class="text-2xl font-display {{ $totals['failed'] > 0 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-status-green)]' }}">{{ number_format($totals['failed']) }}</div>
            @if ($hint = $affectedHint($affected['failed']))
                <div class="text-[11px] text-[var(--color-status-yellow)] mt-0.5 font-data truncate">{!! $hint !!}</div>
            @endif
        </div>
    </div>

    @if ($sites->isEmpty())
        <div class="card p-10 text-center text-[var(--color-ink-soft)]">No sites in inventory yet.</div>
    @else
        @php
            // Three "skip" reasons collapse into one greyed pill the user can quickly tell
            // apart from real scan results: not on a care plan (disabled), not a WP install
            // (n/a — checksums column only), or never scanned (default greyed).
            $statusPill = function ($scan, bool $disabledByCarePlan = false, bool $skipForNonWp = false): array {
                if ($disabledByCarePlan) {
                    return ['class' => 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]', 'label' => 'disabled', 'rank' => 0];
                }
                if ($skipForNonWp) {
                    return ['class' => 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)]', 'label' => 'n/a', 'rank' => 0];
                }
                if (! $scan) {
                    return ['class' => 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]', 'label' => 'never', 'rank' => 1];
                }
                return match ($scan->status) {
                    \App\Models\SiteSecurityScan::STATUS_CLEAN => ['class' => 'bg-[var(--color-status-green)]/15 text-[var(--color-status-green)]', 'label' => 'clean', 'rank' => 4],
                    \App\Models\SiteSecurityScan::STATUS_ISSUES_FOUND => ['class' => 'bg-[var(--color-status-red)]/15 text-[var(--color-status-red)]', 'label' => 'issues', 'rank' => 2],
                    default => ['class' => 'bg-[var(--color-status-yellow)]/15 text-[var(--color-status-yellow)]', 'label' => 'failed', 'rank' => 3],
                };
            };
        @endphp
        <div class="card overflow-hidden">
            <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'sitecheck', defaultDir: 'asc' })">
                <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                    <tr>
                        <x-sort-th key="site" class="px-5 py-2">Site</x-sort-th>
                        <x-sort-th key="server" class="px-5 py-2">Server</x-sort-th>
                        @if ($carePlansEnabled)
                            <x-sort-th key="careplan" class="px-5 py-2" title="Sort desc to surface care plan sites first">Care plan</x-sort-th>
                        @endif
                        <x-sort-th key="sitecheck" class="px-5 py-2" title="Sort asc to surface failures + issues">SiteCheck</x-sort-th>
                        <x-sort-th key="checksums" class="px-5 py-2" title="Sort asc to surface failures + issues">Checksums</x-sort-th>
                        <x-sort-th key="last" class="px-5 py-2">Last scanned</x-sort-th>
                        <th class="px-5 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($sites as $site)
                        @php
                            $onCarePlan = $site->isCarePlanActive();
                            $sc = $site->latestSiteCheckScan;
                            $cc = $site->latestChecksumScan;
                            $scPill = $statusPill($sc, disabledByCarePlan: ! $onCarePlan);
                            $ccPill = $statusPill($cc, disabledByCarePlan: ! $onCarePlan, skipForNonWp: ! $site->is_wordpress);
                            $lastScannedAt = collect([$sc?->scanned_at, $cc?->scanned_at])->filter()->max();
                        @endphp
                        <tr
                            class="{{ $onCarePlan ? '' : 'bg-[var(--color-surface-alt)]/40' }}"
                            data-sort-site="{{ $site->domain }}"
                            data-sort-server="{{ $site->server?->name ?? $site->hosting_provider ?? '' }}"
                            data-sort-careplan="{{ $onCarePlan ? 1 : 0 }}"
                            data-sort-sitecheck="{{ $scPill['rank'] }}"
                            data-sort-checksums="{{ $ccPill['rank'] }}"
                            data-sort-last="{{ $lastScannedAt?->getTimestamp() ?? '' }}">
                            <td class="px-5 py-2 font-data">
                                <a href="{{ route('sites.show', [$site, 'security']) }}" class="text-[var(--color-primary-600)] hover:underline">{{ $site->domain }}</a>
                            </td>
                            <td class="px-5 py-2 text-xs">
                                @if ($site->server)
                                    <a href="{{ route('servers.show', $site->server) }}" class="text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]" title="{{ $site->server->name }}">{{ $site->server->display_name }}</a>
                                @elseif ($site->hosting_provider)
                                    <span class="text-[var(--color-ink-soft)] font-medium">
                                        <i class="fa-solid fa-cloud text-[10px] mr-1"></i>{{ ucfirst($site->hosting_provider) }}
                                    </span>
                                @else
                                    <span class="text-[var(--color-ink-soft)]">—</span>
                                @endif
                            </td>
                            @if ($carePlansEnabled)
                                <td class="px-5 py-2">
                                    {{-- Inline toggle — clicking flips care_plan_enabled in place via
                                         fetch (no page reload, so the row doesn't reorder). The handler
                                         at the bottom of the page intercepts via the data-care-plan-toggle
                                         attribute and swaps the button class/icon/title on success. --}}
                                    <button type="button"
                                            data-care-plan-toggle
                                            data-url="{{ route('sites.care-plan', $site) }}"
                                            data-on="{{ $site->care_plan_enabled ? '1' : '0' }}"
                                            data-domain="{{ $site->domain }}"
                                            class="care-plan-pill text-[10px] px-2 py-0.5 rounded-full font-medium cursor-pointer transition-colors {{ $site->care_plan_enabled
                                                ? 'bg-[var(--color-status-green)]/15 text-[var(--color-status-green)] hover:bg-[var(--color-status-green)]/25'
                                                : 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] hover:bg-[var(--color-status-green)]/15 hover:text-[var(--color-status-green)]' }}"
                                            title="{{ $site->care_plan_enabled
                                                ? 'Click to mark as NOT on a care plan (manual override).'
                                                : 'Click to mark as on a care plan (manual override).' }}">
                                        <i class="fa-{{ $site->care_plan_enabled ? 'solid fa-shield-heart' : 'regular fa-circle' }} text-[9px] mr-0.5"></i><span class="care-plan-label">{{ $site->care_plan_enabled ? 'on' : 'off' }}</span>
                                    </button>
                                </td>
                            @endif
                            <td class="px-5 py-2"><span class="text-[10px] px-2 py-0.5 rounded-full font-medium {{ $scPill['class'] }}">{{ $scPill['label'] }}</span></td>
                            <td class="px-5 py-2"><span class="text-[10px] px-2 py-0.5 rounded-full font-medium {{ $ccPill['class'] }}">{{ $ccPill['label'] }}</span></td>
                            <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]" title="{{ $lastScannedAt }}">{{ $lastScannedAt?->diffForHumans() ?? '—' }}</td>
                            <td class="px-5 py-2 text-right">
                                <form method="POST" action="{{ route('security.scans.run', $site) }}" class="inline">
                                    @csrf
                                    <button type="submit" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">
                                        <i class="fa-solid fa-rotate"></i> Re-scan
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Care-plan inline toggle handler. Delegated click listener — finds
         the clicked [data-care-plan-toggle] button, POSTs the flip via
         fetch (no page reload, no row reorder), swaps the button's
         classes/icon/label on success. The next FULL page reload re-sorts.
         Console-logs on attach + click so problems show in DevTools. --}}
    <script>
    (function () {
        const csrf = '{{ csrf_token() }}';
        const buttonCount = document.querySelectorAll('[data-care-plan-toggle]').length;
        console.log('[clockwork care-plan toggle] handler attached, ' + buttonCount + ' buttons on page');

        const styles = {
            on: {
                cls: 'bg-[var(--color-status-green)]/15 text-[var(--color-status-green)] hover:bg-[var(--color-status-green)]/25',
                icon: 'fa-solid fa-shield-heart',
                label: 'on',
                title: 'Click to mark as NOT on a care plan (manual override).',
            },
            off: {
                cls: 'bg-[var(--color-surface-alt)] text-[var(--color-ink-soft)] hover:bg-[var(--color-status-green)]/15 hover:text-[var(--color-status-green)]',
                icon: 'fa-regular fa-circle',
                label: 'off',
                title: 'Click to mark as on a care plan (manual override).',
            },
        };
        const styleClasses = [
            ...styles.on.cls.split(' '),
            ...styles.off.cls.split(' '),
        ];

        document.addEventListener('click', async (ev) => {
            const btn = ev.target.closest('[data-care-plan-toggle]');
            if (!btn) return;

            // Defensive: kill any default behavior even though type=button
            ev.preventDefault();
            ev.stopPropagation();

            if (btn.disabled) return;

            const wasOn = btn.dataset.on === '1';
            const target = wasOn ? '0' : '1';
            const apply = wasOn ? styles.off : styles.on;
            const domain = btn.dataset.domain || '?';
            console.log('[clockwork care-plan toggle] click: ' + domain + ' ' + (wasOn ? 'on→off' : 'off→on'));

            btn.disabled = true;
            const origOpacity = btn.style.opacity;
            btn.style.opacity = '0.5';

            try {
                const fd = new FormData();
                fd.append('_token', csrf);
                fd.append('enabled', target);
                const r = await fetch(btn.dataset.url, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    body: fd,
                    credentials: 'same-origin',
                });
                console.log('[clockwork care-plan toggle] response: ' + r.status + ' ' + r.statusText);
                if (!r.ok) {
                    const txt = await r.text();
                    throw new Error('HTTP ' + r.status + ': ' + txt.substring(0, 200));
                }
                const data = await r.json();
                if (!data.ok) throw new Error('Server returned ok=false: ' + JSON.stringify(data));

                btn.classList.remove(...styleClasses);
                btn.classList.add(...apply.cls.split(' '));
                const i = btn.querySelector('i');
                if (i) i.className = apply.icon + ' text-[9px] mr-0.5';
                const lbl = btn.querySelector('.care-plan-label');
                if (lbl) lbl.textContent = apply.label;
                btn.title = apply.title;
                btn.dataset.on = target;
                console.log('[clockwork care-plan toggle] success: ' + domain + ' is now ' + apply.label);
            } catch (e) {
                btn.style.outline = '2px solid var(--color-status-red)';
                btn.title = 'Toggle failed: ' + e.message;
                setTimeout(() => { btn.style.outline = ''; }, 3000);
                console.error('[clockwork care-plan toggle] FAILED for ' + domain + ':', e);
                alert('Care-plan toggle failed for ' + domain + '\n\n' + e.message + '\n\nCheck the browser console for details.');
            } finally {
                btn.disabled = false;
                btn.style.opacity = origOpacity;
            }
        });
    })();
    </script>
@endsection
