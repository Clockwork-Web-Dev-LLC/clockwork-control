@php
    $hasUpdates = count($updatesAvailable) > 0;
    $snapshotStale = $snapshotAt === null || $snapshotAt->lt(now()->subHours(24));
@endphp

<div class="card p-5 mb-6">
    <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
        <div>
            <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                <i class="fa-solid fa-arrow-up-from-bracket text-[var(--color-ink-muted)] mr-1"></i>
                Plugin updates
            </h2>
            <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                {{ $pluginCounts['total'] }} installed
                · <span class="text-[var(--color-ink-strong)]">{{ $pluginCounts['active'] }} active</span>
                · {{ $pluginCounts['inactive'] }} inactive
                · @if ($pluginCounts['updates_available'] > 0)
                    <span class="text-[var(--color-status-yellow)]">{{ $pluginCounts['updates_available'] }} updates available</span>
                @else
                    <span class="text-[var(--color-status-green)]">all up to date</span>
                @endif
            </p>
            @if ($snapshotAt)
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                    Inventory cached {{ $snapshotAt->diffForHumans() }}.
                </p>
            @endif
        </div>
        <button type="button"
                id="updates-refresh-btn"
                class="btn-pill-nav text-xs"
                data-url="{{ route('sites.companion.push-update', $site) }}"
                title="Force-refresh the Companion snapshot. Use this if you just updated something out-of-band and the list looks stale.">
            <i class="fa-solid fa-rotate"></i> Refresh inventory
        </button>
    </div>

    {{-- Care plan banner — informational, doesn't gate the action. --}}
    @if ($site->care_plan_enabled)
        <div class="rounded-md p-3 mb-4 status-green text-sm">
            <i class="fa-solid fa-shield-heart"></i>
            <strong>Care plan</strong> — these updates are included.
        </div>
    @else
        <div class="rounded-md p-3 mb-4 status-yellow text-sm">
            <i class="fa-solid fa-circle-exclamation"></i>
            <strong>Not on a care plan</strong> — bill these updates separately. Toggle the flag in
            <a href="{{ route('sites.show', ['site' => $site, 'tab' => 'settings']) }}" class="underline">Settings → Billing</a>
            if that's wrong.
        </div>
    @endif

    @if ($snapshotStale)
        <div class="rounded-md p-3 mb-4 status-yellow text-sm">
            <i class="fa-solid fa-clock-rotate-left"></i>
            Inventory snapshot is older than 24 hours. Click <strong>Refresh inventory</strong> above before updating to make sure you're acting on current data.
        </div>
    @endif

    @if (! $hasUpdates)
        <div class="rounded-md p-6 text-center text-[var(--color-ink-soft)]">
            <i class="fa-solid fa-circle-check text-[var(--color-status-green)] text-2xl mb-2 block"></i>
            All active plugins are up to date.
        </div>
    @else
        <div id="updates-summary" class="hidden rounded-md p-3 mb-4 text-sm"></div>

        <form id="updates-form" data-action="{{ route('sites.companion.plugin-update', $site) }}">
            @csrf
            <div class="flex items-center justify-between gap-3 mb-3">
                <label class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] flex items-center gap-2">
                    <input type="checkbox" id="updates-select-all" checked>
                    Select all
                </label>
                <button type="submit" class="btn-primary text-sm" id="updates-run-btn">
                    <i class="fa-solid fa-arrow-up-from-bracket"></i>
                    Update selected
                </button>
            </div>

            <table class="w-full text-sm">
                <thead>
                    <tr class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] border-b border-[var(--color-border-light)]">
                        <th class="text-left py-2 pr-2 w-8"></th>
                        <th class="text-left py-2 pr-3">Plugin</th>
                        <th class="text-left py-2 pr-3 font-data">Current</th>
                        <th class="text-left py-2 pr-3 font-data">Available</th>
                        <th class="text-left py-2">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-[var(--color-border-light)]">
                    @foreach ($updatesAvailable as $p)
                        <tr data-slug="{{ $p['slug'] }}" data-name="{{ $p['name'] ?? $p['slug'] }}">
                            <td class="py-2 pr-2">
                                <input type="checkbox" name="slugs[]" value="{{ $p['slug'] }}" class="updates-row-check" checked>
                            </td>
                            <td class="py-2 pr-3">
                                <div class="font-medium text-[var(--color-ink-strong)]">{{ $p['name'] ?? $p['slug'] }}</div>
                                <div class="text-xs text-[var(--color-ink-soft)] font-data">{{ $p['slug'] }}</div>
                            </td>
                            <td class="py-2 pr-3 font-data text-[var(--color-ink-muted)]">{{ $p['version'] ?? '?' }}</td>
                            <td class="py-2 pr-3 font-data text-[var(--color-status-yellow)]">{{ $p['new_version'] ?? '?' }}</td>
                            <td class="py-2 updates-row-status text-[var(--color-ink-soft)]">
                                @if (! ($p['active'] ?? false))
                                    <span class="text-xs text-[var(--color-ink-soft)]">inactive</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </form>
    @endif

    @if (count($upToDate) > 0)
        <details class="mt-6">
            <summary class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] cursor-pointer">
                Up to date ({{ count($upToDate) }})
            </summary>
            <ul class="mt-2 text-xs text-[var(--color-ink-muted)] divide-y divide-[var(--color-border-light)]">
                @foreach ($upToDate as $p)
                    <li class="py-1.5 flex items-center justify-between gap-3">
                        <span><span class="text-[var(--color-ink-strong)]">{{ $p['name'] ?? $p['slug'] }}</span> <span class="font-data">{{ $p['version'] ?? '' }}</span></span>
                        <span class="font-data text-[var(--color-ink-soft)]">{{ $p['slug'] }}</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif

    @if (count($inactive) > 0)
        <details class="mt-3">
            <summary class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)] cursor-pointer">
                Inactive ({{ count($inactive) }}) — usually delete candidates, not update candidates
            </summary>
            <ul class="mt-2 text-xs text-[var(--color-ink-muted)] divide-y divide-[var(--color-border-light)]">
                @foreach ($inactive as $p)
                    <li class="py-1.5 flex items-center justify-between gap-3">
                        <span>
                            <span class="text-[var(--color-ink-strong)]">{{ $p['name'] ?? $p['slug'] }}</span>
                            <span class="font-data">{{ $p['version'] ?? '' }}</span>
                            @if ($p['update_available'] ?? false)
                                <span class="status-pill status-yellow text-[10px] ml-1">update available</span>
                            @endif
                        </span>
                        <span class="font-data text-[var(--color-ink-soft)]">{{ $p['slug'] }}</span>
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</div>

<script>
(function () {
    const form = document.getElementById('updates-form');
    if (!form) return;

    const csrfToken = '{{ csrf_token() }}';
    const actionUrl = form.dataset.action;
    const refreshUrl = '{{ route('sites.companion.push-update', $site) }}';

    const selectAll = document.getElementById('updates-select-all');
    const summary = document.getElementById('updates-summary');
    const runBtn = document.getElementById('updates-run-btn');

    selectAll.addEventListener('change', () => {
        document.querySelectorAll('.updates-row-check').forEach(cb => { cb.checked = selectAll.checked; });
    });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const checked = Array.from(document.querySelectorAll('.updates-row-check:checked'));
        if (checked.length === 0) {
            alert('Pick at least one plugin to update.');
            return;
        }
        if (!confirm('Update ' + checked.length + ' ' + (checked.length === 1 ? 'plugin' : 'plugins') + ' on {{ $site->domain }}? This runs synchronously and may take a few minutes.')) {
            return;
        }

        runBtn.disabled = true;
        const originalLabel = runBtn.innerHTML;

        let done = 0;
        let failed = 0;
        const total = checked.length;

        summary.className = 'rounded-md p-3 mb-4 text-sm bg-[var(--color-surface-alt)] text-[var(--color-ink-strong)]';
        summary.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Starting…';
        summary.classList.remove('hidden');

        // Sequential, NOT parallel — WP doesn't love concurrent self-upgrades.
        for (const cb of checked) {
            const row = cb.closest('tr');
            const statusCell = row.querySelector('.updates-row-status');
            const slug = cb.value;
            const name = row.dataset.name;

            statusCell.innerHTML = '<i class="fa-solid fa-spinner fa-spin text-[var(--color-ink-muted)]"></i> upgrading…';
            summary.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Updating ' + (done + failed + 1) + ' of ' + total + ': <strong>' + escapeHtml(name) + '</strong>';

            try {
                const formData = new FormData();
                formData.append('slug', slug);
                formData.append('_token', csrfToken);
                const r = await fetch(actionUrl, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData,
                });
                const data = await r.json();

                if (data.ok) {
                    done++;
                    cb.checked = false;
                    cb.disabled = true;
                    let html = '<span class="text-[var(--color-status-green)]"><i class="fa-solid fa-check"></i> ' + escapeHtml(data.before_version || '?') + ' → ' + escapeHtml(data.after_version || '?') + '</span>';
                    html += ' <span class="text-xs text-[var(--color-ink-soft)]">(' + Math.round((data.elapsed_ms || 0) / 100) / 10 + 's)</span>';
                    if (data.was_active && !data.reactivated) {
                        html += ' <span class="status-pill status-red text-[10px] ml-1" title="Plugin was active before the upgrade but failed to re-activate. The site is running with this plugin OFF.">re-activation FAILED</span>';
                    }
                    statusCell.innerHTML = html;
                } else {
                    failed++;
                    statusCell.innerHTML = '<span class="text-[var(--color-status-red)]"><i class="fa-solid fa-xmark"></i> ' + escapeHtml(data.error || 'Failed') + '</span>';
                }
            } catch (err) {
                failed++;
                statusCell.innerHTML = '<span class="text-[var(--color-status-red)]"><i class="fa-solid fa-xmark"></i> Network error: ' + escapeHtml(err.message) + '</span>';
            }
        }

        runBtn.disabled = false;
        runBtn.innerHTML = originalLabel;

        if (failed === 0) {
            summary.className = 'rounded-md p-3 mb-4 text-sm status-green';
            summary.innerHTML = '<i class="fa-solid fa-circle-check"></i> ' + done + ' updated successfully. <a href="#" id="updates-refresh-after" class="underline">Refresh inventory</a> to confirm.';
        } else {
            summary.className = 'rounded-md p-3 mb-4 text-sm status-yellow';
            summary.innerHTML = '<i class="fa-solid fa-triangle-exclamation"></i> ' + done + ' updated, ' + failed + ' failed. <a href="#" id="updates-refresh-after" class="underline">Refresh inventory</a> to see current state.';
        }

        // Best-effort snapshot refresh — fire-and-forget so the next page load is fresh.
        fetch(refreshUrl, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        }).catch(() => {});

        document.getElementById('updates-refresh-after')?.addEventListener('click', (ev) => {
            ev.preventDefault();
            location.reload();
        });
    });

    // Refresh-inventory button (header)
    const refreshBtn = document.getElementById('updates-refresh-btn');
    refreshBtn?.addEventListener('click', async () => {
        const original = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Refreshing…';
        try {
            await fetch(refreshBtn.dataset.url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
            });
            location.reload();
        } catch (e) {
            refreshBtn.disabled = false;
            refreshBtn.innerHTML = original;
            alert('Refresh failed: ' + e.message);
        }
    });

    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }
})();
</script>
