@php
    $updates = $data['updates'] ?? [];
    $uptime = $data['uptime'] ?? [];
    $security = $data['security'] ?? [];
    $perf = $data['performance'] ?? [];
    $traffic = $data['traffic'] ?? [];
    $forms = $data['forms'] ?? [];
    $workLog = $data['work_log'] ?? [];
    $period = $report->period_start->format('F Y');
@endphp
WEBSITE CARE REPORT — {{ $domain }}
{{ $period }} ({{ $report->period_start->format('M j') }} – {{ $report->period_end->format('M j, Y') }})

Hi{{ $contactName ? ' '.$contactName : '' }},

Here's your website care summary for {{ $domain }} for {{ $period }}.
@if (! empty($data['meta']['custom_notes']))

A NOTE FROM YOUR SUPPORT TEAM
{{ $data['meta']['custom_notes'] }}
@endif
@if (isset($data['updates']))

UPDATES APPLIED ({{ count($updateRows) }})
@forelse ($updateRows as $row)
- [{{ $row['type'] }}] {{ $row['label'] }}{{ $row['date'] ? ' ('.\Illuminate\Support\Carbon::parse($row['date'])->format('M j').')' : '' }}
@empty
Everything was already up to date this month.
@endforelse
@endif
@if (isset($data['uptime']) && ($uptime['monitored'] ?? true))

UPTIME
Availability: {{ $uptime['uptime_percentage'] ?? '—' }}%
Outages: {{ (int) ($uptime['outages_count'] ?? 0) }}
Total downtime: {{ (int) ($uptime['downtime_minutes'] ?? 0) }} min
@endif
@if (isset($data['security']))

SECURITY
Security scans run: {{ number_format((int) ($security['total_scans'] ?? 0)) }}
@if (! empty($security['checksum_status']))
WordPress core files: {{ $security['checksum_status'] === 'clean' ? 'Verified clean' : ucfirst($security['checksum_status']) }}
@endif
Known vulnerabilities: {{ (int) ($security['active_vulnerabilities'] ?? 0) === 0 ? 'None' : (int) $security['active_vulnerabilities'] }}
Malicious visitors blocked: {{ number_format((int) ($security['blocked_threats_count'] ?? 0)) }}
@endif
@if (! empty($perf['has_performance']))

SPEED & PERFORMANCE (Google Lighthouse, out of 100)
@foreach (['mobile' => 'Mobile', 'desktop' => 'Desktop'] as $key => $label)
@if (! empty($perf[$key]))
{{ $label }}: {{ $perf[$key]['score'] }}/100{{ ! empty($perf[$key]['scanned_at']) ? ' (tested '.\Illuminate\Support\Carbon::parse($perf[$key]['scanned_at'])->format('M j, Y').')' : '' }}
@endif
@endforeach
@endif
@if (! empty($traffic['has_traffic']))

TRAFFIC
Visits: {{ number_format((int) ($traffic['total_visits'] ?? 0)) }}
Requests served: {{ number_format((int) ($traffic['total_requests'] ?? 0)) }}
@endif
@if ((int) ($forms['total_synthetic_tests'] ?? 0) > 0)

CONTACT FORMS
{{ (int) $forms['total_synthetic_tests'] }} test submissions, {{ $forms['pass_rate'] ?? 0 }}% delivered
@endif
@if (! empty($workLog['entries']))

ADDITIONAL WORK THIS MONTH
@foreach ($workLog['entries'] as $entry)
- {{ \Illuminate\Support\Carbon::parse($entry['worked_on'])->format('M j') }}: {{ $entry['description'] }} ({{ $entry['hours'] }}h)
@endforeach
@endif

Questions about anything in this report? Just reply to this email{{ $branding['support_email'] !== '' ? ' or write to '.$branding['support_email'] : '' }}.

— The {{ $branding['company_name'] }} team
