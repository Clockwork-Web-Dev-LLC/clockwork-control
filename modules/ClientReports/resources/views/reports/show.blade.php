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
            <button onclick="window.print()" class="px-4 py-2 rounded-lg bg-slate-900 text-white text-xs font-semibold hover:bg-slate-800 transition-colors flex items-center gap-2">
                <i class="fa-solid fa-print"></i> Print / Download PDF
            </button>
        </div>
    </div>

    {{-- Report Document Container --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200 p-8 sm:p-12 space-y-10 report-card">
        {{-- Header & Cover Banner --}}
        <div class="border-b border-slate-200 pb-8 flex items-start justify-between flex-wrap gap-6">
            <div>
                @if (!empty($branding['logo_url']))
                    <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] }}" class="h-10 mb-4 object-contain">
                @else
                    <div class="text-xl font-extrabold text-slate-900 tracking-tight mb-2">
                        {{ $branding['company_name'] }}
                    </div>
                @endif
                <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight">
                    Website Care & Maintenance Report
                </h1>
                <p class="text-slate-500 text-sm mt-1">
                    Prepared for <span class="font-semibold text-slate-800">{{ $report->client_name ?: $site['domain'] }}</span>
                </p>
            </div>

            <div class="text-right sm:text-right">
                <div class="text-xs text-slate-400 uppercase tracking-wider font-semibold">Report Period</div>
                <div class="text-base font-bold text-slate-900 mt-0.5">
                    {{ $report->period_start->format('M j, Y') }} &ndash; {{ $report->period_end->format('M j, Y') }}
                </div>
                <div class="text-xs text-slate-500 mt-1 font-mono">
                    {{ $site['domain'] }}
                </div>
            </div>
        </div>

        {{-- Custom Operator Notes if Provided --}}
        @if (!empty($meta['custom_notes']))
            <div class="bg-slate-50 border-l-4 border-slate-900 p-4 rounded-r-lg text-sm text-slate-700 leading-relaxed">
                <div class="font-semibold text-slate-900 mb-1">A Note From Your Support Team:</div>
                {{ $meta['custom_notes'] }}
            </div>
        @endif

        {{-- Key Metric Highlights Grid --}}
        @php
            $metricCount = (isset($data['updates']) ? 1 : 0)
                + (isset($data['uptime']) ? 1 : 0)
                + (isset($data['security']) ? 1 : 0)
                + (isset($data['forms']) ? 1 : 0);
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
                @if (isset($data['security']))
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 text-center">
                        <div class="text-2xl font-black text-slate-900">{{ $security['blocked_threats_count'] ?? 0 }}</div>
                        <div class="text-xs font-semibold text-slate-500 uppercase mt-1">Threats Blocked</div>
                    </div>
                @endif
                @if (isset($data['forms']))
                    <div class="bg-slate-50 p-4 rounded-xl border border-slate-100 text-center">
                        <div class="text-2xl font-black text-indigo-600">{{ $forms['pass_rate'] ?? 100 }}%</div>
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
                        <i class="fa-solid fa-shield-halved text-indigo-600"></i> Security & Firewall
                    </h2>
                    <span class="text-xs font-semibold text-slate-500">{{ $security['total_scans'] ?? 0 }} scans conducted</span>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-xs font-medium text-slate-500">Malware & Checksum Scan</div>
                        <div class="text-sm font-bold text-emerald-600 mt-1 flex items-center gap-1">
                            <i class="fa-solid fa-check-circle"></i> Clean / Passed
                        </div>
                    </div>
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-xs font-medium text-slate-500">Malicious Requests Deflected</div>
                        <div class="text-sm font-bold text-slate-900 mt-1">
                            {{ $security['blocked_threats_count'] ?? 0 }} IP bans
                        </div>
                    </div>
                    <div class="border border-slate-100 rounded-lg p-3 bg-slate-50">
                        <div class="text-xs font-medium text-slate-500">Known CVE Vulnerabilities</div>
                        <div class="text-sm font-bold {{ ($security['active_vulnerabilities'] ?? 0) === 0 ? 'text-emerald-600' : 'text-amber-600' }} mt-1">
                            {{ $security['active_vulnerabilities'] ?? 0 }} Active
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Section: Backups & Disaster Recovery --}}
        @if (isset($data['backups']))
            <div class="space-y-4">
                <div class="flex items-center justify-between border-b border-slate-200 pb-2">
                    <h2 class="text-lg font-bold text-slate-900 flex items-center gap-2">
                        <i class="fa-solid fa-cloud-arrow-up text-amber-600"></i> Backups & Recovery
                    </h2>
                    <span class="text-xs font-semibold text-emerald-700 bg-emerald-50 px-2.5 py-1 rounded-full">Active</span>
                </div>
                <p class="text-xs text-slate-500">
                    Your website files and database are routinely backed up and stored in off-site secure cloud storage for complete disaster recovery.
                </p>
                <div class="text-xs text-slate-600 bg-slate-50 p-3 rounded-lg border border-slate-100">
                    <span class="font-semibold text-slate-800">Storage Destination:</span> {{ $backups['destination'] }}
                    @if (!empty($backups['last_backup_at']))
                        <span class="mx-2">&bull;</span>
                        <span class="font-semibold text-slate-800">Latest Verified Backup:</span> {{ $backups['last_backup_at'] }}
                    @endif
                </div>
            </div>
        @endif

        {{-- Section: Performance Benchmarks --}}
        @if (isset($data['performance']) && !empty($perf['has_performance']))
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
                        <div class="text-sm font-bold text-slate-900">{{ number_format($traffic['bandwidth_mb'] ?? 0, 1) }} MB</div>
                        <div class="text-[11px] text-slate-500">Bandwidth Served</div>
                    </div>
                </div>
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
</body>
</html>
