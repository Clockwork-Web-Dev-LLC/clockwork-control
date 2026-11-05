@php
    // The text/plain part of the email, so every echo is raw: an escaped echo
    // would show the client "O&#039;Brien &amp; Sons" for O'Brien & Sons.
    $updates = $data['updates'] ?? [];
    $uptime = $data['uptime'] ?? [];
    $security = $data['security'] ?? [];
    $perf = $data['performance'] ?? [];
    $traffic = $data['traffic'] ?? [];
    $forms = $data['forms'] ?? [];
    $workLog = $data['work_log'] ?? [];
    $period = $report->period_start->format('F Y');
    // Which sections the client sees: one rule for this, the HTML part and the preview.
    $sections = \Modules\ClientReports\Support\ReportSections::visibility($data);
    // A site enrolled part-way through the month: say when monitoring began.
    $coverageNote = \Modules\ClientReports\Support\ReportPhrases::coverageNote($data);
    $monitoredSince = \Modules\ClientReports\Support\ReportPhrases::monitoringBeganOn($uptime['monitored_since'] ?? null);
@endphp
WEBSITE CARE REPORT — {!! $domain !!}
{!! $period !!} ({!! $report->period_start->format('M j') !!} – {!! $report->period_end->format('M j, Y') !!})

Hi {!! $greetingName ?: 'there' !!},

Here's your website care summary for {!! $domain !!} for {!! $period !!}.
@if ($coverageNote)
{!! $coverageNote !!}
@endif
@if (! empty($data['meta']['custom_notes']))

A NOTE FROM YOUR SUPPORT TEAM
{!! $data['meta']['custom_notes'] !!}
@endif
@if ($sections['updates'])

UPDATES APPLIED ({!! count($updateRows) !!})
@forelse ($updateRows as $row)
- [{!! $row['type'] !!}] {!! $row['label'] !!}{!! $row['date'] ? ' ('.\Illuminate\Support\Carbon::parse($row['date'])->format('M j').')' : '' !!}
@empty
{!! \Modules\ClientReports\Support\ReportPhrases::noUpdatesLine($updates) !!}
@endforelse
@endif
@if ($sections['uptime'])

UPTIME
Availability: {!! $uptime['uptime_percentage'] ?? '—' !!}%{!! $monitoredSince ? ' (monitoring began '.$monitoredSince.')' : '' !!}
Outages: {!! (int) ($uptime['outages_count'] ?? 0) !!}
Total downtime: {!! (int) ($uptime['downtime_minutes'] ?? 0) !!} min
@foreach ($uptime['outages'] ?? [] as $outage)
- {!! \Modules\ClientReports\Support\ReportPhrases::outageStart($outage) !!} for {!! (int) $outage['minutes'] < 1 ? 'under a minute' : $outage['minutes'].' min' !!}{!! ! empty($outage['ongoing']) ? ' (still down at period end)' : '' !!}
@endforeach
@if ((int) ($uptime['outages_count'] ?? 0) > count($uptime['outages'] ?? []) && ! empty($uptime['outages']))
(Showing the {!! count($uptime['outages']) !!} most recent of {!! (int) $uptime['outages_count'] !!} outages.)
@endif
@endif
@if ($sections['security'])

SECURITY
Security scans run: {!! number_format((int) ($security['total_scans'] ?? 0)) !!}
@if (array_key_exists('blacklist_status', $security))
Spam & malware blocklists: {!! match ($security['blacklist_status']) { 'clean' => 'Not listed', 'issues_found', 'warning' => 'Listed — under review', null => 'Not checked this month', default => ucfirst((string) $security['blacklist_status']) } !!}
@endif
@if (array_key_exists('malware_status', $security))
Malware scan: {!! match ($security['malware_status']) { 'clean' => 'No malware found', 'warning', 'issues_found' => 'Issues flagged', 'incomplete' => 'Partial scan', null => 'Not checked this month', default => ucfirst((string) $security['malware_status']) } !!}
@endif
@if ((bool) ($data['site']['is_wordpress'] ?? true) && (bool) ($security['checksum_supported'] ?? true))
WordPress core files: {!! match ($security['checksum_status'] ?? null) { 'clean' => 'Verified clean', 'issues_found', 'warning' => 'Unexpected changes found', null => 'Not checked this month', default => ucfirst((string) $security['checksum_status']) } !!}
@endif
@php
    $vulnsCheckedOn = \Modules\ClientReports\Support\ReportPhrases::vulnerabilitiesCheckedOn($security);
    $vulnPluginNames = \Modules\ClientReports\Support\ReportPhrases::vulnerablePlugins($security);
@endphp
Known plugin vulnerabilities{!! $vulnsCheckedOn ? ' (checked '.$vulnsCheckedOn.')' : '' !!}: {!! \Modules\ClientReports\Support\ReportPhrases::vulnerabilities($security) !!}
@if ($vulnPluginNames !== [])
{!! \Illuminate\Support\Str::plural('Plugin', count($vulnPluginNames)) !!} affected: {!! implode(', ', $vulnPluginNames) !!}
@endif
@if (($security['blocked_threats_count'] ?? null) !== null)
Malicious visitors blocked: {!! number_format((int) $security['blocked_threats_count']) !!}
@endif
@endif
@if ($sections['backups'])
@php($backupDestination = \Modules\ClientReports\Support\ReportPhrases::backupDestination($data['backups']))

BACKUPS
@if (isset($data['backups']['status']))
@if ($data['backups']['covers_period'] ?? true)
Backed up on {!! (int) $data['backups']['days_with_backups'] !!} {!! \Illuminate\Support\Str::plural('day', (int) $data['backups']['days_with_backups']) !!} this month{!! $backupDestination ? ' '.$backupDestination : '' !!}. Most recent:
@else
Backed up{!! $backupDestination ? ' '.$backupDestination : '' !!}. Most recent:
@endif
@foreach ($data['backups']['recent'] as $day)
- {!! \Illuminate\Support\Carbon::parse($day['date'])->format('D, M j') !!}{!! ! empty($day['manual']) ? ' (manual)' : '' !!}
@endforeach
@else
Your site's files and database were backed up to independent off-site storage this month.{!! ! empty($data['backups']['last_backup_at']) ? ' Most recent backup: '.\Illuminate\Support\Carbon::parse($data['backups']['last_backup_at'])->format('M j, Y').'.' : '' !!}
@endif
@endif
@if ($sections['performance'])

SPEED & PERFORMANCE (Google Lighthouse, out of 100)
@foreach (['mobile' => 'Mobile', 'desktop' => 'Desktop'] as $key => $label)
@if (! empty($perf[$key]))
{!! $label !!}: {!! $perf[$key]['score'] !!}/100{!! ! empty($perf[$key]['scanned_at']) ? ' (tested '.\Illuminate\Support\Carbon::parse($perf[$key]['scanned_at'])->format('M j, Y').')' : '' !!}
@endif
@endforeach
@endif
@if ($sections['traffic'])

TRAFFIC
Visits: {!! number_format((int) ($traffic['total_visits'] ?? 0)) !!}
Requests served: {!! number_format((int) ($traffic['total_requests'] ?? 0)) !!}
@endif
@if ($sections['forms'])

CONTACT FORMS
@if ((int) ($forms['live_tests'] ?? 0) > 0)
{!! (int) $forms['live_tests'] !!} live test submissions, notification email reported sent for {!! $forms['pass_rate'] ?? 0 !!}% (inbox delivery not checked)
@endif
@if ((int) ($forms['lab_tests'] ?? 0) > 0)
Forms accepted {!! (int) ($forms['lab_accepted'] ?? 0) !!} of {!! (int) $forms['lab_tests'] !!} automated check submissions (no email sent, so the email wasn't tested)
@endif
@endif
@if ($sections['work_log'])

ADDITIONAL WORK THIS MONTH
@foreach ($workLog['entries'] as $entry)
- {!! \Illuminate\Support\Carbon::parse($entry['worked_on'])->format('M j') !!}: {!! $entry['description'] !!} ({!! $entry['hours'] !!}h)
@endforeach
@endif
@if ($branding['footer_text'] !== '')

{!! $branding['footer_text'] !!}
@endif

Questions about anything in this report? Just reply to this email{!! $branding['support_email'] !== '' ? ' or write to '.$branding['support_email'] : '' !!}.

— The {!! $branding['company_name'] !!} team
