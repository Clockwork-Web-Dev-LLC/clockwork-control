<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Resolved: {{ $domain }} is back online</title>
</head>
<body style="margin:0;padding:0;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#222;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f6f7f9;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="background:#ffffff;border-radius:8px;padding:32px;text-align:left;box-shadow:0 1px 3px rgba(0,0,0,0.05);">
                <tr><td style="padding-bottom:16px;">
                    <div style="font-size:13px;color:#16a34a;font-weight:700;letter-spacing:0.05em;text-transform:uppercase;">✓ Service Restored</div>
                </td></tr>
                <tr><td style="padding-bottom:12px;">
                    <h1 style="margin:0;font-size:22px;line-height:1.3;color:#18181b;font-weight:600;">Good news: {{ $domain }} is back online.</h1>
                </td></tr>
                <tr><td style="padding-bottom:20px;font-size:15px;line-height:1.6;color:#45515e;">
                    Our monitoring system confirmed that <a href="https://{{ $domain }}" style="color:#0284c7;text-decoration:none;font-weight:500;">{{ $domain }}</a> has successfully recovered and is responding normally.
                </td></tr>
                <tr><td style="padding-bottom:20px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f0fdf4;border:1px solid #dcfce7;border-radius:6px;padding:16px;">
                        <tr><td style="font-size:13px;color:#45515e;line-height:1.6;">
                            <strong style="color:#18181b;">Website:</strong> {{ $domain }}<br>
                            <strong style="color:#18181b;">Status:</strong> Operational (HTTP 200 OK)<br>
                            <strong style="color:#18181b;">Recovered at:</strong> {{ $recoveredAt->format('M j, Y H:i T') }}<br>
                            @if($downtimeMin > 0)
                                <strong style="color:#18181b;">Total downtime:</strong> {{ $downtimeMin }} minute{{ $downtimeMin === 1 ? '' : 's' }}
                            @elseif($downtimeSec !== null)
                                <strong style="color:#18181b;">Total downtime:</strong> {{ $downtimeSec }} second{{ $downtimeSec === 1 ? '' : 's' }}
                            @endif
                        </td></tr>
                    </table>
                </td></tr>
                <tr><td style="padding-bottom:20px;font-size:15px;line-height:1.6;color:#45515e;">
                    All systems are operational. We will continue monitoring the site closely.
                </td></tr>
                <tr><td style="padding-top:16px;border-top:1px solid #e5e7eb;font-size:12px;color:#8e8e93;line-height:1.5;">
                    Sent by Clockwork Monitoring.<br>
                    You are receiving this notification because you are subscribed to status updates for {{ $domain }}.
                </td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
