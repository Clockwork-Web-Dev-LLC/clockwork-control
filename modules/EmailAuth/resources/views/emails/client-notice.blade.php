@php
    /*
     * Client notice about a domain's email authentication. Same table-and-inline-style
     * construction and branding as the monthly client report email.
     */
    $primary = $branding['primary_color'];
    $accent = $branding['accent_color'];
    $font = "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
    $ink = '#0f172a';
    $muted = '#64748b';
    $line = '#e2e8f0';
    $soft = '#f8fafc';
    $headerText = '#DCD8EA';
    // Zero-width non-joiner before each dot stops mail clients auto-linking the domain.
    $plainDomain = str_replace('.', '&zwnj;.', e($domain));
    $pill = [
        'fail' => ['Needs attention', '#b91c1c', '#fee2e2'],
        'warn' => ['Recommended', '#b45309', '#fef3c7'],
        'info' => ['For your information', '#475569', '#f1f5f9'],
    ];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="light only">
    <meta name="format-detection" content="telephone=no, date=no, address=no, email=no, url=no">
    <style>
        a[x-apple-data-detectors], .cw-nolink a { color: inherit !important; text-decoration: none !important; font-size: inherit !important; font-weight: inherit !important; }
    </style>
    <title>Email setup — {{ $domain }}</title>
</head>
<body style="margin:0; padding:0; background:#f1f5f9; font-family:{{ $font }}; color:{{ $ink }};">
<div style="display:none; max-height:0; overflow:hidden; opacity:0;">{{ \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', $note)), 140) }}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f1f5f9;">
<tr><td align="center" style="padding:24px 12px;">
<table role="presentation" width="640" cellpadding="0" cellspacing="0" border="0" style="width:100%; max-width:640px; background:#ffffff; border-radius:12px; overflow:hidden; border:1px solid {{ $line }};">

    <tr><td style="background:{{ $primary }}; padding:28px 32px 24px 32px;" bgcolor="{{ $primary }}">
        @if ($logoBytes)
            <img src="{{ $message->embedData($logoBytes, 'logo.png', 'image/png') }}" alt="{{ $branding['company_name'] }}" height="40" style="display:block; height:40px; width:auto; border:0; color:#ffffff; font-size:18px; font-weight:700;">
        @elseif ($branding['logo_url'] !== '')
            <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] }}" height="40" style="display:block; height:40px; width:auto; border:0; color:#ffffff; font-size:18px; font-weight:700;">
        @else
            <div style="color:#ffffff; font-size:20px; font-weight:800;">{{ $branding['company_name'] }}</div>
        @endif
        <div style="color:#ffffff; font-size:24px; font-weight:800; margin-top:20px; line-height:1.25;">Your email setup</div>
        <div class="cw-nolink" style="color:{{ $headerText }}; font-size:14px; margin-top:6px;"><span style="color:{{ $headerText }}; text-decoration:none;">{!! $plainDomain !!}</span></div>
    </td></tr>
    <tr><td style="background:{{ $accent }}; height:4px; line-height:4px; font-size:0;" bgcolor="{{ $accent }}">&nbsp;</td></tr>

    {{-- Operator's note --}}
    <tr><td style="padding:28px 32px 4px 32px; font-size:15px; line-height:1.65; color:#334155;">
        <p style="margin:0 0 14px 0;">Hi{{ $greetingName ? ' '.$greetingName : '' }},</p>
        <div style="margin:0;">{!! nl2br(e(trim($note))) !!}</div>
    </td></tr>

    @if (count($items))
        {{-- Plain-English summary --}}
        <tr><td style="padding:24px 32px 0 32px;">
            <div style="font-size:17px; font-weight:800; color:{{ $ink }}; border-bottom:2px solid {{ $primary }}; padding-bottom:8px;">What we found</div>
        </td></tr>
        @foreach ($items as $item)
            @php [$label, $fg, $bg] = $pill[$item['severity']] ?? $pill['info']; @endphp
            <tr><td style="padding:14px 32px 0 32px;">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
                    <td style="border:1px solid {{ $line }}; border-left:4px solid {{ $fg }}; border-radius:8px; padding:14px 16px; background:#ffffff;">
                        <span style="display:inline-block; font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:0.04em; color:{{ $fg }}; background:{{ $bg }}; border-radius:4px; padding:2px 7px;">{{ $label }}</span>
                        <div style="font-size:15px; font-weight:700; color:{{ $ink }}; margin-top:8px;">{{ $item['title'] }}</div>
                        <div style="font-size:14px; line-height:1.6; color:#334155; margin-top:4px;">{{ $item['explanation'] }}</div>
                    </td>
                </tr></table>
            </td></tr>
        @endforeach
    @endif

    {{-- Call to action: reply --}}
    <tr><td style="padding:24px 32px 0 32px;">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
            <td style="background:{{ $soft }}; border:1px solid {{ $line }}; border-radius:10px; padding:16px 18px; font-size:14px; line-height:1.6; color:#334155;">
                <strong style="color:{{ $ink }};">Want us to take care of this?</strong> Just reply to this email and we’ll handle it for you.
            </td>
        </tr></table>
    </td></tr>

    @if (count($items))
        {{-- Technical details for whoever manages DNS --}}
        <tr><td style="padding:28px 32px 0 32px;">
            <div style="font-size:15px; font-weight:800; color:{{ $ink }}; border-bottom:1px solid {{ $line }}; padding-bottom:8px;">Technical details</div>
            <p style="margin:8px 0 10px 0; font-size:12px; line-height:1.5; color:{{ $muted }};">For whoever manages your domain’s DNS. Checked {{ $check->checked_at?->format('M j, Y') }}.</p>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:12px;">
                @foreach ($items as $item)
                    <tr>
                        <td style="padding:7px 10px 7px 0; border-bottom:1px solid {{ $line }}; color:{{ $muted }}; font-weight:700; white-space:nowrap;" valign="top">{{ $item['check'] }}</td>
                        <td style="padding:7px 0; border-bottom:1px solid {{ $line }}; color:#334155; line-height:1.5;" valign="top">{{ $item['technical'] }} <span style="color:#94a3b8; font-family:Menlo, Consolas, monospace;">({{ $item['code'] }})</span></td>
                    </tr>
                @endforeach
            </table>
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="font-size:12px; margin-top:14px;">
                <tr>
                    <td style="padding:6px 10px 6px 0; color:{{ $muted }}; font-weight:700; white-space:nowrap;" valign="top">SPF record</td>
                    <td class="cw-nolink" style="padding:6px 0; font-family:Menlo, Consolas, monospace; color:{{ $ink }}; word-break:break-all;" valign="top">{{ $check->spf_record ?: 'None published' }}@if ($check->spf_record) <span style="color:{{ $muted }}; font-family:{{ $font }};">· {{ (int) $check->spf_lookup_count }}/10 DNS lookups</span>@endif</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px 6px 0; color:{{ $muted }}; font-weight:700; white-space:nowrap;" valign="top">DMARC record</td>
                    <td class="cw-nolink" style="padding:6px 0; font-family:Menlo, Consolas, monospace; color:{{ $ink }}; word-break:break-all;" valign="top">{{ $check->dmarc_record ?: 'None published' }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px 6px 0; color:{{ $muted }}; font-weight:700; white-space:nowrap;" valign="top">DKIM</td>
                    <td style="padding:6px 0; color:{{ $ink }};" valign="top">{{ ! empty($check->dkim_selectors_found) ? 'Found: '.implode(', ', (array) $check->dkim_selectors_found) : 'No key found on common selectors' }}</td>
                </tr>
                <tr>
                    <td style="padding:6px 10px 6px 0; color:{{ $muted }}; font-weight:700; white-space:nowrap;" valign="top">Mail servers (MX)</td>
                    <td style="padding:6px 0; color:{{ $ink }};" valign="top">{{ $check->mx_present ? 'Present' : 'None' }}</td>
                </tr>
            </table>
        </td></tr>
    @endif

    <tr><td style="padding:28px 32px 28px 32px; font-size:14px; line-height:1.6; color:#334155;">
        Thanks,
        <div>— The {{ $branding['company_name'] }} team</div>
        @if ($branding['support_email'] !== '')
            <div style="margin-top:10px; font-size:13px; color:{{ $muted }};">Questions? Reply here or write to <a href="mailto:{{ $branding['support_email'] }}" style="color:{{ $primary }}; font-weight:600;">{{ $branding['support_email'] }}</a>.</div>
        @endif
    </td></tr>

    <tr><td style="background:{{ $soft }}; border-top:1px solid {{ $line }}; padding:16px 32px; font-size:11px; line-height:1.5; color:#94a3b8;">
        <span class="cw-nolink">Email authentication review for <span style="color:#94a3b8; text-decoration:none;">{!! $plainDomain !!}</span> · Prepared by {{ $branding['company_name'] }}</span>
    </td></tr>
</table>
</td></tr>
</table>
</body>
</html>
