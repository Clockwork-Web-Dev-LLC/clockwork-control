@extends('layouts.app')

@section('title', 'Server updates · Operations · Clockwork')

@section('content')
    @php
        use App\Models\Server;
        use App\Models\ServerUpdateSnapshot;
    @endphp

    @include('operations._tabs')

    <x-page-header title="Fleet server updates"
        subtitle="Apt-update snapshot for every non-ignored server. Pick servers to queue one-by-one or all at once. Per-server details on each row link to the server's Updates tab." />

    @if (session('status'))
        <div class="mb-4 px-4 py-2 rounded-md bg-[var(--color-status-green)]/10 text-[var(--color-status-green)] text-sm">
            <i class="fa-solid fa-circle-check mr-1"></i> {{ session('status') }}
        </div>
    @endif
    @if (session('status_error'))
        <div class="mb-4 px-4 py-2 rounded-md bg-[var(--color-status-red)]/10 text-[var(--color-status-red)] text-sm">
            <i class="fa-solid fa-triangle-exclamation mr-1"></i> {{ session('status_error') }}
        </div>
    @endif

    {{-- Roll-up tiles --}}
    <div class="grid grid-cols-2 md:grid-cols-5 gap-3 mb-6">
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Servers</div>
            <div class="text-2xl font-display text-[var(--color-ink-strong)] font-data">{{ number_format($totals['fleet_size']) }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">{{ $totals['polled_ok'] }} polled · {{ $totals['never_polled'] }} never · {{ $totals['failed_polls'] }} failed</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Packages pending</div>
            <div class="text-2xl font-display {{ $totals['total_updates'] > 0 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-status-green)]' }} font-data">{{ number_format($totals['total_updates']) }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Across the fleet</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Security</div>
            <div class="text-2xl font-display {{ $totals['security_updates'] > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-status-green)]' }} font-data">{{ number_format($totals['security_updates']) }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">From -security repos</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Reboot pending</div>
            <div class="text-2xl font-display {{ $totals['reboot_pending'] > 0 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-status-green)]' }} font-data">{{ number_format($totals['reboot_pending']) }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">Server count</div>
        </div>
        <div class="card px-4 py-3">
            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">In flight</div>
            <div class="text-2xl font-display {{ ($totals['queued'] + $totals['running']) > 0 ? 'text-[var(--color-primary-600)]' : 'text-[var(--color-ink-strong)]' }} font-data">{{ number_format($totals['queued'] + $totals['running']) }}</div>
            <div class="text-[10px] text-[var(--color-ink-soft)] mt-0.5">{{ $totals['queued'] }} queued · {{ $totals['running'] }} running</div>
        </div>
    </div>

    {{-- Auto-refresh: when a background poll is in flight, reload the page
         every 10s so the user watches "polled X of Y" tick up live, then
         falls through to a normal render once the marker clears. --}}
    @if ($pollInProgress)
        <meta http-equiv="refresh" content="10">
    @endif

    {{-- Toolbar --}}
    <div class="card p-4 mb-4 flex items-center gap-3 flex-wrap">
        <form method="POST" action="{{ route('operations.server-updates.refresh') }}" class="flex items-center gap-2">
            @csrf
            <button type="submit"
                    class="btn-pill-nav text-sm @if($pollInProgress) opacity-50 cursor-not-allowed @endif"
                    @if($pollInProgress) disabled @endif
                    onclick="return confirm('Re-poll every non-ignored server now? Runs in the background — takes a few minutes.')">
                <i class="fa-solid @if($pollInProgress) fa-spinner fa-spin @else fa-rotate @endif"></i>
                {{ $pollInProgress ? 'Polling in background…' : 'Re-poll fleet now' }}
            </button>
        </form>
        <div class="text-xs text-[var(--color-ink-soft)]">
            Or wait — <code class="font-data">clockwork:poll-system-updates</code> runs daily at 04:15 UTC.
        </div>
    </div>

    @if ($pollInProgress)
        <div class="card p-4 mb-4 border-l-4 border-amber-500">
            <div class="text-sm">
                <strong>Background poll in progress.</strong>
                Polled <strong>{{ $polledSinceStart }}</strong> of <strong>{{ $servers->count() }}</strong> servers
                @if ($pollStartedAt)
                    · started {{ $pollStartedAt->diffForHumans() }}
                @endif
                · this page reloads every 10s until done.
            </div>
        </div>
    @endif

    @if ($servers->isEmpty())
        <div class="card p-10 text-center text-[var(--color-ink-soft)]">No servers in the fleet.</div>
    @else
        <form method="POST" action="{{ route('operations.server-updates.queueBulk') }}" id="updates-bulk-form">
            @csrf
            <div class="card overflow-hidden">
                <table class="w-full text-sm" x-data="sortableTable({ defaultKey: 'updates', defaultDir: 'desc' })">
                    <thead class="bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)] text-xs uppercase tracking-wide">
                        <tr>
                            <th class="px-5 py-2 w-10">
                                <input type="checkbox" id="select-all" class="rounded">
                            </th>
                            <x-sort-th key="name" class="px-5 py-2">Server</x-sort-th>
                            <x-sort-th key="status" class="px-5 py-2">State</x-sort-th>
                            <x-sort-th key="updates" class="px-5 py-2 text-right">Packages</x-sort-th>
                            <x-sort-th key="security" class="px-5 py-2 text-right">Security</x-sort-th>
                            <x-sort-th key="reboot" class="px-5 py-2">Reboot</x-sort-th>
                            <x-sort-th key="polled" class="px-5 py-2">Polled</x-sort-th>
                            <th class="px-5 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($servers as $server)
                            @php
                                $snap = $server->updateSnapshot;
                                $jobActive = in_array($server->update_status, [Server::UPDATE_STATUS_QUEUED, Server::UPDATE_STATUS_RUNNING], true);
                                $missingSsh = $server->clockwork_jail_provisioned_at === null && empty($server->ssh_password);

                                $stateRank = 0;
                                $stateLabel = '—';
                                $stateClass = 'bg-[var(--color-surface-alt)] text-[var(--color-ink-muted)]';
                                if ($jobActive) {
                                    $stateRank = 5;
                                    $stateLabel = $server->update_status === Server::UPDATE_STATUS_RUNNING ? 'running' : 'queued';
                                    $stateClass = 'bg-[var(--color-primary-100)] text-[var(--color-primary-700)]';
                                } elseif (! $snap) {
                                    $stateRank = 1;
                                    $stateLabel = 'never polled';
                                } elseif ($snap->poll_status !== ServerUpdateSnapshot::STATUS_OK) {
                                    $stateRank = 0;
                                    $stateLabel = $snap->poll_status === ServerUpdateSnapshot::STATUS_SSH_FAILED ? 'ssh failed' : 'parse failed';
                                    $stateClass = 'bg-[var(--color-status-red)]/10 text-[var(--color-status-red)]';
                                } elseif ($snap->total_updates === 0 && ! $snap->reboot_required) {
                                    $stateRank = 4;
                                    $stateLabel = 'up to date';
                                    $stateClass = 'bg-[var(--color-status-green)]/15 text-[var(--color-status-green)]';
                                } elseif ($snap->security_updates > 0) {
                                    $stateRank = 2;
                                    $stateLabel = 'security';
                                    $stateClass = 'bg-[var(--color-status-red)]/15 text-[var(--color-status-red)]';
                                } else {
                                    $stateRank = 3;
                                    $stateLabel = 'patches';
                                    $stateClass = 'bg-[var(--color-status-yellow)]/15 text-[var(--color-status-yellow)]';
                                }

                                $totalUpdates = $snap?->total_updates ?? 0;
                                $securityUpdates = $snap?->security_updates ?? 0;
                                $rebootRequired = $snap?->reboot_required ?? $server->reboot_required;
                                $polledTs = $snap?->polled_at?->getTimestamp() ?? 0;
                                $isUpToDate = $stateLabel === 'up to date';
                                $isStaging = $server->isStaging();
                                $disableCheckbox = $jobActive || $missingSsh || $server->is_ignored || $isUpToDate || $isStaging;
                                $disableReason = match (true) {
                                    $jobActive => 'Update currently in flight',
                                    $server->is_ignored => 'Server is ignored',
                                    $isStaging => 'Staging server — never drained by clockwork:process-server-updates',
                                    $missingSsh => 'No SSH credentials configured',
                                    $isUpToDate => 'Server is up to date',
                                    default => null,
                                };
                            @endphp
                            <tr
                                class="{{ $jobActive ? 'bg-[var(--color-primary-50)]' : '' }}"
                                data-sort-name="{{ strtolower($server->name) }}"
                                data-sort-status="{{ $stateRank }}"
                                data-sort-updates="{{ $totalUpdates }}"
                                data-sort-security="{{ $securityUpdates }}"
                                data-sort-reboot="{{ $rebootRequired ? 1 : 0 }}"
                                data-sort-polled="{{ $polledTs }}">
                                <td class="px-5 py-2">
                                    <input type="checkbox" name="server_ids[]" value="{{ $server->id }}"
                                           class="rounded server-row-checkbox"
                                           {{ $disableCheckbox ? 'disabled' : '' }}
                                           @if ($disableReason) title="{{ $disableReason }}" @endif
                                           data-sec="{{ $securityUpdates }}"
                                           data-reboot="{{ $rebootRequired ? 1 : 0 }}">
                                </td>
                                <td class="px-5 py-2 font-data">
                                    <a href="{{ route('servers.show', [$server, 'updates']) }}" class="text-[var(--color-primary-600)] hover:underline" title="{{ $server->name }}">{{ $server->display_name }}</a>
                                    @if ($missingSsh)
                                        <span class="ml-1 text-[10px] text-[var(--color-status-yellow)]" title="No SSH credentials — bulk queue will skip this row">
                                            <i class="fa-solid fa-key"></i> no ssh
                                        </span>
                                    @endif
                                </td>
                                <td class="px-5 py-2">
                                    <span class="text-[10px] px-2 py-0.5 rounded-full font-medium {{ $stateClass }}">{{ $stateLabel }}</span>
                                </td>
                                <td class="px-5 py-2 text-right font-data tabular-nums {{ $totalUpdates > 0 ? 'text-[var(--color-status-yellow)] font-medium' : 'text-[var(--color-ink-soft)]' }}">
                                    {{ number_format($totalUpdates) }}
                                </td>
                                <td class="px-5 py-2 text-right font-data tabular-nums {{ $securityUpdates > 0 ? 'text-[var(--color-status-red)] font-medium' : 'text-[var(--color-ink-soft)]' }}">
                                    {{ number_format($securityUpdates) }}
                                </td>
                                <td class="px-5 py-2">
                                    @if ($rebootRequired)
                                        <span class="text-[10px] text-[var(--color-status-yellow)]"><i class="fa-solid fa-power-off"></i> required</span>
                                    @else
                                        <span class="text-[10px] text-[var(--color-ink-soft)]">—</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-xs text-[var(--color-ink-muted)]">
                                    @if ($snap)
                                        <span title="{{ $snap->polled_at }}">{{ $snap->polled_at->diffForHumans() }}</span>
                                    @else
                                        <span class="text-[var(--color-ink-soft)]">never</span>
                                    @endif
                                </td>
                                <td class="px-5 py-2 text-right whitespace-nowrap">
                                    @if ($jobActive)
                                        <span class="text-xs text-[var(--color-primary-700)]"><i class="fa-solid fa-spinner {{ $server->update_status === Server::UPDATE_STATUS_RUNNING ? 'fa-spin' : '' }}"></i> {{ $stateLabel }}</span>
                                    @else
                                        @if (! $disableCheckbox && ! $isUpToDate)
                                            <button type="submit"
                                                    form="single-update-{{ $server->id }}"
                                                    class="btn-pill-nav text-xs mr-2 text-[var(--color-primary-700)] hover:bg-[var(--color-primary-50)]"
                                                    onclick="return confirm('Are you sure you want to run updates and reboot on {{ $server->display_name }}?');"
                                                    title="Install updates and reboot immediately">
                                                <i class="fa-solid fa-bolt"></i> Update & reboot
                                            </button>
                                        @endif
                                        <a href="{{ route('servers.show', [$server, 'updates']) }}" class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]" title="Open per-server Updates tab to queue or schedule reboot">
                                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Manage
                                        </a>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            {{-- Sticky bulk-action toolbar. --}}
            <div class="sticky bottom-0 z-40 mt-4 -mx-4 px-4 pb-4 pt-2 bg-gradient-to-t from-[var(--color-surface)] via-[var(--color-surface)] to-transparent">
                <div class="card p-4 flex items-end gap-3 flex-wrap shadow-lg ring-1 ring-[var(--color-border)]">
                    <div class="flex gap-2 text-xs">
                        <button type="button" id="select-with-updates" class="btn-pill-nav">
                            <i class="fa-solid fa-check-double"></i> Select all with packages
                        </button>
                        <button type="button" id="select-with-security" class="btn-pill-nav">
                            <i class="fa-solid fa-shield-halved"></i> Security only
                        </button>
                        <button type="button" id="select-clear" class="btn-pill-nav">Clear</button>
                    </div>
                    <div class="ml-auto flex items-end gap-3 flex-wrap">
                        <input type="hidden" name="reboot_immediate" value="1">
                        <div>
                            <label for="bulk-reboot-at" class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">
                                Reboot at <span class="normal-case text-[var(--color-ink-muted)]">(optional, server-local; blank = immediate)</span>
                            </label>
                            <input type="time" id="bulk-reboot-at" name="reboot_at"
                                   class="font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                            <div class="text-[10px] text-[var(--color-ink-soft)] mt-1">Leave blank to install & reboot immediately after upgrade completes.</div>
                        </div>
                        <div class="flex flex-col gap-1">
                            <button type="submit" id="bulk-queue-btn" class="btn-primary disabled:opacity-50 disabled:cursor-not-allowed" disabled
                                    onclick="return confirmBulkSubmit(this);">
                                <i class="fa-solid fa-bolt"></i>
                                <span id="bulk-btn-label">Install updates & reboot immediately</span> (<span data-bulk-count>0</span>)
                            </button>
                            <span class="text-[10px] text-[var(--color-ink-soft)]">Drains via <code class="font-data">clockwork:process-server-updates</code> (every minute, one server per tick)</span>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        {{-- Hidden single-server forms for row "Update & reboot" button --}}
        @foreach ($servers as $s)
            @php
                $sSnap = $s->updateSnapshot;
                $sReboot = $sSnap?->reboot_required ?? $s->reboot_required;
                $sUpToDate = $sSnap && $sSnap->poll_status === ServerUpdateSnapshot::STATUS_OK && $sSnap->total_updates === 0 && ! $sReboot;
            @endphp
            @if (! in_array($s->update_status, [Server::UPDATE_STATUS_QUEUED, Server::UPDATE_STATUS_RUNNING], true) && ! $s->is_ignored && ($s->clockwork_jail_provisioned_at !== null || ! empty($s->ssh_password)) && ! $sUpToDate)
                <form id="single-update-{{ $s->id }}" method="POST" action="{{ route('operations.server-updates.queueBulk') }}" class="hidden">
                    @csrf
                    <input type="hidden" name="server_ids[]" value="{{ $s->id }}">
                    <input type="hidden" name="reboot_immediate" value="1">
                </form>
            @endif
        @endforeach

        <script>
            (function () {
                const form = document.getElementById('updates-bulk-form');
                if (! form) return;

                const checkboxes = () => Array.from(form.querySelectorAll('.server-row-checkbox:not(:disabled)'));
                const selectAll = document.getElementById('select-all');
                const counter = form.querySelector('[data-bulk-count]');
                const submit = document.getElementById('bulk-queue-btn');

                function syncCounter() {
                    const checked = checkboxes().filter(c => c.checked).length;
                    if (counter) counter.textContent = checked;
                    if (submit) submit.disabled = checked === 0;

                    if (selectAll) {
                        const all = checkboxes();
                        const checkedAll = all.length > 0 && all.every(c => c.checked);
                        const checkedNone = all.every(c => ! c.checked);
                        selectAll.checked = checkedAll;
                        selectAll.indeterminate = ! checkedAll && ! checkedNone;
                    }
                }

                form.addEventListener('change', (e) => {
                    if (e.target.classList.contains('server-row-checkbox')) syncCounter();
                });

                selectAll?.addEventListener('change', () => {
                    checkboxes().forEach(c => { c.checked = selectAll.checked; });
                    syncCounter();
                });

                document.getElementById('select-with-updates')?.addEventListener('click', () => {
                    checkboxes().forEach(c => { c.checked = true; });
                    syncCounter();
                });
                document.getElementById('select-with-security')?.addEventListener('click', () => {
                    checkboxes().forEach(c => {
                        c.checked = parseInt(c.dataset.sec || '0', 10) > 0;
                    });
                    syncCounter();
                });
                document.getElementById('select-clear')?.addEventListener('click', () => {
                    checkboxes().forEach(c => { c.checked = false; });
                    syncCounter();
                });

                const rebootInput = document.getElementById('bulk-reboot-at');
                const btnLabel = document.getElementById('bulk-btn-label');
                function syncRebootLabel() {
                    if (btnLabel) {
                        btnLabel.textContent = rebootInput?.value
                            ? 'Queue updates & schedule reboot'
                            : 'Install updates & reboot immediately';
                    }
                }
                rebootInput?.addEventListener('input', syncRebootLabel);

                syncCounter();
                syncRebootLabel();
            })();

            function confirmBulkSubmit(btn) {
                const rebootAt = document.getElementById('bulk-reboot-at')?.value;
                if (rebootAt) {
                    return confirm(`Are you sure you want to run updates on all selected servers and schedule reboot for ${rebootAt}?`);
                }
                return confirm("are you sure you want to run updates and reboot on all selected servers?");
            }
        </script>
    @endif
@endsection
