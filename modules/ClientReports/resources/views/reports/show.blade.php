<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $report->title }} · {{ $report->site->domain }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap');
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background-color: #f8fafc;
            color: #0f172a;
        }
        @media print {
            body { background-color: #ffffff; }
            .no-print { display: none !important; }
            .page-break { page-break-before: always; }
            .report-card { box-shadow: none !important; border: 1px solid #e2e8f0 !important; }
        }
    </style>
</head>
<body class="py-8 px-4 sm:px-6 lg:px-8">
@php
    $data = $report->sections_data;
    $meta = $data['meta'] ?? [];
    $branding = $data['branding'] ?? [];
    $site = $data['site'] ?? [];
    $updates = $data['updates'] ?? [];
    $uptime = $data['uptime'] ?? [];
    $security = $data['security'] ?? [];
    $perf = $data['performance'] ?? [];
    $forms = $data['forms'] ?? [];
    $traffic = $data['traffic'] ?? [];
    $backups = $data['backups'] ?? [];
    $workLog = $data['work_log'] ?? [];
    // Brand Palette & Accents (Settings > White Labeling > Client Reports).
    // Same fields/defaults as compileBranding()'s fallback — mirrors the
    // accent-strip pattern already used in the vulnerability report email.
    $primaryColor = !empty($branding['primary_color']) ? $branding['primary_color'] : '#2D2062';
    $accentColor = !empty($branding['accent_color']) ? $branding['accent_color'] : '#7EFF83';
@endphp

<div class="max-w-4xl mx-auto">
    {{-- Floating Action Bar for Print / Share --}}
    <div class="no-print flex items-center justify-between mb-8 bg-white p-4 rounded-xl shadow-sm border border-slate-200">
        <div class="flex items-center gap-3">
            <span class="px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-100 text-emerald-800">
                Executive Maintenance Report
            </span>
            <span class="text-xs text-slate-500">Period: {{ $report->period_start->format('M j, Y') }} &ndash; {{ $report->period_end->format('M j, Y') }}</span>
        </div>
        <div class="flex items-center gap-2">
            @if ($isOperatorPreview ?? false)
                <a href="{{ route('client-reports.email', $report) }}" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-xs font-semibold hover:bg-slate-50 transition-colors flex items-center gap-2" title="The email exactly as the client gets it">
                    <i class="fa-solid fa-envelope"></i> Email version
                </a>
            @endif
            <button onclick="window.print()" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-xs font-semibold hover:bg-slate-800 transition-colors flex items-center gap-2">
                <i class="fa-solid fa-print"></i> Print / Download PDF
            </button>
        </div>
    </div>

    @if (($isOperatorPreview ?? false) && $report->status === \Modules\ClientReports\Models\ClientReport::STATUS_SUPERSEDED)
        <div class="no-print mb-6 bg-slate-100 border border-slate-200 text-slate-700 rounded-xl p-4 text-sm">
            A newer report for this schedule and period replaced this one. It can't be sent.
        </div>
    @elseif (($isOperatorPreview ?? false) && ! empty($data['meta']['review_reasons']) && $report->status !== 'sent')
        <div class="no-print mb-6 bg-amber-50 border border-amber-200 text-amber-900 rounded-xl p-4 text-sm">
            <div class="font-semibold mb-1"><i class="fa-solid fa-hand"></i> {{ $report->schedule?->delivery_mode === \Modules\ClientReports\Models\ClientReportSchedule::MODE_AUTO ? 'Held for review instead of being emailed automatically' : 'Check these before sending' }}</div>
            <ul class="list-disc ml-5 space-y-0.5">
                @foreach ($data['meta']['review_reasons'] as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
            <div class="mt-2 text-xs text-amber-800">Fix or confirm these, then send it from the history table (or generate a fresh one).</div>
        </div>
    @endif

    {{-- Report Document Container --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden report-card">
        {{-- Header & Cover Banner: the brand colour, same as the email's header. --}}
        <div class="px-8 sm:px-12 pt-8 pb-7 flex items-start justify-between flex-wrap gap-6" style="background: {{ $primaryColor }}; -webkit-print-color-adjust: exact; print-color-adjust: exact;">
            <div>
                @if (!empty($branding['logo_url']))
                    <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] }}" class="h-10 mb-4 object-contain">
                @else
                    <div class="text-xl font-extrabold text-white tracking-tight mb-2">
                        {{ $branding['company_name'] }}
                    </div>
                @endif
                <h1 class="text-3xl font-extrabold text-white tracking-tight">
                    Website Care Report
                </h1>
                <p class="text-sm mt-1" style="color: #DCD8EA;">
                    Prepared for <span class="font-semibold text-white">{{ $report->client_name ?: $site['domain'] }}</span>
                </p>
            </div>

            <div class="text-right sm:text-right">
                <div class="text-xs uppercase tracking-wider font-semibold" style="color: #DCD8EA;">Report Period</div>
                <div class="text-base font-bold text-white mt-0.5">
                    {{ $report->period_start->format('M j, Y') }} &ndash; {{ $report->period_end->format('M j, Y') }}
                </div>
                <div class="text-xs mt-1 font-mono" style="color: #DCD8EA;">
                    {{ $site['domain'] }}
                </div>
            </div>
        </div>
        {{-- Brand Accent Strip --}}
        <div style="height: 4px; background: {{ $accentColor }}; -webkit-print-color-adjust: exact; print-color-adjust: exact;"></div>

        <div class="p-8 sm:p-12 space-y-10">

        {{-- Custom Operator Notes if Provided --}}
        @if (!empty($meta['custom_notes']))
            <div class="bg-slate-50 border-l-4 border-slate-900 p-4 rounded-r-lg text-sm text-slate-700 leading-relaxed">
                <div class="font-semibold text-slate-900 mb-1">A Note From Your Support Team:</div>
                {{ $meta['custom_notes'] }}
            </div>
        @endif

        {{-- Key Metric Highlights Grid --}}
        @php
            // Pre-schema-2 reports counted form passes wrongly (always 0%); don't show them.
            $showFormsTile = (int) ($data['meta']['schema_version'] ?? 1) >= 2 && (int) ($forms['live_tests'] ?? 0) > 0;
            // Null means nothing on the site reports blocks, so there's no number to show.
            $showBlockedTile = isset($data['security']) && ($security['blocked_threats_count'] ?? null) !== null;
            $metricCount = (isset($data['updates']) ? 1 : 0)
                + (isset($data['uptime']) ? 1 : 0)
                + ($showBlockedTile ? 1 : 0)
                + ($showFormsTile ? 1 : 0);
            // Tailwind's build-time scanner only picks up utility classes it can
            // find as literal substrings — sm:grid-cols-{{ $n }} would compile to
            // nothing for any $n whose literal class string doesn't appear
            // somewhere in the scanned source, silently breaking the grid at
            // that breakpoint. Spell out each option so the scanner sees them.
            $metricGridColsClass = match (min($metricCount, 4)) {
                1 => 'sm:grid-cols-1',
                2 => 'sm:grid-cols-2',
                3 => 'sm:grid-cols-3',
                default => 'sm:grid-cols-4',
            };
        @endphp
        @if ($metricCount > 0)
            <div class="grid grid-cols-2 {{ $metricGridColsClass }} gap-4">
                @if (isset($data['updates']))
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 text-center">
                        <div class="text-2xl font-black text-slate-900">{{ $updates['total'] ?? 0 }}</div>
                        <div class="text-xs font-semibold text-slate-500 uppercase mt-1">Updates Completed</div>
                    </div>
                @endif
                @if (isset($data['uptime']))
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 text-center">
                        <div class="text-2xl font-black text-emerald-600">{{ $uptime['uptime_percentage'] ?? 100 }}%</div>
                        <div class="text-xs font-semibold text-slate-500 uppercase mt-1">Uptime Rate</div>
                    </div>
                @endif
                @if ($showBlockedTile)
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 text-center">
                        <div class="text-2xl font-black text-slate-900">{{ $security['blocked_threats_count'] }}</div>
                        <div class="text-xs font-semibold text-slate-500 uppercase mt-1">Threats Blocked</div>
                    </div>
                @endif
                @if ($showFormsTile)
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 text-center">
                        <div class="text-2xl font-black" style="color: {{ $primaryColor }};">{{ $forms['pass_rate'] ?? 0 }}%</div>
                        <div class="text-xs font-semibold text-slate-500 uppercase mt-1">Form Deliverability</div>
                    </div>
                @endif
            </div>
        @endif

        {{-- Section: Updates Applied --}}
        @if (isset($data['updates']))
            <div class="space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-circle-arrow-up text-sky-600"></i> Updates & Upgrades
                    </h2>
                    <span class="text-xs font-semibold text-slate-500">{{ $updates['total'] ?? 0 }} updates performed</span>
                </div>
                <p class="text-xs text-slate-500">
                    Regular updates ensure your site remains secure against known vulnerabilities and compatible with current WordPress releases.
                </p>

                @if (!empty($updates['items']['plugins']))
                    <div class="overflow-x-auto">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-50 text-slate-500 uppercase tracking-wider text-[10px]">
                                <tr>
                                    <th class="px-3 py-2">Component</th>
                                    <th class="px-3 py-2">Details</th>
                                    <th class="px-3 py-2 text-right">Date Applied</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach ($updates['items']['plugins'] as $item)
                                    <tr>
                                        <td class="px-3 py-2 font-medium text-slate-900">{{ $item['target'] ?: 'WordPress Plugin' }}</td>
                                        <td class="px-3 py-2 text-slate-500">{{ $item['summary'] }}</td>
                                        <td class="px-3 py-2 text-right text-slate-400 font-mono">{{ $item['date'] }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif (array_key_exists('pending_plugins', $updates) && ($updates['pending_plugins'] === null || $updates['pending_plugins'] !== []))
                    {{-- Same rule as the email: never "up to date" while updates wait or weren't checked. --}}
                    <div class="text-xs text-slate-500 italic py-2">No updates were applied this period.</div>
                @else
                    <div class="text-xs text-slate-500 italic py-2">All software components were up-to-date throughout this period.</div>
                @endif
            </div>
        @endif

        {{-- Section: Uptime & Availability --}}
        @if (isset($data['uptime']))
            <div class="space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-heart-pulse text-emerald-600"></i> Uptime & Availability
                    </h2>
                    <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full">
                        {{ $uptime['uptime_percentage'] ?? 100 }}% Available
                    </span>
                </div>
                <p class="text-xs text-slate-500">
                    Our global uptime monitors ping your website 24/7 at frequent intervals to verify round-the-clock availability.
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-sm font-bold text-slate-900">{{ $uptime['outages_count'] ?? 0 }}</div>
                        <div class="text-[11px] text-slate-500">Outages Recorded</div>
                    </div>
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-sm font-bold text-slate-900">{{ $uptime['downtime_minutes'] ?? 0 }} min</div>
                        <div class="text-[11px] text-slate-500">Total Downtime</div>
                    </div>
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-sm font-bold text-slate-900">24 / 7 / 365</div>
                        <div class="text-[11px] text-slate-500">Proactive Monitoring</div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Section: Security & Threat Prevention --}}
        @if (isset($data['security']))
            <div class="space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-shield-halved" style="color: {{ $primaryColor }};"></i> Security & Firewall
                    </h2>
                    <span class="text-xs font-semibold text-slate-500">{{ $security['total_scans'] ?? 0 }} scans conducted</span>
                </div>
                @php
                    $checksum = $security['checksum_status'] ?? null;
                    [$checksumLabel, $checksumClass] = match ($checksum) {
                        'clean' => ['Verified clean', 'text-emerald-600'],
                        'issues_found', 'warning' => ['Unexpected changes found', 'text-amber-600'],
                        null => ['Not checked this period', 'text-slate-400'],
                        default => [ucfirst((string) $checksum), 'text-amber-600'],
                    };
                    $vulnsChecked = (bool) ($security['vulnerabilities_checked'] ?? false);
                    [$malwareLabel, $malwareClass] = match ($security['malware_status'] ?? null) {
                        'clean' => ['No malware found', 'text-emerald-600'],
                        'warning', 'issues_found' => ['Issues flagged', 'text-amber-600'],
                        null => ['Not checked this period', 'text-slate-400'],
                        default => [ucfirst((string) $security['malware_status']), 'text-amber-600'],
                    };
                    $vulns = (int) ($security['active_vulnerabilities'] ?? 0);
                @endphp
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    @if (array_key_exists('malware_status', $security))
                        <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                            <div class="text-xs font-medium text-slate-500">Malware Scan</div>
                            <div class="text-sm font-bold {{ $malwareClass }} mt-1">{{ $malwareLabel }}</div>
                            @if (! empty($security['malware_checked_at']))
                                <div class="text-[11px] text-slate-400">Checked {{ $security['malware_checked_at'] }}</div>
                            @endif
                        </div>
                    @endif
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-xs font-medium text-slate-500">WordPress Core Files</div>
                        @if ((bool) ($data['site']['is_wordpress'] ?? true))
                            <div class="text-sm font-bold {{ $checksumClass }} mt-1">{{ $checksumLabel }}</div>
                            @if (! empty($security['checksum_checked_at']))
                                <div class="text-[11px] text-slate-400">Checked {{ $security['checksum_checked_at'] }}</div>
                            @endif
                        @else
                            <div class="text-sm font-bold text-slate-400 mt-1">Not checked (no WordPress detected)</div>
                        @endif
                    </div>
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-xs font-medium text-slate-500">Malicious Requests Deflected</div>
                        <div class="text-sm font-bold text-slate-900 mt-1">
                            {{ ($security['blocked_threats_count'] ?? null) !== null ? $security['blocked_threats_count'].' IP bans' : 'Not measured (no blocker reports to Control)' }}
                        </div>
                    </div>
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-xs font-medium text-slate-500">Known Plugin Vulnerabilities</div>
                        @if (! $vulnsChecked)
                            <div class="text-sm font-bold text-slate-400 mt-1">Not checked</div>
                        @else
                            <div class="text-sm font-bold {{ $vulns === 0 ? 'text-emerald-600' : 'text-amber-600' }} mt-1">
                                {{ $vulns === 0 ? 'None found' : $vulns.' found' }}
                            </div>
                            @if (! empty($security['vulnerable_plugins']))
                                <div class="text-[11px] text-slate-500">{{ implode(', ', $security['vulnerable_plugins']) }}</div>
                            @endif
                            @if (! empty($security['vulnerabilities_as_of']))
                                <div class="text-[11px] text-slate-400">As of {{ $security['vulnerabilities_as_of'] }}</div>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
        @endif

        {{-- Section: Backups & Disaster Recovery --}}
        @if (isset($data['backups']))
            @php
                $backupStatus = $backups['status'] ?? (! empty($backups['verified']) ? 'verified' : 'not_checked');
                [$backupBadge, $backupBadgeClass] = match ($backupStatus) {
                    'verified' => ['Verified this period', 'text-emerald-700 bg-emerald-50'],
                    'none_found' => ['No backups found this period — hidden from the client email', 'text-amber-700 bg-amber-50'],
                    'stale' => ['No backups in the last 10 days of the period — hidden from the client email', 'text-amber-700 bg-amber-50'],
                    'no_source' => ['No backup source for this host — hidden from the client email', 'text-slate-600 bg-slate-100'],
                    default => ['Could not check backups — hidden from the client email', 'text-red-700 bg-red-50'],
                };
            @endphp
            <div class="space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-cloud-arrow-up text-amber-600"></i> Backups & Recovery
                    </h2>
                    <span class="{{ $backupStatus === 'verified' ? '' : 'no-print' }} text-xs font-semibold {{ $backupBadgeClass }} px-2.5 py-1 rounded-full">{{ $backupBadge }}</span>
                </div>
                @if (in_array($backupStatus, ['verified', 'stale'], true))
                    <p class="text-xs text-slate-500">
                        Backed up on {{ (int) ($backups['days_with_backups'] ?? 0) }} day(s) this period{{ ! empty($backups['destination']) ? ' by '.$backups['destination'] : '' }}.{{ $backupStatus === 'stale' && ! empty($backups['last_backup_at']) ? ' Last backup: '.$backups['last_backup_at'].'.' : '' }}
                    </p>
                    @if (! ($backups['covers_period'] ?? true) && ($isOperatorPreview ?? false))
                        <p class="no-print text-xs text-slate-500">Our records for this site start {{ $backups['records_from'] }}, so the email leaves out the day count.</p>
                    @endif
                @endif
                @if (! empty($backups['recent']))
                    <table class="w-full text-xs text-left">
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($backups['recent'] as $day)
                                <tr>
                                    <td class="px-3 py-1.5 font-mono text-slate-700">{{ $day['date'] }}</td>
                                    <td class="px-3 py-1.5 text-slate-500">{{ implode(' + ', $day['kinds']) }}{{ ! empty($day['manual']) ? ' (manual)' : '' }}</td>
                                    <td class="px-3 py-1.5 text-right text-slate-400">{{ $day['bytes'] !== null ? number_format($day['bytes'] / 1024 ** 2, 1).' MB' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
                @if (! empty($backups['check_error']))
                    <div class="no-print text-xs text-red-700 bg-red-50 p-3 rounded-lg border border-red-100">Last check failed: {{ $backups['check_error'] }}</div>
                @endif
            </div>
        @endif

        {{-- Section: Performance Benchmarks --}}
        @if (isset($data['performance']) && ! empty($perf['has_performance']) && (! empty($perf['mobile']) || ! empty($perf['desktop'])))
            <div class="space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-bolt text-yellow-500"></i> Speed & Performance
                    </h2>
                    <span class="text-xs font-semibold text-slate-500">Google Lighthouse</span>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    @if (!empty($perf['desktop']))
                        <div class="border border-slate-100 rounded-lg p-4 bg-slate-50 text-center">
                            <div class="text-xs text-slate-500 uppercase font-semibold">Desktop Performance</div>
                            <div class="text-3xl font-black text-slate-900 mt-1">{{ $perf['desktop']['score'] }}/100</div>
                            <div class="text-[11px] text-slate-400 mt-0.5">Largest Contentful Paint: {{ $perf['desktop']['lcp_ms'] }}ms</div>
                        </div>
                    @endif
                    @if (!empty($perf['mobile']))
                        <div class="border border-slate-100 rounded-lg p-4 bg-slate-50 text-center">
                            <div class="text-xs text-slate-500 uppercase font-semibold">Mobile Performance</div>
                            <div class="text-3xl font-black text-slate-900 mt-1">{{ $perf['mobile']['score'] }}/100</div>
                            <div class="text-[11px] text-slate-400 mt-0.5">Largest Contentful Paint: {{ $perf['mobile']['lcp_ms'] }}ms</div>
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- Section: Traffic Analytics --}}
        @if (isset($data['traffic']) && !empty($traffic['has_traffic']))
            <div class="space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-chart-line text-violet-600"></i> Traffic Analytics
                    </h2>
                    <span class="text-xs font-semibold text-slate-500">{{ number_format($traffic['total_visits'] ?? 0) }} visits this period</span>
                </div>
                <p class="text-xs text-slate-500">
                    Overall visitor traffic and bandwidth usage recorded by the fleet's edge/CDN layer during the reporting period.
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-center">
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-sm font-bold text-slate-900">{{ number_format($traffic['total_visits'] ?? 0) }}</div>
                        <div class="text-[11px] text-slate-500">Total Visits</div>
                    </div>
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-sm font-bold text-slate-900">{{ number_format($traffic['total_requests'] ?? 0) }}</div>
                        <div class="text-[11px] text-slate-500">Total Requests</div>
                    </div>
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-sm font-bold text-slate-900">{{ ($traffic['bandwidth_mb'] ?? 0) > 0 ? number_format($traffic['bandwidth_mb'], 1).' MB' : 'Not measured' }}</div>
                        <div class="text-[11px] text-slate-500">Bandwidth Served</div>
                    </div>
                </div>
            </div>
        @endif

        @if (isset($data['work_log']))
            <div class="space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-clock text-slate-700"></i> Work log
                    </h2>
                    <span class="text-xs font-semibold text-slate-500">{{ number_format($workLog['total_hours'] ?? 0, 2) }} hours</span>
                </div>
                @if (empty($workLog['entries']))
                    <p class="text-xs text-slate-500">No manual work was logged during this period.</p>
                @else
                    <table class="w-full text-sm">
                        <thead class="text-left text-[11px] uppercase tracking-wide text-slate-400">
                            <tr>
                                <th class="pb-2">Date</th>
                                <th class="pb-2">Hours</th>
                                <th class="pb-2">Work</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach ($workLog['entries'] as $entry)
                                <tr>
                                    <td class="py-2 text-slate-600 whitespace-nowrap">{{ $entry['worked_on'] }}</td>
                                    <td class="py-2 text-slate-900 font-semibold">{{ number_format($entry['hours'], 2) }}</td>
                                    <td class="py-2 text-slate-700">
                                        {{ $entry['description'] }}
                                        @if (!empty($entry['user']))
                                            <span class="block text-[11px] text-slate-400">{{ $entry['user'] }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        @endif

        {{-- Custom Footer Note if present --}}
        @if (!empty($branding['footer_text']))
            <div class="p-4 rounded-xl bg-slate-50 border border-slate-200 text-xs text-slate-600">
                {{ $branding['footer_text'] }}
            </div>
        @endif

        {{-- Footer --}}
        <div class="border-t border-slate-200 pt-8 flex items-center justify-between flex-wrap gap-4 text-xs text-slate-400">
            <div>
                Generated by <span class="font-semibold text-slate-600">{{ $branding['company_name'] }}</span>
            </div>
            <div>
                Questions? Email <a href="mailto:{{ $branding['support_email'] }}" class="text-slate-600 hover:underline">{{ $branding['support_email'] }}</a>
            </div>
        </div>
        </div>
    </div>
</div>
</body>
</html>
