@php
    /*
     * Self-contained monthly client report email. Table layout + inline styles only
     * (Gmail/Outlook ignore <style> blocks and flex/grid). No links out of the email
     * except the support mailto — the client can't reach Control.
     */
    $primary = $branding['primary_color'];
    $accent = $branding['accent_color'];
    $font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $ink = '#0f172a';
    $muted = '#64748b';
    $line = '#e2e8f0';
    $soft = '#f8fafc';
    $good = '#047857';
    $warn = '#b45309';

    $meta = $data['meta'] ?? [];
    $updates = $data['updates'] ?? [];
    $uptime = $data['uptime'] ?? [];
    $security = $data['security'] ?? [];
    $perf = $data['performance'] ?? [];
    $forms = $data['forms'] ?? [];
    $traffic = $data['traffic'] ?? [];
    $backups = $data['backups'] ?? [];
    $workLog = $data['work_log'] ?? [];

    $period = $report->period_start->format('F Y');
    $range = $report->period_start->format('M j').' – '.$report->period_end->format('M j, Y');

    $showUptime = isset($data['uptime']) && ($uptime['monitored'] ?? true);
    $showForms = isset($data['forms']) && (int) ($forms['total_synthetic_tests'] ?? 0) > 0;
    $showPerf = isset($data['performance']) && ! empty($perf['has_performance']) && (! empty($perf['mobile']) || ! empty($perf['desktop']));
    $showTraffic = isset($data['traffic']) && ! empty($traffic['has_traffic']);
    $showWork = isset($data['work_log']) && ! empty($workLog['entries']);

    $updateCount = count($updateRows);
    $uptimePct = $uptime['uptime_percentage'] ?? null;
    $uptimeLabel = $uptimePct === null ? '—' : rtrim(rtrim(number_format((float) $uptimePct, 2), '0'), '.').'%';
    $downtime = (int) ($uptime['downtime_minutes'] ?? 0);
    $downtimeLabel = $downtime >= 60 ? floor($downtime / 60).'h '.($downtime % 60).'m' : $downtime.' min';
    $vulns = (int) ($security['active_vulnerabilities'] ?? 0);
    $checksum = (string) ($security['checksum_status'] ?? '');

    $scoreColor = fn ($s) => $s >= 90 ? $good : ($s >= 50 ? $warn : '#b91c1c');
    $bandwidth = (float) ($traffic['bandwidth_mb'] ?? 0);
    $bandwidthLabel = $bandwidth >= 1024 ? number_format($bandwidth / 1024, 1).' GB' : number_format($bandwidth, 0).' MB';

    // Tiles sit four across even on a phone, so long numbers are abbreviated there
    // (the section below still shows the exact figure).
    $compact = fn ($n) => $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 1), '0'), '.').'M'
        : ($n >= 10000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.').'K' : number_format($n));
    $tiles = [];
    if (isset($data['updates'])) $tiles[] = [$updateCount, 'Updates applied', $ink];
    if ($showUptime) $tiles[] = [$uptimeLabel, 'Uptime', $good];
    if (isset($data['security'])) $tiles[] = [$compact((int) ($security['total_scans'] ?? 0)), 'Security scans', $ink];
    if ($showTraffic) $tiles[] = [$compact((int) ($traffic['total_visits'] ?? 0)), 'Visits', $ink];
    elseif (isset($data['security'])) $tiles[] = [$compact((int) ($security['blocked_threats_count'] ?? 0)), 'Threats blocked', $ink];
    $tiles = array_slice($tiles, 0, 4);
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light only">
    <title>{{ $period }} website report — {{ $domain }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:{{ $font }}; color:{{ $ink }};">
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">
    {{ $period }} for {{ $domain }}: {{ $updateCount }} {{ \Illuminate\Support\Str::plural('update', $updateCount) }}{{ $showUptime ? ', '.$uptimeLabel.' uptime' : '' }} — full details inside.
</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:640px; background:#ffffff; border-radius:12px; overflow:hidden; border:1px solid {{ $line }};">

    {{-- Header: brand purple so the white logo is visible --}}
    <tr><td style="background:{{ $primary }}; padding:28px 32px 24px 32px;" bgcolor="{{ $primary }}">
        @if ($logoBytes)
            <img src="{{ $message->embedData($logoBytes, 'logo.png', 'image/png') }}" alt="{{ $branding['company_name'] }}" height="40" style="display:block; height:40px; width:auto; border:0; color:#ffffff; font-size:18px; font-weight:700;">
        @elseif ($branding['logo_url'] !== '')
            <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] }}" height="40" style="display:block; height:40px; width:auto; border:0; color:#ffffff; font-size:18px; font-weight:700;">
        @else
            <div style="color:#ffffff; font-size:20px; font-weight:800;">{{ $branding['company_name'] }}</div>
        @endif
        <div style="color:#ffffff; font-size:24px; font-weight:800; margin-top:20px; line-height:1.25;">Website Care Report</div>
        <div style="color:#ffffff; opacity:0.8; font-size:14px; margin-top:6px;">{{ $domain }} &nbsp;·&nbsp; {{ $range }}</div>
    </td></tr>
    <tr><td style="background:{{ $accent }}; height:4px; line-height:4px; font-size:0;" bgcolor="{{ $accent }}">&nbsp;</td></tr>

    {{-- Intro --}}
    <tr><td style="padding:28px 32px 8px 32px; font-size:15px; line-height:1.6; color:#334155;">
        <p style="margin:0 0 12px 0;">Hi{{ $contactName ? ' '.$contactName : '' }},</p>
        <p style="margin:0;">Here's your website care summary for <strong style="color:{{ $ink }};">{{ $domain }}</strong> for {{ $period }} — what we updated, how the site performed, and how we kept it secure.</p>
    </td></tr>

    @if (! empty($meta['custom_notes']))
        <tr><td style="padding:16px 32px 0 32px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                <td style="background:{{ $soft }}; border-left:4px solid {{ $primary }}; padding:14px 16px; font-size:14px; line-height:1.6; color:#334155;">
                    <div style="font-weight:700; color:{{ $ink }}; margin-bottom:4px;">A note from your support team</div>
                    {!! nl2br(e($meta['custom_notes'])) !!}
                </td>
            </tr></table>
        </td></tr>
    @endif

    {{-- At-a-glance tiles --}}
    @if (count($tiles))
        <tr><td style="padding:20px 28px 4px 28px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                @foreach ($tiles as [$value, $label, $color])
                    <td width="{{ floor(100 / count($tiles)) }}%" style="padding:4px;" valign="top">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                            <td align="center" style="background:{{ $soft }}; border:1px solid {{ $line }}; border-radius:10px; padding:14px 4px;">
                                <div style="font-size:20px; font-weight:800; color:{{ $color }}; line-height:1.2; white-space:nowrap;">{{ $value }}</div>
                                <div style="font-size:10px; font-weight:600; color:{{ $muted }}; text-transform:uppercase; letter-spacing:0.03em; margin-top:4px;">{{ $label }}</div>
                            </td>
                        </tr></table>
                    </td>
                @endforeach
            </tr></table>
        </td></tr>
    @endif

    {{-- Updates --}}
    @if (isset($data['updates']))
        <tr><td style="padding:28px 32px 0 32px;">
            <div style="font-size:17px; font-weight:800; color:{{ $ink }}; border-bottom:2px solid {{ $primary }}; padding-bottom:8px;">Updates applied</div>
            <p style="margin:10px 0 12px 0; font-size:13px; line-height:1.6; color:{{ $muted }};">Keeping WordPress, themes and plugins current closes known security holes and keeps everything compatible.</p>
            @if ($updateCount)
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px;">
                    @foreach ($updateRows as $row)
                        <tr>
                            <td style="padding:9px 0; border-bottom:1px solid {{ $line }}; color:{{ $ink }}; line-height:1.45;" valign="top">
                                <span style="display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:{{ $primary }}; background:#ede9fe; border-radius:4px; padding:2px 6px; margin-right:6px;">{{ $row['type'] }}</span>{{ $row['label'] }}
                            </td>
                            <td align="right" style="padding:9px 0 9px 12px; border-bottom:1px solid {{ $line }}; color:{{ $muted }}; white-space:nowrap;" valign="top">{{ $row['date'] ? \Illuminate\Support\Carbon::parse($row['date'])->format('M j') : '' }}</td>
                        </tr>
                    @endforeach
                </table>
            @else
                <p style="margin:0; font-size:14px; color:#334155;">Everything was already up to date this month — no updates were needed.</p>
            @endif
        </td></tr>
    @endif

    {{-- Uptime --}}
    @if ($showUptime)
        <tr><td style="padding:28px 32px 0 32px;">
            <div style="font-size:17px; font-weight:800; color:{{ $ink }}; border-bottom:2px solid {{ $primary }}; padding-bottom:8px;">Uptime &amp; availability</div>
            <p style="margin:10px 0 12px 0; font-size:13px; line-height:1.6; color:{{ $muted }};">We check your site around the clock and are alerted the moment it stops responding.</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;">
                <tr><td style="padding:8px 0; border-bottom:1px solid {{ $line }}; color:#334155;">Availability</td><td align="right" style="padding:8px 0; border-bottom:1px solid {{ $line }}; font-weight:700; color:{{ $good }};">{{ $uptimeLabel }}</td></tr>
                <tr><td style="padding:8px 0; border-bottom:1px solid {{ $line }}; color:#334155;">Outages</td><td align="right" style="padding:8px 0; border-bottom:1px solid {{ $line }}; font-weight:700; color:{{ $ink }};">{{ (int) ($uptime['outages_count'] ?? 0) }}</td></tr>
                <tr><td style="padding:8px 0; color:#334155;">Total downtime</td><td align="right" style="padding:8px 0; font-weight:700; color:{{ $ink }};">{{ $downtimeLabel }}</td></tr>
            </table>
        </td></tr>
    @endif

    {{-- Security --}}
    @if (isset($data['security']))
        <tr><td style="padding:28px 32px 0 32px;">
            <div style="font-size:17px; font-weight:800; color:{{ $ink }}; border-bottom:2px solid {{ $primary }}; padding-bottom:8px;">Security</div>
            <p style="margin:10px 0 12px 0; font-size:13px; line-height:1.6; color:{{ $muted }};">We scan for malware and tampered files, watch for known plugin vulnerabilities, and block attackers at the server.</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px;">
                <tr><td style="padding:8px 0; border-bottom:1px solid {{ $line }}; color:#334155;">Security scans run</td><td align="right" style="padding:8px 0; border-bottom:1px solid {{ $line }}; font-weight:700; color:{{ $ink }};">{{ number_format((int) ($security['total_scans'] ?? 0)) }}</td></tr>
                @if ($checksum !== '')
                    <tr><td style="padding:8px 0; border-bottom:1px solid {{ $line }}; color:#334155;">WordPress core files</td><td align="right" style="padding:8px 0; border-bottom:1px solid {{ $line }}; font-weight:700; color:{{ $checksum === 'clean' ? $good : $warn }};">{{ $checksum === 'clean' ? 'Verified clean' : ucfirst($checksum) }}</td></tr>
                @endif
                <tr><td style="padding:8px 0; border-bottom:1px solid {{ $line }}; color:#334155;">Known vulnerabilities</td><td align="right" style="padding:8px 0; border-bottom:1px solid {{ $line }}; font-weight:700; color:{{ $vulns === 0 ? $good : $warn }};">{{ $vulns === 0 ? 'None' : $vulns.' being addressed' }}</td></tr>
                <tr><td style="padding:8px 0; color:#334155;">Malicious visitors blocked</td><td align="right" style="padding:8px 0; font-weight:700; color:{{ $ink }};">{{ number_format((int) ($security['blocked_threats_count'] ?? 0)) }}</td></tr>
            </table>
        </td></tr>
    @endif

    {{-- Performance --}}
    @if ($showPerf)
        <tr><td style="padding:28px 32px 0 32px;">
            <div style="font-size:17px; font-weight:800; color:{{ $ink }}; border-bottom:2px solid {{ $primary }}; padding-bottom:8px;">Speed &amp; performance</div>
            <p style="margin:10px 0 12px 0; font-size:13px; line-height:1.6; color:{{ $muted }};">Google Lighthouse scores out of 100 — 90+ is excellent. "Load time" is how long the main content takes to appear.</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                @foreach (['mobile' => 'Mobile', 'desktop' => 'Desktop'] as $key => $label)
                    @if (! empty($perf[$key]))
                        @php $p = $perf[$key]; $score = (int) ($p['score'] ?? 0); @endphp
                        <td width="50%" style="padding:0 6px 0 0;" valign="top">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                                <td align="center" style="background:{{ $soft }}; border:1px solid {{ $line }}; border-radius:10px; padding:16px 8px;">
                                    <div style="font-size:11px; font-weight:600; color:{{ $muted }}; text-transform:uppercase; letter-spacing:0.04em;">{{ $label }}</div>
                                    <div style="font-size:28px; font-weight:800; color:{{ $scoreColor($score) }}; margin-top:4px;">{{ $score }}<span style="font-size:14px; color:{{ $muted }}; font-weight:600;">/100</span></div>
                                    @if (! empty($p['lcp_ms']))
                                        <div style="font-size:12px; color:{{ $muted }}; margin-top:2px;">Load time {{ number_format($p['lcp_ms'] / 1000, 1) }}s</div>
                                    @endif
                                    @if (! empty($p['scanned_at']))
                                        <div style="font-size:11px; color:#94a3b8; margin-top:2px;">Tested {{ \Illuminate\Support\Carbon::parse($p['scanned_at'])->format('M j, Y') }}</div>
                                    @endif
                                </td>
                            </tr></table>
                        </td>
                    @endif
                @endforeach
            </tr></table>
        </td></tr>
    @endif

    {{-- Traffic --}}
    @if ($showTraffic)
        <tr><td style="padding:28px 32px 0 32px;">
            <div style="font-size:17px; font-weight:800; color:{{ $ink }}; border-bottom:2px solid {{ $primary }}; padding-bottom:8px;">Traffic</div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:14px; margin-top:8px;">
                <tr><td style="padding:8px 0; border-bottom:1px solid {{ $line }}; color:#334155;">Visits</td><td align="right" style="padding:8px 0; border-bottom:1px solid {{ $line }}; font-weight:700; color:{{ $ink }};">{{ number_format((int) ($traffic['total_visits'] ?? 0)) }}</td></tr>
                <tr><td style="padding:8px 0; border-bottom:1px solid {{ $line }}; color:#334155;">Page &amp; file requests served</td><td align="right" style="padding:8px 0; border-bottom:1px solid {{ $line }}; font-weight:700; color:{{ $ink }};">{{ number_format((int) ($traffic['total_requests'] ?? 0)) }}</td></tr>
                <tr><td style="padding:8px 0; color:#334155;">Data transferred</td><td align="right" style="padding:8px 0; font-weight:700; color:{{ $ink }};">{{ $bandwidthLabel }}</td></tr>
            </table>
        </td></tr>
    @endif

    {{-- Contact forms --}}
    @if ($showForms)
        <tr><td style="padding:28px 32px 0 32px;">
            <div style="font-size:17px; font-weight:800; color:{{ $ink }}; border-bottom:2px solid {{ $primary }}; padding-bottom:8px;">Contact forms</div>
            <p style="margin:10px 0 0 0; font-size:14px; line-height:1.6; color:#334155;">We sent <strong>{{ (int) $forms['total_synthetic_tests'] }}</strong> test {{ \Illuminate\Support\Str::plural('submission', (int) $forms['total_synthetic_tests']) }} through your forms to confirm messages reach you — <strong style="color:{{ ($forms['pass_rate'] ?? 0) >= 100 ? $good : $warn }};">{{ $forms['pass_rate'] ?? 0 }}% delivered</strong>.</p>
        </td></tr>
    @endif

    {{-- Backups --}}
    @if (isset($data['backups']) && ($backups['enabled'] ?? true))
        <tr><td style="padding:28px 32px 0 32px;">
            <div style="font-size:17px; font-weight:800; color:{{ $ink }}; border-bottom:2px solid {{ $primary }}; padding-bottom:8px;">Backups</div>
            <p style="margin:10px 0 0 0; font-size:14px; line-height:1.6; color:#334155;">
                Your site's files and database are backed up automatically{{ ! empty($backups['destination']) ? ' ('.$backups['destination'].')' : '' }}, so it can be restored if anything goes wrong.
                @if (! empty($backups['last_backup_at']))
                    Most recent backup: <strong>{{ \Illuminate\Support\Carbon::parse($backups['last_backup_at'])->format('M j, Y') }}</strong>.
                @endif
            </p>
        </td></tr>
    @endif

    {{-- Work log --}}
    @if ($showWork)
        <tr><td style="padding:28px 32px 0 32px;">
            <div style="font-size:17px; font-weight:800; color:{{ $ink }}; border-bottom:2px solid {{ $primary }}; padding-bottom:8px;">Additional work this month <span style="font-size:13px; font-weight:600; color:{{ $muted }};">· {{ rtrim(rtrim(number_format((float) ($workLog['total_hours'] ?? 0), 2), '0'), '.') }} hrs</span></div>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:13px; margin-top:8px;">
                @foreach ($workLog['entries'] as $entry)
                    <tr>
                        <td style="padding:8px 12px 8px 0; border-bottom:1px solid {{ $line }}; color:{{ $muted }}; white-space:nowrap;" valign="top">{{ \Illuminate\Support\Carbon::parse($entry['worked_on'])->format('M j') }}</td>
                        <td style="padding:8px 0; border-bottom:1px solid {{ $line }}; color:{{ $ink }}; line-height:1.5;" valign="top">{{ $entry['description'] }}</td>
                        <td align="right" style="padding:8px 0 8px 12px; border-bottom:1px solid {{ $line }}; color:{{ $ink }}; font-weight:700; white-space:nowrap;" valign="top">{{ rtrim(rtrim(number_format((float) $entry['hours'], 2), '0'), '.') }}h</td>
                    </tr>
                @endforeach
            </table>
        </td></tr>
    @endif

    @if ($branding['footer_text'] !== '')
        <tr><td style="padding:24px 32px 0 32px; font-size:13px; line-height:1.6; color:#334155;">{!! nl2br(e($branding['footer_text'])) !!}</td></tr>
    @endif

    {{-- Sign-off --}}
    <tr><td style="padding:28px 32px 28px 32px; font-size:14px; line-height:1.6; color:#334155;">
        Questions about anything in this report? Just reply to this email
        @if ($branding['support_email'] !== '')
            or write to <a href="mailto:{{ $branding['support_email'] }}" style="color:{{ $primary }}; font-weight:600;">{{ $branding['support_email'] }}</a>.
        @else
            .
        @endif
        <div style="margin-top:14px;">— The {{ $branding['company_name'] }} team</div>
    </td></tr>

    <tr><td style="background:{{ $soft }}; border-top:1px solid {{ $line }}; padding:16px 32px; font-size:11px; line-height:1.5; color:#94a3b8;">
        Report period {{ $range }} · {{ $domain }} · Prepared by {{ $branding['company_name'] }}
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
