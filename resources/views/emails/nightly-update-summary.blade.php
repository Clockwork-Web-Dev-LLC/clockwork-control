<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Nightly auto-updates — {{ $runDate->format('Y-m-d') }}</title>
</head>
<body style="margin:0;padding:0;background:#F1EEFF;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,'Helvetica Neue',Arial,sans-serif;color:#212025;-webkit-font-smoothing:antialiased;">
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#F1EEFF;padding:24px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="720" style="max-width:720px;width:100%;background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 1px 2px rgba(45,32,98,0.08);">
                @php
                    $sn = count($succeeded);
                    $fn = count($failed);
                    $kn = count($skipped);
                    $cn = count($cancelled);
                    $hadFailures = $fn > 0;
                    $eyebrowColor = $hadFailures ? '#b91c1c' : '#1f8a3b';
                    $eyebrowBg = $hadFailures ? '#fde8e8' : '#DCFFDD';
                    $eyebrowText = $hadFailures ? '⚠ Some failures' : '✓ All green';
                    // Group rows by domain so one site = one block. Preserves the
                    // order in which sites first appeared in each section (the
                    // command already feeds them sorted by domain).
                    $groupByDomain = function (array $rows): array {
                        $out = [];
                        foreach ($rows as $r) {
                            $d = $r['domain'] ?? '(unknown)';
                            $out[$d] ??= [];
                            $out[$d][] = $r;
                        }
                        return $out;
                    };
                    $succeededByDomain = $groupByDomain($succeeded);
                    $failedByDomain    = $groupByDomain($failed);
                    $skippedByDomain   = $groupByDomain($skipped);
                    $cancelledByDomain = $groupByDomain($cancelled);
                @endphp

                {{-- Brand header — deep purple band, clockworkWD wordmark, lime accent strip below. --}}
                <tr><td style="background:#2D2062;padding:20px 28px;">
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0">
                        <tr>
                            <td style="font-size:24px;font-weight:800;letter-spacing:-0.02em;color:#ffffff;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;line-height:1;vertical-align:middle;">
                                clockwork<span style="display:inline-block;background:#7EFF83;color:#2D2062;font-weight:800;font-size:12px;padding:3px 8px;border-radius:6px;margin-left:6px;letter-spacing:0;vertical-align:middle;">WD</span>
                            </td>
                            <td style="padding-left:14px;font-size:11px;letter-spacing:0.18em;color:rgba(255,255,255,0.55);text-transform:uppercase;font-weight:600;vertical-align:middle;">
                                Nightly Auto-Updates
                            </td>
                        </tr>
                    </table>
                </td></tr>
                <tr><td style="height:4px;background:#7EFF83;line-height:4px;font-size:0;">&nbsp;</td></tr>

                {{-- Body --}}
                <tr><td style="padding:28px 32px 32px 32px;text-align:left;">

                    {{-- Status pill --}}
                    <div style="display:inline-block;background:{{ $eyebrowBg }};color:{{ $eyebrowColor }};font-size:12px;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;padding:5px 12px;border-radius:9999px;margin-bottom:14px;">
                        {{ $eyebrowText }}
                    </div>

                    <h1 style="margin:0 0 6px 0;font-size:24px;line-height:1.25;color:#2D2062;font-weight:700;letter-spacing:-0.01em;">
                        Nightly auto-updates · {{ $runDate->format('M j, Y') }}
                    </h1>
                    <p style="margin:0 0 22px 0;font-size:14px;color:#5b5566;">
                        {{ $sitesTouched }} {{ $sitesTouched === 1 ? 'site' : 'sites' }} touched · plugin updates only
                    </p>

                    {{-- Counters strip --}}
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="margin-bottom:28px;">
                        <tr>
                            <td width="25%" style="background:#DCFFDD;border-radius:8px;padding:16px 12px;text-align:center;">
                                <div style="font-size:26px;font-weight:800;color:#1f8a3b;line-height:1;">{{ $sn }}</div>
                                <div style="font-size:11px;color:#1f8a3b;text-transform:uppercase;letter-spacing:0.08em;font-weight:700;margin-top:6px;">Succeeded</div>
                            </td>
                            <td width="2%"></td>
                            <td width="25%" style="background:{{ $hadFailures ? '#fde8e8' : '#F1EEFF' }};border-radius:8px;padding:16px 12px;text-align:center;">
                                <div style="font-size:26px;font-weight:800;color:{{ $hadFailures ? '#b91c1c' : '#8a8294' }};line-height:1;">{{ $fn }}</div>
                                <div style="font-size:11px;color:{{ $hadFailures ? '#b91c1c' : '#8a8294' }};text-transform:uppercase;letter-spacing:0.08em;font-weight:700;margin-top:6px;">Failed</div>
                            </td>
                            <td width="2%"></td>
                            <td width="25%" style="background:#fff7e6;border-radius:8px;padding:16px 12px;text-align:center;">
                                <div style="font-size:26px;font-weight:800;color:#b87b00;line-height:1;">{{ $kn }}</div>
                                <div style="font-size:11px;color:#b87b00;text-transform:uppercase;letter-spacing:0.08em;font-weight:700;margin-top:6px;">Skipped</div>
                            </td>
                            <td width="2%"></td>
                            <td width="25%" style="background:#F1EEFF;border-radius:8px;padding:16px 12px;text-align:center;">
                                <div style="font-size:26px;font-weight:800;color:#5b5566;line-height:1;">{{ $cn }}</div>
                                <div style="font-size:11px;color:#5b5566;text-transform:uppercase;letter-spacing:0.08em;font-weight:700;margin-top:6px;">Cancelled</div>
                            </td>
                        </tr>
                    </table>

                    {{-- Failures section, prioritised. Grouped by site. --}}
                    @if ($fn > 0)
                        <h2 style="margin:0 0 12px 0;font-size:16px;color:#2D2062;font-weight:700;letter-spacing:0.01em;text-transform:uppercase;">
                            Failures <span style="color:#b91c1c;">({{ count($failedByDomain) }} {{ count($failedByDomain) === 1 ? 'site' : 'sites' }}, {{ $fn }} {{ $fn === 1 ? 'plugin' : 'plugins' }})</span>
                        </h2>
                        @foreach ($failedByDomain as $domain => $rows)
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                                   style="background:#fff;border:1px solid #e3deef;border-left:4px solid #b91c1c;border-radius:6px;margin-bottom:12px;">
                                <tr><td style="padding:14px 16px;">
                                    <div style="font-size:15px;font-weight:700;color:#212025;margin-bottom:10px;">
                                        <a href="https://{{ $domain }}" style="color:#6953C4;text-decoration:none;">{{ $domain }}</a>
                                        <span style="font-size:12px;color:#8a8294;font-weight:400;margin-left:8px;">{{ count($rows) }} {{ count($rows) === 1 ? 'plugin' : 'plugins' }} failed</span>
                                    </div>
                                    @foreach ($rows as $row)
                                        <div style="padding:10px 0;{{ ! $loop->first ? 'border-top:1px solid #f1edff;' : '' }}">
                                            <div style="font-size:13px;color:#212025;line-height:1.5;">
                                                <span style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:#5b5566;">{{ $row['plugin_slug'] }}</span>
                                                &nbsp;·&nbsp;
                                                <span style="font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;color:#b91c1c;font-weight:600;">{{ $row['before_version'] ?: '?' }} → {{ $row['target_version'] ?: '?' }}</span>
                                            </div>
                                            @if (! empty($row['error_excerpt']))
                                                <div style="margin-top:6px;font-size:12px;color:#7f1d1d;font-family:ui-monospace,Menlo,Consolas,monospace;line-height:1.5;word-break:break-word;background:#fde8e8;border-radius:4px;padding:8px 10px;">
                                                    {{ $row['error_excerpt'] }}
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </td></tr>
                            </table>
                        @endforeach
                    @endif

                    {{-- Successes — grouped by site. --}}
                    @if ($sn > 0)
                        <h2 style="margin:{{ $fn > 0 ? '24px' : '0' }} 0 12px 0;font-size:16px;color:#2D2062;font-weight:700;letter-spacing:0.01em;text-transform:uppercase;">
                            Succeeded <span style="color:#1f8a3b;">({{ count($succeededByDomain) }} {{ count($succeededByDomain) === 1 ? 'site' : 'sites' }}, {{ $sn }} {{ $sn === 1 ? 'plugin' : 'plugins' }})</span>
                        </h2>
                        @foreach ($succeededByDomain as $domain => $rows)
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                                   style="background:#fdfcff;border:1px solid #e3deef;border-radius:6px;margin-bottom:10px;">
                                <tr><td style="padding:12px 16px;">
                                    <div style="font-size:14px;font-weight:700;color:#212025;margin-bottom:6px;">
                                        <a href="https://{{ $domain }}" style="color:#6953C4;text-decoration:none;">{{ $domain }}</a>
                                        <span style="display:inline-block;background:#DCFFDD;color:#1f8a3b;padding:2px 8px;border-radius:9999px;font-size:11px;font-weight:700;margin-left:8px;vertical-align:middle;">
                                            {{ count($rows) }} {{ count($rows) === 1 ? 'update' : 'updates' }}
                                        </span>
                                    </div>
                                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font-size:12px;border-collapse:collapse;">
                                        @foreach ($rows as $row)
                                            <tr>
                                                <td style="padding:4px 0;color:#5b5566;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;width:60%;">
                                                    {{ $row['plugin_slug'] }}
                                                </td>
                                                <td style="padding:4px 0;color:#5b5566;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;text-align:right;">
                                                    <span style="color:#8a8294;">{{ $row['before_version'] ?: '?' }}</span>
                                                    &nbsp;→&nbsp;
                                                    <span style="color:#1f8a3b;font-weight:600;">{{ $row['after_version'] ?: $row['target_version'] ?: '?' }}</span>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </table>
                                </td></tr>
                            </table>
                        @endforeach
                    @endif

                    {{-- Skipped — grouped by site, single-line per plugin. --}}
                    @if ($kn > 0)
                        <h2 style="margin:24px 0 12px 0;font-size:16px;color:#2D2062;font-weight:700;letter-spacing:0.01em;text-transform:uppercase;">
                            Skipped <span style="color:#b87b00;">({{ count($skippedByDomain) }} {{ count($skippedByDomain) === 1 ? 'site' : 'sites' }}, {{ $kn }} {{ $kn === 1 ? 'plugin' : 'plugins' }})</span>
                        </h2>
                        @foreach ($skippedByDomain as $domain => $rows)
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                                   style="background:#fff7e6;border:1px solid #f4e4c1;border-radius:6px;margin-bottom:10px;">
                                <tr><td style="padding:12px 16px;">
                                    <div style="font-size:14px;font-weight:700;color:#212025;margin-bottom:6px;">
                                        <a href="https://{{ $domain }}" style="color:#6953C4;text-decoration:none;">{{ $domain }}</a>
                                        <span style="font-size:11px;color:#8a8294;font-weight:400;margin-left:8px;">{{ count($rows) }} skipped</span>
                                    </div>
                                    @foreach ($rows as $row)
                                        <div style="font-size:12px;color:#7c5500;line-height:1.5;padding:3px 0;">
                                            <span style="font-family:ui-monospace,Menlo,Consolas,monospace;">{{ $row['plugin_slug'] }}</span> — {{ $row['error_excerpt'] ?? 'skipped' }}
                                        </div>
                                    @endforeach
                                </td></tr>
                            </table>
                        @endforeach
                    @endif

                    {{-- Cancelled — grouped by site. --}}
                    @if ($cn > 0)
                        <h2 style="margin:24px 0 12px 0;font-size:16px;color:#2D2062;font-weight:700;letter-spacing:0.01em;text-transform:uppercase;">
                            Cancelled <span style="color:#5b5566;">({{ count($cancelledByDomain) }} {{ count($cancelledByDomain) === 1 ? 'site' : 'sites' }}, {{ $cn }} {{ $cn === 1 ? 'plugin' : 'plugins' }})</span>
                        </h2>
                        @foreach ($cancelledByDomain as $domain => $rows)
                            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%"
                                   style="background:#F1EEFF;border:1px solid #e3deef;border-radius:6px;margin-bottom:10px;">
                                <tr><td style="padding:12px 16px;">
                                    <div style="font-size:14px;font-weight:700;color:#212025;margin-bottom:6px;">
                                        <a href="https://{{ $domain }}" style="color:#6953C4;text-decoration:none;">{{ $domain }}</a>
                                    </div>
                                    @foreach ($rows as $row)
                                        <div style="font-size:12px;color:#5b5566;line-height:1.5;padding:3px 0;font-family:ui-monospace,Menlo,Consolas,monospace;">
                                            {{ $row['plugin_slug'] }}
                                        </div>
                                    @endforeach
                                </td></tr>
                            </table>
                        @endforeach
                    @endif
                </td></tr>

                {{-- Footer --}}
                <tr><td style="padding:18px 32px 24px 32px;border-top:1px solid #e3deef;background:#fdfcff;">
                    <p style="margin:0;font-size:12px;color:#8a8294;line-height:1.5;">
                        <strong style="color:#2D2062;">{{ config('clockwork.operator.name') }}</strong> care-plan auto-updates run nightly between
                        2:00 AM and 6:00 AM Eastern. To pause auto-updates on a specific site, visit its Settings tab.
                    </p>
                </td></tr>
            </table>

            <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="720" style="max-width:720px;width:100%;margin-top:14px;">
                <tr><td style="text-align:center;font-size:11px;color:#8a8294;letter-spacing:0.05em;">
                    {{ config('clockwork.operator.name') }} &middot; <a href="{{ config('clockwork.operator.website_url') }}" style="color:#8a8294;text-decoration:none;">{{ config('clockwork.operator.website_url') }}</a>
                </td></tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
