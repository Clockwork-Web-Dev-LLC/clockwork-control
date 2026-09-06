<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contact form not working on {{ $domain }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#222;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f6f7f9;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="background:#ffffff;border-radius:8px;padding:32px;text-align:left;">
                <tr><td style="padding-bottom:16px;">
                    <div style="font-size:14px;color:#dc2626;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;">⚠ Contact form alert</div>
                </td></tr>
                <tr><td style="padding-bottom:12px;">
                    <h1 style="margin:0;font-size:22px;line-height:1.3;color:#18181b;font-weight:600;">Your contact form on {{ $domain }} stopped working.</h1>
                </td></tr>
                <tr><td style="padding-bottom:20px;font-size:15px;line-height:1.6;color:#45515e;">
                    Our daily test just failed for the second time in a row. New visitor messages may not be reaching you right now.
                </td></tr>
                <tr><td style="padding-bottom:20px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#fef2f2;border-radius:6px;padding:16px;">
                        <tr><td style="font-size:13px;color:#45515e;line-height:1.6;">
                            <strong style="color:#18181b;">What we saw:</strong><br>
                            {{ $reason }}<br><br>
                            <strong style="color:#18181b;">Last checked:</strong> {{ $lastTestAt?->format('Y-m-d H:i T') ?? 'unknown' }}<br>
                            <strong style="color:#18181b;">Consecutive failures:</strong> {{ $streak }}
                        </td></tr>
                    </table>
                </td></tr>
                <tr><td style="padding-bottom:20px;font-size:15px;line-height:1.6;color:#45515e;">
                    We're already on it — no action needed from you. We'll email again as soon as it's working again.
                </td></tr>
                <tr><td style="padding-top:16px;border-top:1px solid #e5e7eb;font-size:12px;color:#8e8e93;line-height:1.5;">
                    Sent by Clockwork — your hosting + maintenance care plan.<br>
                    If you'd rather not receive these notifications, reply and let us know.
                </td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
