@extends('layouts.app')

@section('title', 'WordPress Plugins & Downloads · Clockwork Control')

@section('content')
    <x-page-header title="WordPress Plugins & Downloads"
        subtitle="Download the latest release packages for Clockwork Companion (Private Edition) and Clockwork Renegade (WordPress.org Directory Edition).">
        <x-slot:actions>
            <a href="{{ route('sites.create') }}" class="btn-primary flex items-center gap-1.5 text-xs">
                <i class="fa-solid fa-plus"></i>
                <span>Add Site</span>
            </a>
            <a href="{{ route('sites.index') }}" class="btn-pill-nav text-xs">
                <i class="fa-solid fa-globe text-[var(--color-ink-muted)]"></i>
                <span>All Sites</span>
            </a>
        </x-slot:actions>
    </x-page-header>

    {{-- Overview banner --}}
    <div class="card p-4 mb-6 border-l-4 border-l-[var(--color-brand)] bg-linear-to-r from-[var(--color-brand)]/5 to-transparent flex flex-col md:flex-row items-start md:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-[var(--color-brand)]/10 text-[var(--color-brand)] flex items-center justify-center shrink-0 text-lg">
                <i class="fa-brands fa-wordpress"></i>
            </div>
            <div>
                <h2 class="text-sm font-semibold text-[var(--color-ink-strong)]">Two Editions, One Unified Dashboard</h2>
                <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                    Clockwork Control connects to WordPress sites via either plugin edition. Both use 256-bit cryptographic Connection Key pairing for seamless enrollment.
                </p>
            </div>
        </div>
        <div class="flex items-center gap-2 text-xs text-[var(--color-ink-soft)] shrink-0">
            <span class="inline-flex items-center gap-1 px-2.5 py-1 rounded-full bg-[var(--color-surface)] border border-[var(--color-border)] font-data">
                <i class="fa-solid fa-key text-[var(--color-brand)]"></i>
                <span>256-Bit Mutual HMAC</span>
            </span>
        </div>
    </div>

    {{-- Plugin Cards Grid --}}
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-8">

        {{-- 1. Clockwork Companion --}}
        <div class="card p-6 flex flex-col justify-between border-t-4 border-t-blue-500 shadow-xs hover:shadow-md transition-shadow">
            <div>
                <div class="flex items-start justify-between gap-3 pb-4 mb-4 border-b border-[var(--color-border-light)]">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-xl shrink-0">
                            <i class="fa-solid fa-shield-halved"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-base font-bold text-[var(--color-ink-strong)]">{{ $companion['name'] }}</h3>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold font-data bg-blue-50 dark:bg-blue-950/60 text-blue-700 dark:text-blue-300 border border-blue-200 dark:border-blue-800/60">
                                    v{{ $companion['version'] }}
                                </span>
                            </div>
                            <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">{{ $companion['edition'] }}</p>
                        </div>
                    </div>
                    <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-1 rounded-md bg-blue-500/10 text-blue-700 dark:text-blue-300 shrink-0">
                        Private Fleet
                    </span>
                </div>

                <p class="text-xs text-[var(--color-ink)] leading-relaxed mb-4">
                    The full-featured internal agency edition. Designed for server-managed sites (SpinupWP, Pressable, or dedicated infrastructure) where deep operational control, automated updates, and disaster recovery are required.
                </p>

                <div class="space-y-2 mb-6">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">Key Capabilities</div>
                    <ul class="space-y-2 text-xs text-[var(--color-ink-muted)]">
                        <li class="flex items-start gap-2">
                            <i class="fa-solid fa-circle-check text-blue-500 mt-0.5 shrink-0 text-xs"></i>
                            <span><strong class="text-[var(--color-ink-strong)]">Off-site Glacier Backups:</strong> Direct streaming to S3 Glacier Instant Retrieval and 2-step restore staging.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="fa-solid fa-circle-check text-blue-500 mt-0.5 shrink-0 text-xs"></i>
                            <span><strong class="text-[var(--color-ink-strong)]">Automated Updates:</strong> Fleet-wide updates for plugins, themes, WordPress core, and translations.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="fa-solid fa-circle-check text-blue-500 mt-0.5 shrink-0 text-xs"></i>
                            <span><strong class="text-[var(--color-ink-strong)]">Maintenance Tools:</strong> MySQL table optimization, debug log inspector, and maintenance mode toggles.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="fa-solid fa-circle-check text-blue-500 mt-0.5 shrink-0 text-xs"></i>
                            <span><strong class="text-[var(--color-ink-strong)]">Rescue Sandbox:</strong> Sandboxed code snippet execution (<code class="text-[11px]">CodeSnippetRoute</code>) for emergency troubleshooting.</span>
                        </li>
                    </ul>
                </div>

                <div class="bg-[var(--color-surface-alt)] p-3 rounded-xl mb-6 text-xs text-[var(--color-ink-muted)] space-y-1 font-data">
                    <div class="flex justify-between">
                        <span class="text-[var(--color-ink-soft)]">License:</span>
                        <span class="text-[var(--color-ink)]">{{ $companion['license'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--color-ink-soft)]">REST Route:</span>
                        <span class="text-[var(--color-ink)]">{{ $companion['route_namespace'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--color-ink-soft)]">WP Admin Screen:</span>
                        <span class="text-[var(--color-ink)]">{{ $companion['pairing_screen'] }}</span>
                    </div>
                </div>
            </div>

            <div>
                <a href="{{ $companion['download_url'] }}"
                   class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl font-semibold text-sm bg-blue-600 hover:bg-blue-700 text-white shadow-sm hover:shadow-md transition-all">
                    <i class="fa-solid fa-download text-sm"></i>
                    <span>Download Clockwork Companion (.zip)</span>
                </a>
                <div class="text-center mt-2 text-[11px] text-[var(--color-ink-soft)] font-data">
                    Package: clockwork-companion-{{ $companion['version'] }}.zip
                </div>
            </div>
        </div>

        {{-- 2. Clockwork Renegade --}}
        <div class="card p-6 flex flex-col justify-between border-t-4 border-t-emerald-500 shadow-xs hover:shadow-md transition-shadow">
            <div>
                <div class="flex items-start justify-between gap-3 pb-4 mb-4 border-b border-[var(--color-border-light)]">
                    <div class="flex items-center gap-3">
                        <div class="w-12 h-12 rounded-2xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-xl shrink-0">
                            <i class="fa-brands fa-wordpress-simple"></i>
                        </div>
                        <div>
                            <div class="flex items-center gap-2">
                                <h3 class="text-base font-bold text-[var(--color-ink-strong)]">{{ $renegade['name'] }}</h3>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-bold font-data bg-emerald-50 dark:bg-emerald-950/60 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800/60">
                                    v{{ $renegade['version'] }}
                                </span>
                            </div>
                            <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">{{ $renegade['edition'] }}</p>
                        </div>
                    </div>
                    <span class="text-[10px] font-semibold uppercase tracking-wider px-2 py-1 rounded-md bg-emerald-500/10 text-emerald-700 dark:text-emerald-300 shrink-0">
                        WordPress.org Directory
                    </span>
                </div>

                <p class="text-xs text-[var(--color-ink)] leading-relaxed mb-4">
                    The official open-source edition crafted specifically for the official WordPress.org Plugin Directory. Strictly adheres to all security and review guidelines with zero remote code execution.
                </p>

                <div class="space-y-2 mb-6">
                    <div class="text-[11px] font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">Key Capabilities</div>
                    <ul class="space-y-2 text-xs text-[var(--color-ink-muted)]">
                        <li class="flex items-start gap-2">
                            <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5 shrink-0 text-xs"></i>
                            <span><strong class="text-[var(--color-ink-strong)]">Strict Security Compliance:</strong> Zero remote <code class="text-[11px]">eval()</code> or arbitrary code execution paths for maximum client trust.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5 shrink-0 text-xs"></i>
                            <span><strong class="text-[var(--color-ink-strong)]">Official WordPress Updates:</strong> Updates delivered exclusively through standard WordPress.org SVN releases.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5 shrink-0 text-xs"></i>
                            <span><strong class="text-[var(--color-ink-strong)]">Affirmative Consent:</strong> Dedicated connection setup screen in WP Admin with transparent terms.</span>
                        </li>
                        <li class="flex items-start gap-2">
                            <i class="fa-solid fa-circle-check text-emerald-500 mt-0.5 shrink-0 text-xs"></i>
                            <span><strong class="text-[var(--color-ink-strong)]">Full Cleanup:</strong> Complete removal of options, transients, and security logs on uninstallation.</span>
                        </li>
                    </ul>
                </div>

                <div class="bg-[var(--color-surface-alt)] p-3 rounded-xl mb-6 text-xs text-[var(--color-ink-muted)] space-y-1 font-data">
                    <div class="flex justify-between">
                        <span class="text-[var(--color-ink-soft)]">License:</span>
                        <span class="text-[var(--color-ink)]">{{ $renegade['license'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--color-ink-soft)]">REST Route:</span>
                        <span class="text-[var(--color-ink)]">{{ $renegade['route_namespace'] }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-[var(--color-ink-soft)]">WP Admin Screen:</span>
                        <span class="text-[var(--color-ink)]">{{ $renegade['pairing_screen'] }}</span>
                    </div>
                </div>
            </div>

            <div>
                <a href="{{ $renegade['download_url'] }}"
                   class="w-full inline-flex items-center justify-center gap-2 px-4 py-3 rounded-xl font-semibold text-sm bg-emerald-600 hover:bg-emerald-700 text-white shadow-sm hover:shadow-md transition-all">
                    <i class="fa-solid fa-download text-sm"></i>
                    <span>Download Clockwork Renegade (.zip)</span>
                </a>
                <div class="text-center mt-2 text-[11px] text-[var(--color-ink-soft)] font-data">
                    Package: clockwork-renegade-{{ $renegade['version'] }}.zip
                </div>
            </div>
        </div>

    </div>

    {{-- Feature Comparison Matrix --}}
    <div class="card p-6 mb-8">
        <h3 class="text-sm font-bold text-[var(--color-ink-strong)] mb-1">Edition Comparison Matrix</h3>
        <p class="text-xs text-[var(--color-ink-muted)] mb-4">Detailed technical comparison between Clockwork Companion and Clockwork Renegade.</p>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="border-b border-[var(--color-border)] text-[var(--color-ink-soft)] font-semibold uppercase tracking-wider text-[10px]">
                        <th class="py-2.5 px-3">Feature</th>
                        <th class="py-2.5 px-3 text-blue-600 dark:text-blue-400">Clockwork Companion</th>
                        <th class="py-2.5 px-3 text-emerald-600 dark:text-emerald-400">Clockwork Renegade</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)] text-[var(--color-ink)]">
                    <tr>
                        <td class="py-2.5 px-3 font-semibold text-[var(--color-ink-strong)]">Distribution Channel</td>
                        <td class="py-2.5 px-3">Private `.zip` &amp; SSH mu-plugin deploy</td>
                        <td class="py-2.5 px-3">Official WordPress.org Directory &amp; `.zip`</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 px-3 font-semibold text-[var(--color-ink-strong)]">Software License</td>
                        <td class="py-2.5 px-3 font-data">Proprietary / Internal Agency</td>
                        <td class="py-2.5 px-3 font-data">GPL-2.0-or-later</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 px-3 font-semibold text-[var(--color-ink-strong)]">REST API Namespace</td>
                        <td class="py-2.5 px-3 font-data text-[11px]">/wp-json/clockwork/v1/</td>
                        <td class="py-2.5 px-3 font-data text-[11px]">/wp-json/clockwork-renegade/v1/</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 px-3 font-semibold text-[var(--color-ink-strong)]">Authentication Method</td>
                        <td class="py-2.5 px-3 font-data">256-bit Connection Key / HMAC-SHA256</td>
                        <td class="py-2.5 px-3 font-data">256-bit Connection Key / HMAC-SHA256</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 px-3 font-semibold text-[var(--color-ink-strong)]">Remote Code Execution (<code class="text-[11px]">eval</code>)</td>
                        <td class="py-2.5 px-3 text-amber-600 dark:text-amber-400 font-semibold">Enabled (Sandboxed rescue)</td>
                        <td class="py-2.5 px-3 text-emerald-600 dark:text-emerald-400 font-semibold">Zero Remote Execution (Omitted)</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 px-3 font-semibold text-[var(--color-ink-strong)]">Plugin Self-Updates</td>
                        <td class="py-2.5 px-3">Direct tarball push from Control</td>
                        <td class="py-2.5 px-3">Standard WordPress.org Core Updater</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 px-3 font-semibold text-[var(--color-ink-strong)]">S3 Glacier Streaming Backups</td>
                        <td class="py-2.5 px-3 text-emerald-600 dark:text-emerald-400">Supported (Create &amp; Restore)</td>
                        <td class="py-2.5 px-3 text-[var(--color-ink-muted)]">Read-only report reporting</td>
                    </tr>
                    <tr>
                        <td class="py-2.5 px-3 font-semibold text-[var(--color-ink-strong)]">Target Infrastructure</td>
                        <td class="py-2.5 px-3">Managed agency servers (SpinupWP, Pressable, Custom)</td>
                        <td class="py-2.5 px-3">Client sites, third-party hosts, public installs</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- 3-Step Enrollment Walkthrough --}}
    <div class="card p-6">
        <h3 class="text-sm font-bold text-[var(--color-ink-strong)] mb-1">Quick Installation &amp; Pairing Guide</h3>
        <p class="text-xs text-[var(--color-ink-muted)] mb-6">How to connect any WordPress site to Clockwork Control in under two minutes.</p>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-full bg-[var(--color-brand)]/10 text-[var(--color-brand)] font-bold text-sm flex items-center justify-center shrink-0">
                    1
                </div>
                <div>
                    <h4 class="text-xs font-semibold text-[var(--color-ink-strong)]">Install &amp; Activate</h4>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1 leading-relaxed">
                        Upload either downloaded <code class="text-[11px]">.zip</code> in WordPress Admin (<span class="font-semibold text-[var(--color-ink)]">Plugins → Add New → Upload Plugin</span>) and click <span class="font-semibold text-[var(--color-ink)]">Activate</span>.
                    </p>
                </div>
            </div>

            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-full bg-[var(--color-brand)]/10 text-[var(--color-brand)] font-bold text-sm flex items-center justify-center shrink-0">
                    2
                </div>
                <div>
                    <h4 class="text-xs font-semibold text-[var(--color-ink-strong)]">Copy Connection Key</h4>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1 leading-relaxed">
                        In WP Admin, open <span class="font-semibold text-[var(--color-ink)]">Tools → Clockwork Control</span> (Companion) or <span class="font-semibold text-[var(--color-ink)]">Clockwork → Connection</span> (Renegade), and click <span class="font-semibold text-[var(--color-ink)]">Copy Connection Key</span>.
                    </p>
                </div>
            </div>

            <div class="flex items-start gap-3">
                <div class="w-8 h-8 rounded-full bg-[var(--color-brand)]/10 text-[var(--color-brand)] font-bold text-sm flex items-center justify-center shrink-0">
                    3
                </div>
                <div>
                    <h4 class="text-xs font-semibold text-[var(--color-ink-strong)]">Connect in Clockwork</h4>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-1 leading-relaxed">
                        Go to <a href="{{ route('sites.create') }}" class="text-[var(--color-brand)] font-semibold hover:underline">Add Site</a>, paste the key, and submit. Clockwork automatically verifies mutual HMAC possession and enrolls the site.
                    </p>
                </div>
            </div>
        </div>
    </div>
@endsection
