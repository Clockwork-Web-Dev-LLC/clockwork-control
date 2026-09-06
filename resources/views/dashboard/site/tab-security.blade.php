@php
    // Allowlist-aware split of the latest checksum scan. The two filtered
    // bucket lists feed the active-issues UI; the `allowlisted` block feeds
    // a collapsed "previously cleared" section so cleared findings stay
    // visible (and removable) without nagging.
    $allowlistService = app(\App\Services\Security\CoreChecksumAllowlist::class);
    $allowlistFiltered = $allowlistService->filter($site, $latestChecksumScan);
    $allowlistEntries = \App\Models\SiteCoreChecksumAllowlist::where('site_id', $site->id)->get()->keyBy(fn ($e) => $e->bucket.':'.$e->path);

    // Sucuri's external scanner gets blocked by Cloudflare's WAF on CF-proxied
    // sites — usually a 403 in the error. Recognise that case so the UI doesn't
    // present it as a generic "Failed" red flag (which it isn't — it's an
    // expected limitation of remote scanning behind CF).
    $sucuriBlockedByCf = function (?\App\Models\SiteSecurityScan $scan, $site): bool {
        if (! $scan
            || $scan->scan_type !== \App\Models\SiteSecurityScan::TYPE_SITECHECK
            || $scan->status !== \App\Models\SiteSecurityScan::STATUS_FAILED
        ) {
            return false;
        }
        if ($site->cloudflare_state !== \App\Models\Site::CF_PROXIED) {
            return false;
        }
        $err = strtolower((string) $scan->error);
        return str_contains($err, '403') || str_contains($err, 'forbidden');
    };

    $statusPill = function (?\App\Models\SiteSecurityScan $scan) use ($sucuriBlockedByCf, $site): array {
        if (! $scan) {
            return ['class' => 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]', 'label' => 'Not yet scanned'];
        }
        if ($sucuriBlockedByCf($scan, $site)) {
            return ['class' => 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]', 'label' => 'Blocked by Cloudflare'];
        }
        return match ($scan->status) {
            \App\Models\SiteSecurityScan::STATUS_CLEAN => ['class' => 'bg-[var(--color-status-green)]/15 text-[var(--color-status-green)]', 'label' => 'Clean'],
            \App\Models\SiteSecurityScan::STATUS_WARNING => ['class' => 'bg-[var(--color-status-yellow)]/15 text-[var(--color-status-yellow)]', 'label' => 'Review'],
            \App\Models\SiteSecurityScan::STATUS_ISSUES_FOUND => ['class' => 'bg-[var(--color-status-red)]/15 text-[var(--color-status-red)]', 'label' => 'Issues found'],
            default => ['class' => 'bg-[var(--color-status-yellow)]/15 text-[var(--color-status-yellow)]', 'label' => 'Failed'],
        };
    };
    $onCarePlan = (bool) $site->care_plan_enabled;
@endphp

@if (session('flash'))
    <div class="card p-3 mb-5 flex items-center gap-2 border-l-4 border-[var(--color-status-green)] text-sm">
        <i class="fa-solid fa-circle-check text-[var(--color-status-green)]"></i>
        <span class="text-[var(--color-ink-strong)]">{{ session('flash') }}</span>
    </div>
@endif

@if (! $onCarePlan)
    <div class="card p-4 mb-5 flex items-start gap-3 border-l-4 border-[var(--color-ink-soft)]">
        <i class="fa-regular fa-circle text-[var(--color-ink-soft)] mt-0.5"></i>
        <div class="text-sm flex-1">
            <p class="text-[var(--color-ink-strong)] font-medium">Scheduled scans disabled — site is not on a care plan.</p>
            <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">Daily and weekly security scans are part of the care plan. Enable on the <a class="text-[var(--color-primary-600)] hover:underline" href="{{ route('sites.show', [$site, 'settings']) }}">Settings tab</a> to begin scheduled runs. The "Run scan now" buttons below still work for ad-hoc scans regardless of plan state.</p>
        </div>
    </div>
@endif

<div class="grid grid-cols-1 md:grid-cols-2 gap-5 mb-6">
    {{-- Sucuri SiteCheck card --}}
    @php $sc = $latestSiteCheck; $scPill = $statusPill($sc); @endphp
    <div class="card p-5">
        <div class="flex items-start justify-between mb-3 gap-3">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-globe text-[var(--color-ink-soft)] mr-1"></i>
                    Sucuri SiteCheck
                </h2>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">Remote malware + blacklist scan (replaces ManageWP).</p>
            </div>
            <span class="text-xs px-2 py-0.5 rounded-full font-medium {{ $scPill['class'] }}">{{ $scPill['label'] }}</span>
        </div>

        <form method="POST" action="{{ route('security.scans.run', $site) }}" class="mb-3">
            @csrf
            <input type="hidden" name="type" value="sitecheck">
            <button type="submit"
                    class="text-xs px-3 py-1.5 rounded-full border border-[var(--color-border-light)] text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] disabled:opacity-60"
                    onclick="this.disabled=true; this.querySelector('i')?.classList.add('fa-spin');">
                <i class="fa-solid fa-rotate"></i> Run scan now
            </button>
        </form>

        @if ($sc)
            <p class="text-sm text-[var(--color-ink-strong)] mb-3">{{ $sc->summary }}</p>
            <dl class="grid grid-cols-2 gap-3 text-xs">
                <div>
                    <dt class="text-[var(--color-ink-soft)]">Scanned</dt>
                    <dd class="font-data text-[var(--color-ink-strong)]" title="{{ $sc->scanned_at }}">{{ $sc->scanned_at->diffForHumans() }}</dd>
                </div>
                <div>
                    <dt class="text-[var(--color-ink-soft)]">Took</dt>
                    <dd class="font-data text-[var(--color-ink-strong)]">{{ $sc->elapsed_ms ? number_format($sc->elapsed_ms).' ms' : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[var(--color-ink-soft)]">Malware</dt>
                    <dd class="text-[var(--color-ink-strong)]">{{ $sc->has_malware_hit ? 'YES' : 'no' }}</dd>
                </div>
                <div>
                    <dt class="text-[var(--color-ink-soft)]">Blacklist</dt>
                    <dd class="text-[var(--color-ink-strong)]">{{ $sc->blacklist_hit ? 'YES' : 'no' }}</dd>
                </div>
            </dl>
            @if ($sucuriBlockedByCf($sc, $site))
                <div class="text-xs text-[var(--color-ink-muted)] mt-3 p-3 rounded border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]">
                    <i class="fa-solid fa-cloud text-orange-500 mr-1"></i>
                    <strong class="text-[var(--color-ink-strong)]">Cloudflare's WAF is blocking Sucuri's external scanner</strong> ({{ $sc->error }}). This is expected for CF-proxied sites — Sucuri tries to fetch your homepage from their IPs and CF rejects them as bots.
                    The <strong>Core file integrity</strong> check below runs server-side over SSH and isn't affected — that's the deeper malware check anyway.
                    To unblock Sucuri specifically, you'd need to whitelist Sucuri's IP ranges in your CF WAF rules.
                </div>
            @elseif ($sc->error)
                <p class="text-xs text-[var(--color-status-red)] mt-3 break-all"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $sc->error }}</p>
            @endif
        @else
            <p class="text-sm text-[var(--color-ink-muted)]">No scan recorded yet. The weekly scheduled run hits every site Monday 02:00.</p>
        @endif
    </div>

    {{-- Core checksums card --}}
    @php
        $cc = $latestChecksumScan;
        // Status pill: if every flagged file is allowlisted, show "Clean
        // (N allowlisted)" instead of red "Issues found" — the red is for
        // findings that still need attention.
        $allAllowlisted = $cc
            && $cc->status === \App\Models\SiteSecurityScan::STATUS_ISSUES_FOUND
            && ! $allowlistFiltered['has_unallowlisted'];
        $ccPill = $allAllowlisted
            ? ['class' => 'bg-[var(--color-status-green)]/15 text-[var(--color-status-green)]', 'label' => 'Clean (allowlisted)']
            : $statusPill($cc);
        $totalAllowlisted = count($allowlistFiltered['allowlisted']['modified'])
            + count($allowlistFiltered['allowlisted']['missing'])
            + count($allowlistFiltered['allowlisted']['should_not_exist']);
    @endphp
    <div id="core-integrity" class="card p-5">
        <div class="flex items-start justify-between mb-3 gap-3">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-shield-halved text-[var(--color-ink-soft)] mr-1"></i>
                    Core file integrity
                </h2>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">SSH wp-cli core checksum verification (catches what Sucuri can't).</p>
            </div>
            <span class="text-xs px-2 py-0.5 rounded-full font-medium {{ $ccPill['class'] }}">{{ $ccPill['label'] }}</span>
        </div>

        @if ($site->is_wordpress)
            <form method="POST" action="{{ route('security.scans.run', $site) }}" class="mb-3">
                @csrf
                <input type="hidden" name="type" value="core_checksums">
                <button type="submit"
                        class="text-xs px-3 py-1.5 rounded-full border border-[var(--color-border-light)] text-[var(--color-ink-strong)] hover:bg-[var(--color-surface-alt)] disabled:opacity-60"
                        onclick="this.disabled=true; this.querySelector('i')?.classList.add('fa-spin');">
                    <i class="fa-solid fa-rotate"></i> Run scan now
                </button>
            </form>
        @else
            <p class="text-xs text-[var(--color-ink-soft)] mb-3"><i class="fa-solid fa-circle-info mr-1"></i> Not a WordPress site — checksum scans don't apply.</p>
        @endif

        @if ($cc)
            <p class="text-sm text-[var(--color-ink-strong)] mb-3">{{ $cc->summary }}</p>
            <dl class="grid grid-cols-2 gap-3 text-xs mb-3">
                <div>
                    <dt class="text-[var(--color-ink-soft)]">Scanned</dt>
                    <dd class="font-data text-[var(--color-ink-strong)]" title="{{ $cc->scanned_at }}">{{ $cc->scanned_at->diffForHumans() }}</dd>
                </div>
                <div>
                    <dt class="text-[var(--color-ink-soft)]">Took</dt>
                    <dd class="font-data text-[var(--color-ink-strong)]">{{ $cc->elapsed_ms ? number_format($cc->elapsed_ms).' ms' : '—' }}</dd>
                </div>
                <div>
                    <dt class="text-[var(--color-ink-soft)]">Modified files</dt>
                    <dd class="font-data text-[var(--color-ink-strong)]">{{ count($cc->details['modified'] ?? []) }}</dd>
                </div>
                <div>
                    <dt class="text-[var(--color-ink-soft)]">Missing / unexpected</dt>
                    <dd class="font-data text-[var(--color-ink-strong)]">{{ count($cc->details['missing'] ?? []) + count($cc->details['should_not_exist'] ?? []) }}</dd>
                </div>
            </dl>

            @php
                // Bucket -> (display label, allowlist enum value, can-view-contents).
                // 'missing' files have no contents to read so the View link is
                // suppressed for that bucket.
                $bucketDefs = [
                    'modified' => ['label' => 'Modified', 'enum' => \App\Models\SiteCoreChecksumAllowlist::BUCKET_MODIFIED, 'viewable' => true],
                    'missing' => ['label' => 'Missing', 'enum' => \App\Models\SiteCoreChecksumAllowlist::BUCKET_MISSING, 'viewable' => false],
                    'should_not_exist' => ['label' => 'Unexpected', 'enum' => \App\Models\SiteCoreChecksumAllowlist::BUCKET_UNEXPECTED, 'viewable' => true],
                ];
            @endphp

            {{-- Active findings (allowlist-filtered) --}}
            @foreach ($bucketDefs as $key => $def)
                @php $files = $allowlistFiltered[$key] ?? []; @endphp
                @if (!empty($files))
                    <details class="mb-2" open>
                        <summary class="text-xs text-[var(--color-ink-muted)] cursor-pointer select-none">
                            <i class="fa-solid fa-caret-right mr-1"></i>{{ $def['label'] }} ({{ count($files) }})
                        </summary>
                        <ul class="mt-1 ml-4 text-xs font-data text-[var(--color-ink-strong)] space-y-1 max-h-64 overflow-y-auto">
                            @foreach ($files as $f)
                                <li class="flex items-center gap-2 group">
                                    <span class="flex-1 truncate" title="{{ $f }}">{{ $f }}</span>
                                    @if ($def['viewable'])
                                        <a href="{{ route('security.scans.file', [$site, 'path' => $f]) }}"
                                           class="text-[10px] px-2 py-0.5 rounded border border-[var(--color-border-light)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-ink-strong)]"
                                           title="View file contents (live SSH read)">
                                            <i class="fa-solid fa-eye"></i> View
                                        </a>
                                    @else
                                        <a href="{{ route('security.scans.allowlist.add', $site) }}"
                                           class="text-[10px] text-[var(--color-ink-soft)] cursor-default" title="Missing files have no contents to view">
                                            <i class="fa-solid fa-eye-slash"></i>
                                        </a>
                                    @endif
                                    <form method="POST" action="{{ route('security.scans.allowlist.add', $site) }}" class="inline">
                                        @csrf
                                        <input type="hidden" name="path" value="{{ $f }}">
                                        <input type="hidden" name="bucket" value="{{ $def['enum'] }}">
                                        <button type="submit"
                                                class="text-[10px] px-2 py-0.5 rounded border border-[var(--color-border-light)] text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] hover:text-[var(--color-status-green)]"
                                                title="Add to allowlist (no reason — view file to add one)">
                                            <i class="fa-solid fa-check"></i> Allow
                                        </button>
                                    </form>
                                </li>
                            @endforeach
                        </ul>
                    </details>
                @endif
            @endforeach

            {{-- Allowlisted findings (collapsed by default) --}}
            @if ($totalAllowlisted > 0)
                <details class="mb-2 mt-3 pt-3 border-t border-[var(--color-border-light)]">
                    <summary class="text-xs text-[var(--color-ink-soft)] cursor-pointer select-none">
                        <i class="fa-solid fa-caret-right mr-1"></i>
                        Allowlisted findings ({{ $totalAllowlisted }})
                    </summary>
                    <ul class="mt-1 ml-4 text-xs font-data text-[var(--color-ink-soft)] space-y-1">
                        @foreach ($bucketDefs as $key => $def)
                            @foreach ($allowlistFiltered['allowlisted'][$key] ?? [] as $f)
                                @php
                                    $entry = $allowlistEntries->get($def['enum'].':'.$f) ?? $allowlistEntries->get('*:'.$f);
                                @endphp
                                <li class="flex items-center gap-2">
                                    <span class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)] w-16">{{ $def['label'] }}</span>
                                    <span class="flex-1 truncate" title="{{ $entry?->reason ?? 'no reason given' }}">{{ $f }}</span>
                                    @if ($def['viewable'])
                                        <a href="{{ route('security.scans.file', [$site, 'path' => $f]) }}"
                                           class="text-[10px] text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]">
                                            <i class="fa-solid fa-eye"></i> View
                                        </a>
                                    @endif
                                    @if ($entry)
                                        <form method="POST" action="{{ route('security.scans.allowlist.remove', [$site, $entry]) }}" class="inline">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit"
                                                    class="text-[10px] text-[var(--color-ink-soft)] hover:text-[var(--color-status-red)]"
                                                    title="Remove from allowlist">
                                                <i class="fa-solid fa-trash"></i>
                                            </button>
                                        </form>
                                    @endif
                                </li>
                            @endforeach
                        @endforeach
                    </ul>
                </details>
            @endif

            @if ($cc->error)
                <p class="text-xs text-[var(--color-status-red)] mt-3 break-all"><i class="fa-solid fa-circle-exclamation mr-1"></i>{{ $cc->error }}</p>
            @endif
        @else
            <p class="text-sm text-[var(--color-ink-muted)]">No scan recorded yet. The daily scheduled run hits every WP site at 02:30.</p>
        @endif
    </div>
</div>

{{-- Recent scan history --}}
<div class="card p-5">
    <h3 class="font-display text-base font-semibold text-[var(--color-ink-strong)] mb-3">
        <i class="fa-solid fa-clock-rotate-left text-[var(--color-ink-soft)] mr-1"></i>
        Recent scans
    </h3>

    @if ($scanHistory->isEmpty())
        <p class="text-sm text-[var(--color-ink-muted)]">No scans on record yet.</p>
    @else
        <table class="w-full text-sm">
            <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                <tr>
                    <th class="w-6 px-2 py-2"></th>
                    <th class="text-left px-4 py-2">When</th>
                    <th class="text-left px-4 py-2">Type</th>
                    <th class="text-left px-4 py-2">Status</th>
                    <th class="text-left px-4 py-2">Summary</th>
                    <th class="text-right px-4 py-2">Took</th>
                </tr>
            </thead>
            {{-- Single Alpine state on the tbody tracks which row id is
                 currently expanded. Sibling <tr>s (the trigger and the
                 details) share scope this way, and only one row can be
                 open at a time — so opening one auto-collapses the last. --}}
            <tbody class="divide-y divide-[var(--color-border-light)]" x-data="{ openId: null }">
                @foreach ($scanHistory as $row)
                    @php
                        $pill = $statusPill($row);
                        $details = is_array($row->details) ? $row->details : [];
                        $findings = $details['findings'] ?? [];
                        $transport = $details['transport'] ?? null;
                        $scannedCount = $details['scanned_files_count'] ?? null;
                        $scanAborted = (bool) ($details['scan_aborted'] ?? false);
                        $abortReason = $details['abort_reason'] ?? null;
                        $ccModified = $details['modified'] ?? [];
                        $ccMissing = $details['missing'] ?? [];
                        $ccUnexpected = $details['should_not_exist'] ?? [];
                        $hasCcFiles = ! empty($ccModified) || ! empty($ccMissing) || ! empty($ccUnexpected);
                        // Show the disclosure caret whenever there is something
                        // worth expanding to: at least one finding, checksum
                        // file buckets, or aborted scan metadata.
                        $expandable = ! empty($findings) || $hasCcFiles || $scanAborted;
                    @endphp
                    <tr class="align-top">
                        <td class="w-6 px-2 py-2 text-[var(--color-ink-soft)]">
                            @if ($expandable)
                                <button type="button"
                                        @click="openId = openId === {{ $row->id }} ? null : {{ $row->id }}"
                                        class="hover:text-[var(--color-ink)] focus:outline-none"
                                        :aria-expanded="openId === {{ $row->id }} ? 'true' : 'false'"
                                        aria-label="Toggle finding details">
                                    <i class="fa-solid fa-chevron-right text-xs transition-transform" :class="openId === {{ $row->id }} ? 'rotate-90' : ''"></i>
                                </button>
                            @endif
                        </td>
                        <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)]" title="{{ $row->scanned_at }}">{{ $row->scanned_at->diffForHumans() }}</td>
                        <td class="px-4 py-2 text-xs font-data text-[var(--color-ink-strong)]">{{ $row->scan_type }}</td>
                        <td class="px-4 py-2"><span class="text-[10px] px-2 py-0.5 rounded-full font-medium {{ $pill['class'] }}">{{ $pill['label'] }}</span></td>
                        <td class="px-4 py-2 text-xs text-[var(--color-ink-muted)] max-w-md">{{ $row->summary }}</td>
                        <td class="px-4 py-2 text-xs text-right font-data text-[var(--color-ink-muted)]">{{ $row->elapsed_ms ? number_format($row->elapsed_ms).' ms' : '—' }}</td>
                    </tr>
                    @if ($expandable)
                        <tr x-show="openId === {{ $row->id }}" x-cloak class="bg-[var(--color-surface-alt)]">
                            <td></td>
                            <td colspan="5" class="px-4 py-3">
                                <div class="text-xs text-[var(--color-ink-muted)] mb-2 flex flex-wrap gap-3">
                                    @if ($transport)
                                        <span><span class="uppercase tracking-wide text-[var(--color-ink-soft)]">Transport:</span> <span class="font-data text-[var(--color-ink-strong)]">{{ $transport }}</span></span>
                                    @endif
                                    @if ($scannedCount !== null)
                                        <span><span class="uppercase tracking-wide text-[var(--color-ink-soft)]">Files scanned:</span> <span class="font-data text-[var(--color-ink-strong)]">{{ number_format($scannedCount) }}</span></span>
                                    @endif
                                    @if ($scanAborted)
                                        <span class="text-[var(--color-status-yellow)]"><i class="fa-solid fa-triangle-exclamation"></i> Scan aborted: {{ $abortReason ?: 'reason not recorded' }}</span>
                                    @endif
                                </div>
                                @if (! empty($findings))
                                    <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">{{ count($findings) }} finding{{ count($findings) === 1 ? '' : 's' }}</div>
                                    <ul class="text-xs space-y-1.5">
                                        @foreach ($findings as $f)
                                            @php
                                                $kind = $f['kind'] ?? 'unknown';
                                                $path = $f['path'] ?? '(no path)';
                                                $evidence = $f['evidence'] ?? '';
                                            @endphp
                                            <li class="border-l-2 border-[var(--color-border-light)] pl-3">
                                                <div class="flex items-baseline gap-2 flex-wrap">
                                                    <span class="font-data text-[10px] uppercase tracking-wide px-1.5 py-0.5 rounded bg-[var(--color-surface)] text-[var(--color-ink-strong)] border border-[var(--color-border-light)]">{{ $kind }}</span>
                                                    <span class="font-data text-[var(--color-ink-strong)] break-all">{{ $path }}</span>
                                                </div>
                                                @if ($evidence !== '')
                                                    <div class="font-data text-[var(--color-ink-muted)] mt-0.5 break-all">{{ $evidence }}</div>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                @if ($hasCcFiles)
                                    @foreach ([['Modified', $ccModified], ['Missing', $ccMissing], ['Unexpected', $ccUnexpected]] as [$label, $files])
                                        @if (! empty($files))
                                            <div class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1 {{ ! $loop->first ? 'mt-2' : '' }}">{{ $label }} ({{ count($files) }})</div>
                                            <ul class="text-xs font-data space-y-0.5 max-h-48 overflow-y-auto text-[var(--color-ink-strong)]">
                                                @foreach ($files as $f)
                                                    <li class="border-l-2 border-[var(--color-border-light)] pl-3 truncate" title="{{ $f }}">{{ $f }}</li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    @endforeach
                                @endif
                            </td>
                        </tr>
                    @endif
                @endforeach
            </tbody>
        </table>
    @endif
</div>
