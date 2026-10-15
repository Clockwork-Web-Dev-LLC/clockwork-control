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
@foreach ($uptime['outages'] ?? [] as $outage)
- {{ ! empty($outage['started_before_period']) ? 'Down since before' : 'Down' }} {{ \Illuminate\Support\Carbon::parse($outage['started_at'])->format('M j, g:i A') }} UTC for {{ (int) $outage['minutes'] < 1 ? 'under a minute' : $outage['minutes'].' min' }}{{ ! empty($outage['ongoing']) ? ' (still down at period end)' : '' }}
@endforeach
@if ((int) ($uptime['outages_count'] ?? 0) > count($uptime['outages'] ?? []) && ! empty($uptime['outages']))
(Showing the {{ count($uptime['outages']) }} most recent of {{ (int) $uptime['outages_count'] }} outages.)
@endif
@endif
@if (isset($data['security']))

SECURITY
Security scans run: {{ number_format((int) ($security['total_scans'] ?? 0)) }}
@if (array_key_exists('blacklist_status', $security))
Spam & malware blocklists: {{ match ($security['blacklist_status']) { 'clean' => 'Not listed', 'issues_found', 'warning' => 'Listed — under review', null => 'Not checked this month', default => ucfirst((string) $security['blacklist_status']) } }}
@endif
@if (array_key_exists('malware_status', $security))
Malware scan: {{ match ($security['malware_status']) { 'clean' => 'No malware found', 'warning', 'issues_found' => 'Issues flagged', null => 'Not checked this month', default => ucfirst((string) $security['malware_status']) } }}
@endif
@if ((bool) ($data['site']['is_wordpress'] ?? true))
WordPress core files: {{ match ($security['checksum_status'] ?? null) { 'clean' => 'Verified clean', 'issues_found', 'warning' => 'Unexpected changes found', null => 'Not checked this month', default => ucfirst((string) $security['checksum_status']) } }}
@endif
@php
    $vulns = (int) ($security['active_vulnerabilities'] ?? 0);
    $vulnPlugins = count($security['vulnerable_plugins'] ?? []);
    $inactiveVulns = (int) ($security['inactive_plugin_vulnerabilities'] ?? 0);
@endphp
Known plugin vulnerabilities{{ ! empty($security['vulnerabilities_as_of']) ? ' (as of '.\Illuminate\Support\Carbon::parse($security['vulnerabilities_as_of'])->format('M j').')' : '' }}: {{ ! ($security['vulnerabilities_checked'] ?? false) ? 'Not checked' : ($vulns === 0 ? 'None found' : $vulns.' found'.($vulnPlugins > 0 ? ' in '.$vulnPlugins.' '.\Illuminate\Support\Str::plural('plugin', $vulnPlugins) : '').($inactiveVulns > 0 ? ' ('.$inactiveVulns.' in inactive plugins)' : '')) }}
@if (($security['blocked_threats_count'] ?? null) !== null)
Malicious visitors blocked: {{ number_format((int) $security['blocked_threats_count']) }}
@endif
@endif
@if (! empty($data['backups']['verified']))

BACKUPS
@if (isset($data['backups']['status']))
Backed up on {{ (int) $data['backups']['days_with_backups'] }} {{ \Illuminate\Support\Str::plural('day', (int) $data['backups']['days_with_backups']) }} this month{{ ! empty($data['backups']['destination']) ? ' by '.$data['backups']['destination'] : '' }}. Most recent:
@foreach ($data['backups']['recent'] as $day)
- {{ \Illuminate\Support\Carbon::parse($day['date'])->format('D, M j') }}{{ ! empty($day['manual']) ? ' (manual)' : '' }}
@endforeach
@else
Your site's files and database were backed up to independent off-site storage this month.{{ ! empty($data['backups']['last_backup_at']) ? ' Most recent backup: '.\Illuminate\Support\Carbon::parse($data['backups']['last_backup_at'])->format('M j, Y').'.' : '' }}
@endif
@endif
@if (! empty($perf['has_performance']) && (! empty($perf['mobile']) || ! empty($perf['desktop'])))

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
@if ((int) ($data['meta']['schema_version'] ?? 1) >= 2 && ((int) ($forms['live_tests'] ?? 0) > 0 || (int) ($forms['lab_tests'] ?? 0) > 0))

CONTACT FORMS
@if ((int) ($forms['live_tests'] ?? 0) > 0)
{{ (int) $forms['live_tests'] }} test submissions, {{ $forms['pass_rate'] ?? 0 }}% delivered
@endif
@if ((int) ($forms['lab_tests'] ?? 0) > 0)
Forms accepted {{ (int) ($forms['lab_accepted'] ?? 0) }} of {{ (int) $forms['lab_tests'] }} automated check submissions (no email sent, so delivery wasn't tested)
@endif
@endif
@if (! empty($workLog['entries']))

ADDITIONAL WORK THIS MONTH
@foreach ($workLog['entries'] as $entry)
- {{ \Illuminate\Support\Carbon::parse($entry['worked_on'])->format('M j') }}: {{ $entry['description'] }} ({{ $entry['hours'] }}h)
@endforeach
@endif

Questions about anything in this report? Just reply to this email{{ $branding['support_email'] !== '' ? ' or write to '.$branding['support_email'] : '' }}.

— The {{ $branding['company_name'] }} team
