<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Contact form working again on {{ $domain }}</title>
</head>
<body style="margin:0;padding:0;background:#f6f7f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#222;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f6f7f9;padding:32px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="560" style="background:#ffffff;border-radius:8px;padding:32px;text-align:left;">
                <tr><td style="padding-bottom:16px;">
                    <div style="font-size:14px;color:#16a34a;font-weight:600;letter-spacing:0.05em;text-transform:uppercase;">✓ Recovered</div>
                </td></tr>
                <tr><td style="padding-bottom:12px;">
                    <h1 style="margin:0;font-size:22px;line-height:1.3;color:#18181b;font-weight:600;">Your contact form on {{ $domain }} is working again.</h1>
                </td></tr>
                <tr><td style="padding-bottom:20px;font-size:15px;line-height:1.6;color:#45515e;">
                    Our daily test just passed. Visitor messages are flowing through normally again.
                </td></tr>
                <tr><td style="padding-bottom:20px;font-size:13px;color:#8e8e93;line-height:1.6;">
                    <strong style="color:#18181b;">Recovered:</strong> {{ $recoveredAt?->format('Y-m-d H:i T') ?? 'just now' }}
                </td></tr>
                <tr><td style="padding-top:16px;border-top:1px solid #e5e7eb;font-size:12px;color:#8e8e93;line-height:1.5;">
                    Sent by Clockwork — your hosting + maintenance care plan.<br>
                    We'll keep checking daily and only email you when something changes.
                </td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
