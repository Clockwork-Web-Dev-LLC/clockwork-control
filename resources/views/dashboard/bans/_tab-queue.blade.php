@php
    use App\Models\ReviewQueueEntry;

    $sourceLabels = [
        ReviewQueueEntry::SOURCE_LLAR => 'LLAR',
        ReviewQueueEntry::SOURCE_WORDFENCE => 'Wordfence',
        ReviewQueueEntry::SOURCE_LLM => 'LLM',
        ReviewQueueEntry::SOURCE_NGINX => 'Nginx',
        ReviewQueueEntry::SOURCE_MANUAL => 'Manual',
    ];

    $sourceIcons = [
        ReviewQueueEntry::SOURCE_LLAR => 'fa-key',
        ReviewQueueEntry::SOURCE_WORDFENCE => 'fa-shield',
        ReviewQueueEntry::SOURCE_LLM => 'fa-brain',
        ReviewQueueEntry::SOURCE_NGINX => 'fa-file-lines',
        ReviewQueueEntry::SOURCE_MANUAL => 'fa-hand',
    ];

    $renderSitesSummary = function (array $sites, int $maxShown = 3) {
        if ($sites === []) return '—';
        $shown = array_slice($sites, 0, $maxShown);
        $extra = count($sites) - count($shown);
        $text = implode(', ', $shown);
        if ($extra > 0) $text .= ' +' . $extra . ' more';
        return $text;
    };

    $renderCfIcon = function (?string $state) {
        return match ($state) {
            'proxied' => '<i class="fa-solid fa-cloud mr-1" style="color: #F38020" title="Cloudflare proxy active"></i>',
            'dns_only' => '<i class="fa-solid fa-cloud mr-1 text-[var(--color-ink-soft)]" title="On Cloudflare DNS but proxy is OFF"></i>',
            default => '',
        };
    };
@endphp

@if (($queuedCount ?? 0) > 0)
    <div class="card p-4 mb-6 bg-[var(--color-surface-alt)] flex items-center gap-3" id="queued-banner" data-queued-count="{{ $queuedCount }}">
        <i class="fa-solid fa-spinner fa-spin text-[var(--color-primary-500)]"></i>
        <div class="flex-1 text-sm">
            <span class="font-medium text-[var(--color-ink-strong)]">{{ $queuedCount }} {{ Str::plural('ban', $queuedCount) }} queued.</span>
            <span class="text-[var(--color-ink-muted)]">The processor runs every minute — the page refreshes automatically.</span>
        </div>
    </div>
    <script>setTimeout(() => location.reload(), 30000);</script>
@endif

{{-- Source filter chips --}}
<div class="flex items-center gap-2 mb-6 flex-wrap">
    <a href="{{ route('bans.queue') }}"
       class="btn-pill-nav {{ ! $sourceFilter ? 'is-active' : '' }}">
        All <span class="ml-1 text-xs text-[var(--color-ink-soft)]">({{ $sourceCounts->sum() }})</span>
    </a>
    @foreach ($sourceLabels as $key => $label)
        @if ($sourceCounts->has($key))
            <a href="{{ route('bans.queue', ['source' => $key]) }}"
               class="btn-pill-nav {{ $sourceFilter === $key ? 'is-active' : '' }}">
                <i class="fa-solid {{ $sourceIcons[$key] ?? 'fa-circle' }}"></i>
                {{ $label }} <span class="ml-1 text-xs text-[var(--color-ink-soft)]">({{ $sourceCounts[$key] }})</span>
            </a>
        @endif
    @endforeach
</div>

{{-- Repeat offenders --}}
<section class="mb-10">
    <div class="flex items-baseline justify-between mb-3 gap-3 flex-wrap">
        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
            Repeat offenders
            <span class="text-sm text-[var(--color-ink-muted)] font-normal ml-2">{{ $repeatOffenders->count() }} {{ Str::plural('IP', $repeatOffenders->count()) }} locked out 2+ times</span>
        </h2>
        @if ($repeatOffenders->isNotEmpty())
            <div class="text-xs text-[var(--color-ink-soft)] inline-flex items-center gap-2">
                <i class="fa-solid fa-network-wired"></i>
                Multi-server icon = locked out on more than one server
            </div>
        @endif
    </div>

    @if ($repeatOffenders->isEmpty())
        <div class="card p-6 text-center text-[var(--color-ink-muted)]">
            <i class="fa-solid fa-thumbs-up text-2xl text-[var(--color-status-green)] mb-2"></i>
            <div>No repeat offenders. Anything attacking more than one site shows up here.</div>
            @if (($activeBansCount ?? 0) > 0)
                <div class="mt-2 text-xs">
                    <a href="{{ route('bans.active') }}" class="text-[var(--color-primary-600)] hover:underline">See {{ number_format($activeBansCount) }} active bans →</a>
                </div>
            @endif
        </div>
    @else
        <form method="POST" id="repeat-offenders-form" data-bulk-form>
            @csrf
            <div class="card overflow-hidden">
                <div class="bg-[var(--color-surface-alt)] px-4 py-2 flex items-center gap-3 flex-wrap">
                    <label class="inline-flex items-center gap-2 text-xs text-[var(--color-ink-muted)] cursor-pointer">
                        <input type="checkbox" id="select-all-repeat" class="rounded border-[var(--color-border)]">
                        <span>Select all</span>
                    </label>
                    <span class="text-xs text-[var(--color-ink-soft)]" id="selection-count">0 selected</span>
                    <div class="ml-auto flex items-center gap-2">
                        <button type="submit"
                                formaction="{{ route('review-queue.bulkApprove') }}"
                                class="btn-primary text-xs px-3 py-1"
                                data-bulk-action="approve"
                                data-confirm="Ban the selected IPs across every server where they are pending?">
                            <i class="fa-solid fa-ban"></i> Ban selected
                        </button>
                        <button type="submit"
                                formaction="{{ route('review-queue.bulkDismiss') }}"
                                class="btn-pill-nav text-xs px-3 py-1"
                                data-bulk-action="dismiss"
                                data-confirm="Dismiss the selected IPs?">
                            <i class="fa-solid fa-xmark"></i> Dismiss selected
                        </button>
                    </div>
                </div>
                <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'lockouts', defaultDir: 'desc' })">
                    <thead class="bg-[var(--color-surface-alt)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="w-8 px-4 py-2"></th>
                            <x-sort-th key="ip"       class="px-4 py-2 font-medium">IP</x-sort-th>
                            <x-sort-th key="lockouts" class="px-4 py-2 font-medium">Lockouts</x-sort-th>
                            <x-sort-th key="servers"  class="px-4 py-2 font-medium">Servers</x-sort-th>
                            <x-sort-th key="sites"    class="px-4 py-2 font-medium">Sites</x-sort-th>
                            <x-sort-th key="latest"   class="px-4 py-2 font-medium">Latest</x-sort-th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($repeatOffenders as $row)
                            <tr class="align-top hover:bg-[var(--color-surface-alt)]/30"
                                data-sort-ip="{{ $row['ip'] }}"
                                data-sort-lockouts="{{ $row['total_occurrences'] }}"
                                data-sort-servers="{{ count($row['servers']) }}"
                                data-sort-sites="{{ count($row['sites']) }}"
                                data-sort-latest="{{ $row['latest_at']?->getTimestamp() ?? '' }}">
                                <td class="px-4 py-3">
                                    <input type="checkbox" name="ips[]" value="{{ $row['ip'] }}"
                                           class="bulk-row-checkbox rounded border-[var(--color-border)]">
                                </td>
                                <td class="px-4 py-3 text-[var(--color-ink-strong)] whitespace-nowrap">
                                    @if ($row['multi_server'])
                                        <i class="fa-solid fa-network-wired text-[var(--color-status-red)] mr-1"
                                           title="Locked out on {{ count($row['servers']) }} servers — fleet-wide brute forcer"></i>
                                    @endif
                                    <x-ip-link :ip="$row['ip']" />
                                </td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <span class="font-display text-base font-semibold text-[var(--color-ink-strong)]">{{ $row['total_occurrences'] }}</span>
                                    <span class="text-xs text-[var(--color-ink-soft)]">×</span>
                                </td>
                                <td class="px-4 py-3 text-[var(--color-ink-muted)]">
                                    @foreach ($row['servers'] as $srv)
                                        <a href="{{ route('servers.show', $srv['id']) }}" class="hover:underline">{{ $srv['name'] }}</a>{{ ! $loop->last ? ', ' : '' }}
                                    @endforeach
                                </td>
                                <td class="px-4 py-3 text-[var(--color-ink-muted)] max-w-md text-xs">
                                    {{ $renderSitesSummary($row['sites']) }}
                                </td>
                                <td class="px-4 py-3 text-[var(--color-ink-soft)] text-xs whitespace-nowrap">
                                    {{ $row['latest_at']?->diffForHumans() ?? '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </form>
    @endif
</section>

{{-- First sightings --}}
@if ($firstSightings->isNotEmpty())
    <details class="mb-10 group">
        <summary class="cursor-pointer inline-flex items-center gap-2 text-sm text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] transition-colors">
            <i class="fa-solid fa-chevron-right text-xs group-open:rotate-90 transition-transform"></i>
            <span class="font-display text-base">Show first sightings</span>
            <span class="text-xs text-[var(--color-ink-soft)]">{{ $firstSightings->count() }} {{ Str::plural('IP', $firstSightings->count()) }} locked out once</span>
        </summary>

        <form method="POST" id="first-sightings-form" data-bulk-form class="mt-4">
            @csrf
            <div class="card overflow-hidden">
                <div class="bg-[var(--color-surface-alt)] px-4 py-2 flex items-center gap-3 flex-wrap">
                    <label class="inline-flex items-center gap-2 text-xs text-[var(--color-ink-muted)] cursor-pointer">
                        <input type="checkbox" id="select-all-first" class="rounded border-[var(--color-border)]">
                        <span>Select all</span>
                    </label>
                    <span class="text-xs text-[var(--color-ink-soft)]" data-selection-count>0 selected</span>
                    <div class="ml-auto flex items-center gap-2">
                        <button type="submit"
                                formaction="{{ route('review-queue.bulkApprove') }}"
                                class="btn-primary text-xs px-3 py-1"
                                data-confirm="Ban the selected IPs?">
                            <i class="fa-solid fa-ban"></i> Ban selected
                        </button>
                        <button type="submit"
                                formaction="{{ route('review-queue.bulkDismiss') }}"
                                class="btn-pill-nav text-xs px-3 py-1"
                                data-confirm="Dismiss the selected IPs?">
                            <i class="fa-solid fa-xmark"></i> Dismiss selected
                        </button>
                    </div>
                </div>
                <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'detected', defaultDir: 'desc' })">
                    <thead class="bg-[var(--color-surface-alt)] text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">
                        <tr>
                            <th class="w-8 px-4 py-2"></th>
                            <x-sort-th key="ip"       class="px-4 py-2 font-medium">IP</x-sort-th>
                            <x-sort-th key="server"   class="px-4 py-2 font-medium">Server</x-sort-th>
                            <x-sort-th key="site"     class="px-4 py-2 font-medium">Site</x-sort-th>
                            <x-sort-th key="detected" class="px-4 py-2 font-medium">Detected</x-sort-th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($firstSightings as $row)
                            <tr class="align-top hover:bg-[var(--color-surface-alt)]/30"
                                data-sort-ip="{{ $row['ip'] }}"
                                data-sort-server="{{ $row['servers'][0]['name'] ?? '' }}"
                                data-sort-site="{{ $row['sites'][0] ?? '' }}"
                                data-sort-detected="{{ $row['latest_at']?->getTimestamp() ?? '' }}">
                                <td class="px-4 py-3">
                                    <input type="checkbox" name="ips[]" value="{{ $row['ip'] }}"
                                           class="bulk-row-checkbox rounded border-[var(--color-border)]">
                                </td>
                                <td class="px-4 py-3 text-[var(--color-ink-strong)]"><x-ip-link :ip="$row['ip']" /></td>
                                <td class="px-4 py-3 text-[var(--color-ink-muted)]">
                                    @foreach ($row['servers'] as $srv)
                                        <a href="{{ route('servers.show', $srv['id']) }}" class="hover:underline">{{ $srv['name'] }}</a>{{ ! $loop->last ? ', ' : '' }}
                                    @endforeach
                                </td>
                                <td class="px-4 py-3 text-[var(--color-ink-muted)] text-xs">
                                    @php $domain = $row['sites'][0] ?? null; @endphp
                                    @if ($domain)
                                        {!! $renderCfIcon($cfStates[$domain] ?? null) !!}{{ $domain }}
                                    @else
                                        —
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-[var(--color-ink-soft)] text-xs whitespace-nowrap">{{ $row['latest_at']?->diffForHumans() ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </form>
    </details>
@endif

<script>
    (function () {
        document.querySelectorAll('[data-bulk-form]').forEach(function (form) {
            const checkboxes = form.querySelectorAll('.bulk-row-checkbox');
            const selectAll = form.querySelector('input[type="checkbox"][id^="select-all-"]');
            const counter = form.querySelector('#selection-count, [data-selection-count]');

            function updateCount() {
                const n = form.querySelectorAll('.bulk-row-checkbox:checked').length;
                if (counter) counter.textContent = n + ' selected';
                if (selectAll) {
                    selectAll.checked = n > 0 && n === checkboxes.length;
                    selectAll.indeterminate = n > 0 && n < checkboxes.length;
                }
            }

            if (selectAll) {
                selectAll.addEventListener('change', function () {
                    checkboxes.forEach(cb => cb.checked = selectAll.checked);
                    updateCount();
                });
            }
            checkboxes.forEach(cb => cb.addEventListener('change', updateCount));

            form.addEventListener('submit', function (e) {
                const n = form.querySelectorAll('.bulk-row-checkbox:checked').length;
                if (n === 0) {
                    e.preventDefault();
                    alert('Select at least one IP first.');
                    return;
                }
                const btn = e.submitter;
                const msg = btn?.dataset.confirm;
                if (msg && ! confirm(msg + ' (' + n + ' selected)')) {
                    e.preventDefault();
                }
            });

            updateCount();
        });
    })();
</script>
