@extends('layouts.app')

@section('title', 'Weird Stats · Clockwork')

@section('content')
    <div class="mb-8">
        <x-page-header title="Weird Stats"
            subtitle="Fleet-level patterns the regular dashboards don't compose into a single picture. Time windows are 7 days for attack-stream stats, 30 days for visit rollups. Threat-log derivatives cached for 10 min." />

        @include('settings._tabs')

        <details class="mt-4 text-xs text-[var(--color-ink-muted)]">
            <summary class="cursor-pointer text-[var(--color-ink-strong)] font-medium inline-flex items-center gap-1">
                <i class="fa-solid fa-circle-info text-[var(--color-ink-soft)]"></i>
                How "visits" are counted
            </summary>
            <div class="mt-2 pl-5 space-y-2 max-w-3xl">
                <p>
                    A <strong>visit</strong> is a <strong>distinct IP per site per UTC day</strong>, excluding <code class="font-data text-xs">403</code> responses and static-asset paths (<code class="font-data text-xs">.js</code>, <code class="font-data text-xs">.css</code>, images, fonts, etc.). It's the WP Engine-style definition — close to "humans who looked at the site once today," far from "page views."
                </p>
                <p>
                    Two known biases:
                </p>
                <ul class="list-disc pl-5 space-y-1">
                    <li>
                        <strong>Bots are NOT filtered out at rollup time.</strong> Filtering known bots in the rollup query made it 50–100× slower; we accepted the inflation. Sites without Cloudflare have visit counts that include bot traffic.
                    </li>
                    <li>
                        <strong>CF-proxied sites under-count.</strong> The origin only sees Cloudflare edge IPs, so distinct-IP-per-day collapses across all real visitors fronted by the same CF edge. Cross-CF-state comparisons (Top 10 vs. Unprotected sites tables) are noisier than they look.
                    </li>
                </ul>
                <p>
                    Source: hourly rollup of <code class="font-data text-xs">threat_logs</code> into <code class="font-data text-xs">site_traffic_daily</code> via <code class="font-data text-xs">clockwork:rollup-traffic</code>. Full deep-dive: <code class="font-data text-xs">docs/claude/traffic-and-capacity.md</code>.
                </p>
            </div>
        </details>
    </div>

    @include('settings.weird-stats._summary-tiles', ['summary' => $summary])

    <div class="space-y-6 mt-6">
        @include('settings.weird-stats._selling-points', ['sellingPoints' => $sellingPoints])
        @include('settings.weird-stats._plugin-coverage', ['rows' => $pluginCoverage])
        @include('settings.weird-stats._top-sites', ['top' => $topSites])
        @include('settings.weird-stats._attacked-paths', ['rows' => $attackedPaths])
        @include('settings.weird-stats._repeat-offenders', ['rows' => $repeatOffenders])
        @include('settings.weird-stats._unprotected-sites', ['sites' => $unprotectedSites])
    </div>
@endsection
