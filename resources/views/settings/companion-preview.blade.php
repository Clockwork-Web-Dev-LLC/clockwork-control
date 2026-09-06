<!DOCTYPE html>
<html lang="en" class="wp-toolbar">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $branding['menu_title'] ?: 'Clockwork' }} &lsaquo; WordPress Admin Live Preview</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        :root {
            --wp-admin-theme-color: #2271b1;
            --cwk-primary: #6953C4;
            --cwk-primary-dark: #2D2062;
            --cwk-primary-soft: #D1C9F4;
            --cwk-page-bg: #FFFFFF;
            --cwk-accent: #7EFF83;
            --cwk-text: #212025;
            --cwk-text-muted: #5b5566;
            --cwk-text-soft: #8a8294;
            --cwk-border: #e3deef;
            --cwk-card: #FFFFFF;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background: #f0f0f1;
            color: #3c434a;
            font-size: 13px;
            line-height: 1.4em;
        }

        /* Top Bar Overlay */
        .preview-bar {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: 40px;
            background: #0f172a;
            color: #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 20px;
            font-size: 12px;
            z-index: 99999;
            box-shadow: 0 2px 8px rgba(0,0,0,0.3);
            border-bottom: 1px solid #1e293b;
        }
        .preview-bar a.back-btn {
            color: #38bdf8;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-weight: 500;
        }
        .preview-bar a.back-btn:hover { color: #bae6fd; text-decoration: underline; }
        .preview-badge {
            background: rgba(16, 185, 129, 0.2);
            color: #34d399;
            border: 1px solid rgba(16, 185, 129, 0.4);
            padding: 2px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
        }

        /* Simulated WP Admin Bar */
        #wpadminbar {
            position: fixed;
            top: 40px;
            left: 0;
            right: 0;
            height: 32px;
            background: #1d2327;
            color: rgba(240, 246, 252, 0.7);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 16px;
            z-index: 9999;
            font-size: 12px;
        }
        .wp-bar-left { display: flex; align-items: center; gap: 18px; }
        .wp-bar-item { display: inline-flex; align-items: center; gap: 6px; color: inherit; cursor: pointer; }
        .wp-bar-item:hover { color: #72aee6; }
        .wp-bar-right { display: flex; align-items: center; gap: 10px; }

        /* Simulated WP Admin Layout */
        .wp-wrapper {
            margin-top: 72px;
            display: flex;
            min-height: calc(100vh - 72px);
        }

        /* Sidebar */
        #adminmenuback, #adminmenuwrap {
            width: 160px;
            background: #1d2327;
            flex-shrink: 0;
        }
        #adminmenu {
            list-style: none;
            padding: 12px 0 30px;
        }
        #adminmenu li a {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 9px 12px;
            color: #c3c4c7;
            text-decoration: none;
            font-size: 13px;
            font-weight: 400;
            transition: background 0.1s, color 0.1s;
        }
        #adminmenu li a:hover {
            background: #131719;
            color: #72aee6;
        }
        #adminmenu li.current > a {
            background: #2271b1;
            color: #fff;
            font-weight: 600;
        }
        #adminmenu li.current.cwk-active > a {
            background: var(--cwk-primary);
            color: #fff;
            font-weight: 600;
        }
        #adminmenu li.separator {
            height: 8px;
            border-bottom: 1px solid rgba(255,255,255,0.06);
            margin-bottom: 8px;
        }

        /* Main Content Container */
        #wpcontent {
            flex-grow: 1;
            background: #fff;
            min-height: calc(100vh - 72px);
        }

        /* Companion Chrome (from clockwork-companion admin.css) */
        .clockwork-admin {
            color: var(--cwk-text);
            background: var(--cwk-page-bg);
            min-height: 100%;
        }
        .clockwork-admin__header {
            background: var(--cwk-primary-dark);
            color: #fff;
            padding: 22px 32px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .clockwork-admin__brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .clockwork-admin__brand img {
            height: 28px;
            max-width: 180px;
            object-fit: contain;
            display: block;
        }
        .clockwork-admin__brand-text {
            font-size: 13px;
            color: rgba(255,255,255,0.7);
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }
        .clockwork-admin__version {
            font-size: 12px;
            color: rgba(255,255,255,0.55);
            font-family: ui-monospace, "SF Mono", Menlo, Consolas, monospace;
        }
        .cwk-header-support-btn {
            background: rgba(255,255,255,0.12);
            color: #fff;
            border: 1px solid rgba(255,255,255,0.25);
            padding: 6px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            text-decoration: none;
            cursor: pointer;
            transition: background 0.15s;
        }
        .cwk-header-support-btn:hover {
            background: rgba(255,255,255,0.22);
        }

        /* Tabs */
        .clockwork-admin__tabs {
            background: #fff;
            border-bottom: 1px solid var(--cwk-border);
            padding: 0 32px;
            display: flex;
            gap: 4px;
            overflow-x: auto;
        }
        .clockwork-admin__tab {
            padding: 14px 18px;
            font-size: 13px;
            font-weight: 500;
            color: var(--cwk-text-muted);
            text-decoration: none;
            border-bottom: 3px solid transparent;
            transition: color 0.15s, border-color 0.15s;
            cursor: pointer;
            white-space: nowrap;
        }
        .clockwork-admin__tab:hover { color: var(--cwk-primary); }
        .clockwork-admin__tab.is-active {
            color: var(--cwk-primary);
            border-bottom-color: var(--cwk-primary);
        }

        /* Body */
        .clockwork-admin__body {
            padding: 28px 32px 60px;
            max-width: 1200px;
        }
        .clockwork-card {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.03);
        }

        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .stat-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 16px;
        }
        .stat-label { font-size: 11px; text-transform: uppercase; color: #64748b; font-weight: 600; letter-spacing: 0.03em; }
        .stat-value { font-size: 20px; font-weight: 700; color: #0f172a; margin-top: 4px; }
        .stat-sub { font-size: 11px; color: #10b981; font-weight: 500; margin-top: 2px; }

        /* Action Log Table */
        .activity-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-top: 12px;
        }
        .activity-table th {
            text-align: left;
            padding: 10px 14px;
            background: #f8fafc;
            color: #64748b;
            font-size: 11px;
            text-transform: uppercase;
            border-bottom: 1px solid #e2e8f0;
        }
        .activity-table td {
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .activity-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 8px;
            border-radius: 9999px;
            font-size: 11px;
            font-weight: 600;
        }
        .badge-success { background: #dcfce7; color: #15803d; }
        .badge-info { background: #e0f2fe; color: #0369a1; }

        .admin-footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            color: #64748b;
            font-size: 12px;
        }
    </style>
</head>
<body>

    {{-- Top Overlay --}}
    <div class="preview-bar">
        <div style="display: flex; align-items: center; gap: 12px;">
            <a href="{{ route('settings.companion.index') }}" class="back-btn">
                <i class="fa-solid fa-arrow-left"></i> Back to Companion Settings
            </a>
            <span style="color: #475569;">|</span>
            <span class="preview-badge">
                <i class="fa-solid fa-eye mr-1"></i> Live Companion Preview
            </span>
            <span style="color: #94a3b8;">
                Viewing as Client Administrator on WordPress 6.7
            </span>
        </div>
        <div>
            <span style="color: #94a3b8; font-size: 11px;">
                Branding source: <strong>Clockwork Control</strong>
            </span>
        </div>
    </div>

    {{-- WP Admin Bar --}}
    <div id="wpadminbar">
        <div class="wp-bar-left">
            <span class="wp-bar-item"><i class="fa-brands fa-wordpress text-base"></i></span>
            <span class="wp-bar-item"><i class="fa-solid fa-house"></i> <strong>My Production Site</strong></span>
            <span class="wp-bar-item"><i class="fa-solid fa-arrows-rotate"></i> 0</span>
            <span class="wp-bar-item"><i class="fa-solid fa-comment"></i> 0</span>
            <span class="wp-bar-item"><i class="fa-solid fa-plus"></i> New</span>
        </div>
        <div class="wp-bar-right">
            <span class="wp-bar-item">
                Howdy, Client Admin
                <span style="width:20px;height:20px;border-radius:50%;background:#475569;display:inline-flex;align-items:center;justify-content:center;color:#fff;font-size:10px;">A</span>
            </span>
        </div>
    </div>

    {{-- Layout Wrapper --}}
    <div class="wp-wrapper">
        {{-- WP Sidebar --}}
        <div id="adminmenuwrap">
            <ul id="adminmenu">
                <li><a href="#"><i class="fa-solid fa-gauge w-4"></i> <span>Dashboard</span></a></li>
                <li class="separator"></li>
                <li><a href="#"><i class="fa-solid fa-thumbtack w-4"></i> <span>Posts</span></a></li>
                <li><a href="#"><i class="fa-solid fa-photo-film w-4"></i> <span>Media</span></a></li>
                <li><a href="#"><i class="fa-solid fa-file w-4"></i> <span>Pages</span></a></li>
                <li><a href="#"><i class="fa-solid fa-comments w-4"></i> <span>Comments</span></a></li>
                <li class="separator"></li>
                
                {{-- The Custom White-Labeled Companion Menu Item --}}
                <li class="current cwk-active">
                    <a href="#">
                        <i class="fa-solid {{ str_contains($branding['menu_icon'], 'shield') ? 'fa-shield-halved' : (str_contains($branding['menu_icon'], 'hammer') ? 'fa-hammer' : (str_contains($branding['menu_icon'], 'heart') ? 'fa-heart' : 'fa-clock')) }} w-4"></i>
                        <span>{{ $branding['menu_title'] ?: 'Clockwork' }}</span>
                    </a>
                </li>
                
                <li><a href="#"><i class="fa-solid fa-paint-roller w-4"></i> <span>Appearance</span></a></li>
                <li>
                    <a href="#" style="{{ $branding['hide_plugin_row'] ? 'opacity:0.6;' : '' }}">
                        <i class="fa-solid fa-puzzle-piece w-4"></i>
                        <span>Plugins</span>
                        @if ($branding['hide_plugin_row'])
                            <span style="font-size:9px;background:#e2e8f0;color:#64748b;padding:1px 4px;border-radius:4px;margin-left:auto;">Hidden</span>
                        @endif
                    </a>
                </li>
                <li><a href="#"><i class="fa-solid fa-users w-4"></i> <span>Users</span></a></li>
                <li><a href="#"><i class="fa-solid fa-wrench w-4"></i> <span>Tools</span></a></li>
                <li><a href="#"><i class="fa-solid fa-gear w-4"></i> <span>Settings</span></a></li>
            </ul>
        </div>

        {{-- Companion Admin Content --}}
        <div id="wpcontent">
            <div class="clockwork-admin">
                {{-- Companion Header --}}
                <header class="clockwork-admin__header">
                    <div class="clockwork-admin__brand">
                        @if (! empty($branding['logo_url']))
                            <img src="{{ $branding['logo_url'] }}" alt="{{ $branding['company_name'] }}" />
                        @else
                            <div style="width:30px;height:30px;border-radius:6px;background:#4f46e5;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;">
                                {{ substr($branding['company_name'] ?: 'C', 0, 1) }}
                            </div>
                        @endif
                        <span class="clockwork-admin__brand-text">{{ $branding['menu_title'] ?: 'Clockwork' }} Companion</span>
                    </div>

                    <div style="display: flex; align-items: center; gap: 14px;">
                        @if (! $branding['hide_help_links'])
                            @if (! empty($branding['support_url']))
                                <a href="{{ $branding['support_url'] }}" target="_blank" class="cwk-header-support-btn">
                                    <i class="fa-solid fa-circle-question mr-1"></i> Get Support
                                </a>
                            @elseif (! empty($branding['support_email']))
                                <a href="mailto:{{ $branding['support_email'] }}" class="cwk-header-support-btn">
                                    <i class="fa-solid fa-envelope mr-1"></i> Contact {{ $branding['company_name'] ?: 'Support' }}
                                </a>
                            @else
                                <button type="button" class="cwk-header-support-btn">
                                    <i class="fa-solid fa-headset mr-1"></i> Get Support
                                </button>
                            @endif
                        @endif

                        <span class="clockwork-admin__version">v1.34.0</span>
                    </div>
                </header>

                {{-- Navigation Tabs --}}
                <nav class="clockwork-admin__tabs">
                    <span class="clockwork-admin__tab is-active">Activity</span>
                    <span class="clockwork-admin__tab">Uptime</span>
                    <span class="clockwork-admin__tab">Security</span>
                    <span class="clockwork-admin__tab">2FA</span>
                    <span class="clockwork-admin__tab">Performance</span>
                    <span class="clockwork-admin__tab">Traffic</span>
                    <span class="clockwork-admin__tab">Forms</span>
                    <span class="clockwork-admin__tab">Backups</span>
                    <span class="clockwork-admin__tab">Notifications</span>
                </nav>

                {{-- Body Content --}}
                <div class="clockwork-admin__body">
                    {{-- Welcome Banner --}}
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;background:#f8fafc;border:1px solid #e2e8f0;padding:18px 24px;border-radius:10px;">
                        <div>
                            <h2 style="font-size:18px;font-weight:700;color:#0f172a;margin-bottom:4px;">
                                Site Care &amp; Telemetry Hub
                            </h2>
                            <p style="color:#64748b;font-size:13px;">
                                Managed by <strong>{{ $branding['company_name'] ?: 'Clockwork Web Dev' }}</strong>. Your WordPress environment is protected, backed up, and monitored 24/7.
                            </p>
                        </div>
                        <span style="background:#dcfce7;color:#15803d;padding:4px 12px;border-radius:9999px;font-weight:600;font-size:12px;display:inline-flex;align-items:center;gap:6px;">
                            <span style="width:6px;height:6px;border-radius:50%;background:#16a34a;"></span> Care Active
                        </span>
                    </div>

                    {{-- Metrics Grid --}}
                    <div class="stats-grid">
                        <div class="stat-box">
                            <div class="stat-label">Telemetry Status</div>
                            <div class="stat-value">Connected</div>
                            <div class="stat-sub">HMAC-SHA256 Encrypted</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Stack Runtime</div>
                            <div class="stat-value">PHP 8.3 · WP 6.7</div>
                            <div class="stat-sub">Core Verified</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">24/7 Uptime</div>
                            <div class="stat-value">99.98%</div>
                            <div class="stat-sub">30-day window</div>
                        </div>
                        <div class="stat-box">
                            <div class="stat-label">Daily Backups</div>
                            <div class="stat-value">Automated</div>
                            <div class="stat-sub">Offsite archived</div>
                        </div>
                    </div>

                    {{-- Recent Maintenance Activity Log --}}
                    <div class="clockwork-card">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                            <h3 style="font-size:15px;font-weight:600;color:#0f172a;">Recent Care Operations</h3>
                            <span style="font-size:11px;color:#64748b;">Pushed from Control Panel</span>
                        </div>

                        <table class="activity-table">
                            <thead>
                                <tr>
                                    <th>Operation</th>
                                    <th>Target / Component</th>
                                    <th>Timestamp</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td><i class="fa-solid fa-shield-halved mr-2 text-indigo-600"></i> Malware &amp; Checksum Scan</td>
                                    <td>WordPress Core 6.7.1 Filesystem</td>
                                    <td>Today at 03:15 AM</td>
                                    <td><span class="activity-badge badge-success"><i class="fa-solid fa-check"></i> Clean</span></td>
                                </tr>
                                <tr>
                                    <td><i class="fa-solid fa-envelopes-bulk mr-2 text-emerald-600"></i> Contact Form Test</td>
                                    <td>Primary Inquiry Form (/contact)</td>
                                    <td>Today at 02:30 AM</td>
                                    <td><span class="activity-badge badge-success"><i class="fa-solid fa-check"></i> 200 OK</span></td>
                                </tr>
                                <tr>
                                    <td><i class="fa-solid fa-arrows-rotate mr-2 text-blue-600"></i> Plugin Compatibility Verification</td>
                                    <td>WooCommerce &amp; ACF Pro</td>
                                    <td>Yesterday at 11:45 PM</td>
                                    <td><span class="activity-badge badge-info"><i class="fa-solid fa-circle-info"></i> Verified</span></td>
                                </tr>
                                <tr>
                                    <td><i class="fa-solid fa-database mr-2 text-amber-600"></i> Offsite Database Snapshot</td>
                                    <td>MySQL wp_posts &amp; options</td>
                                    <td>Yesterday at 04:00 AM</td>
                                    <td><span class="activity-badge badge-success"><i class="fa-solid fa-check"></i> Archived</span></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    {{-- Admin Footer --}}
                    <div class="admin-footer">
                        <div>
                            @if (! empty($branding['footer_text']))
                                {{ $branding['footer_text'] }}
                            @else
                                Maintained with care by <a href="{{ $branding['company_url'] ?: '#' }}" style="color:#2563eb;text-decoration:none;font-weight:500;">{{ $branding['company_name'] ?: 'Clockwork Web Dev, LLC' }}</a>
                            @endif
                        </div>
                        <div>
                            <span>Companion v1.34.0 · REST protocol v1</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

</body>
</html>
