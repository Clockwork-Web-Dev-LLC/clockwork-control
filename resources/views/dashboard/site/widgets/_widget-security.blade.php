@php
    $siteCheck = $latestSiteCheck;
    $checksum = $latestChecksumScan;

    $siteCheckOk = $siteCheck && ($siteCheck->status === 'clean' || $siteCheck->status === 'ok');
    $checksumOk = $checksum && ($checksum->status === 'clean' || $checksum->status === 'ok');
    $sslState = $site->sslState();
    $sslOk = in_array($sslState, [\App\Models\Site::SSL_STATE_GREEN, \App\Models\Site::SSL_STATE_NONE], true);

    $isSecure = ($siteCheckOk || ! $siteCheck) && ($checksumOk || ! $checksum) && $bansCount === 0 && $sslOk;
@endphp

<div class="card p-5 flex flex-col justify-between h-full">
    <div>
        <div class="flex items-center justify-between mb-4">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-shield-halved text-emerald-600"></i>
                Security &amp; Integrity
            </h3>
            @if ($isSecure)
                <span class="status-pill status-green text-[10px]">
                    <span class="status-dot"></span> Secure
                </span>
            @else
                <span class="status-pill status-yellow text-[10px]">
                    <span class="status-dot"></span> Needs Review
                </span>
            @endif
        </div>

        <div class="space-y-2.5 py-1">
            <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                <span class="flex items-center gap-2 text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-lock text-emerald-600"></i> SSL Certificate
                </span>
                @if ($site->cert_expires_at)
                    @if ($sslState === \App\Models\Site::SSL_STATE_RED)
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-800">
                            Expired {{ $site->cert_expires_at->diffForHumans() }}
                        </span>
                    @elseif ($sslState === \App\Models\Site::SSL_STATE_YELLOW)
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-800">
                            Expires {{ $site->cert_expires_at->diffForHumans() }}
                        </span>
                    @else
                        <span class="text-[10px] text-emerald-700 font-semibold flex items-center gap-1">
                            <i class="fa-solid fa-check text-[9px]"></i> Valid (expires {{ $site->cert_expires_at->diffForHumans() }})
                        </span>
                    @endif
                @elseif ($site->cert_source === \App\Models\Site::CERT_SOURCE_REDIRECT_ONLY)
                    <span class="text-[10px] text-[var(--color-ink-muted)]">Redirect only</span>
                @else
                    <span class="text-[10px] text-[var(--color-ink-muted)]">No SSL tracked</span>
                @endif
            </div>

            <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                <span class="flex items-center gap-2 text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-shield-virus text-indigo-600"></i> Sucuri SiteCheck
                </span>
                @if ($siteCheck)
                    @if ($siteCheckOk)
                        <span class="text-[10px] text-emerald-700 font-semibold flex items-center gap-1">
                            <i class="fa-solid fa-check text-[9px]"></i> Clean
                        </span>
                    @else
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-800">
                            {{ $siteCheck->status }}
                        </span>
                    @endif
                @else
                    <span class="text-[10px] text-[var(--color-ink-muted)]">No scan yet</span>
                @endif
            </div>

            <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                <span class="flex items-center gap-2 text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-file-shield text-blue-600"></i> Core Integrity
                </span>
                @if ($checksum)
                    @if ($checksumOk)
                        <span class="text-[10px] text-emerald-700 font-semibold flex items-center gap-1">
                            <i class="fa-solid fa-check text-[9px]"></i> Checksums Verified
                        </span>
                    @else
                        <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-800">
                            Modified files detected
                        </span>
                    @endif
                @elseif (! $site->host()->supports(\Modules\Core\Contracts\HostingProvider::CAP_SSH) && ! $site->isPressable())
                    <span class="text-[10px] text-[var(--color-ink-muted)]" title="wp-cli core verify-checksums requires server SSH access">N/A (requires SSH)</span>
                @else
                    <span class="text-[10px] text-[var(--color-ink-muted)]">No check yet</span>
                @endif
            </div>

            <div class="flex items-center justify-between text-xs p-2.5 rounded-lg bg-[var(--color-surface-alt)]/60">
                <span class="flex items-center gap-2 text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-ban text-rose-600"></i> Active IP Bans
                </span>
                @if (! $site->host()->supports(\Modules\Core\Contracts\HostingProvider::CAP_SSH))
                    <span class="text-[10px] text-[var(--color-ink-muted)]" title="Server-level fail2ban IP bans require SSH access">N/A (requires SSH)</span>
                @elseif ($bansCount > 0)
                    <span class="px-2 py-0.5 rounded text-[10px] font-semibold bg-rose-100 text-rose-800">
                        {{ $bansCount }} active ban{{ $bansCount === 1 ? '' : 's' }}
                    </span>
                @else
                    <span class="text-[10px] text-emerald-700 font-medium">0 active bans</span>
                @endif
            </div>
        </div>
    </div>

    <div class="mt-4 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
        <span class="text-[11px] text-[var(--color-ink-muted)]">
            @if ($siteCheck?->scanned_at)
                Scanned {{ $siteCheck->scanned_at->diffForHumans() }}
            @endif
        </span>
        <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'security']) }}"
           class="btn-pill-nav text-xs font-medium text-emerald-700 hover:underline">
            Security Details <i class="fa-solid fa-chevron-right text-[10px] ml-0.5"></i>
        </a>
    </div>
</div>
