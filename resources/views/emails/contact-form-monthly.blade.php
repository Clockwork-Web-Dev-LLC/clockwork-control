<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>{{ $stats->monthLabel }} contact form report — {{ $domain }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#222;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f6f7f9;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="background:#ffffff;border-radius:8px;padding:32px;text-align:left;">
                <tr><td style="padding-bottom:16px;">
                    <div style="font-size:14px;color:#45515e;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;">{{ $stats->monthLabel }} report</div>
                </td></tr>
                <tr><td style="padding-bottom:12px;">
                    <h1 style="margin:0;font-size:22px;line-height:1.3;color:#18181b;font-weight:600;">Contact form check — {{ $domain }}</h1>
                </td></tr>

                @if ($stats->totalRuns === 0)
                    <tr><td style="padding-bottom:20px;font-size:15px;line-height:1.6;color:#45515e;">
                        We didn't run any tests against your contact form this month. If you signed up recently, this is normal — your first month report will land on the 1st.
                    </td></tr>
                @elseif ($stats->failedRuns === 0)
                    <tr><td style="padding-bottom:20px;font-size:15px;line-height:1.6;color:#45515e;">
                        We tested your contact form <strong>{{ $stats->totalRuns }}</strong> {{ \Illuminate\Support\Str::plural('time', $stats->totalRuns) }} this month. Every one passed — your form's been delivering messages reliably.
                    </td></tr>
                @else
                    <tr><td style="padding-bottom:20px;font-size:15px;line-height:1.6;color:#45515e;">
                        We tested your contact form <strong>{{ $stats->totalRuns }}</strong> {{ \Illuminate\Support\Str::plural('time', $stats->totalRuns) }} this month. <strong>{{ $stats->passedRuns }}</strong> passed, <strong>{{ $stats->failedRuns }}</strong> failed.
                    </td></tr>
                @endif

                <tr><td style="padding-bottom:20px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f6f7f9;border-radius:6px;padding:16px;">
                        <tr>
                            <td style="font-size:13px;color:#45515e;line-height:1.8;">
                                <strong style="color:#18181b;">Total tests:</strong> {{ $stats->totalRuns }}<br>
                                <strong style="color:#18181b;">Passed:</strong> {{ $stats->passedRuns }} ({{ $stats->passRatePct() }}%)<br>
                                <strong style="color:#18181b;">Failed:</strong> {{ $stats->failedRuns }}<br>
                                @if ($stats->longestOutageMinutes > 0)
                                    <strong style="color:#18181b;">Longest outage:</strong>
                                    @if ($stats->longestOutageMinutes < 60)
                                        {{ $stats->longestOutageMinutes }} minutes
                                    @elseif ($stats->longestOutageMinutes < 1440)
                                        {{ round($stats->longestOutageMinutes / 60, 1) }} hours
                                    @else
                                        {{ round($stats->longestOutageMinutes / 1440, 1) }} days
                                    @endif
                                    ({{ $stats->longestOutageStart?->format('M j') }} – {{ $stats->longestOutageEnd?->format('M j') }})<br>
                                @endif
                                <strong style="color:#18181b;">Last test:</strong> {{ $stats->lastRunAt?->format('Y-m-d H:i T') ?? '—' }} ({{ $stats->lastRunStatus ?? 'no runs' }})
                            </td>
                        </tr>
                    </table>
                </td></tr>

                <tr><td style="padding-top:16px;border-top:1px solid #e5e7eb;font-size:12px;color:#8e8e93;line-height:1.5;">
                    Sent by Clockwork on the 1st of each month.<br>
                    We only email between reports when something changes (form stops working, form recovers).
                </td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
