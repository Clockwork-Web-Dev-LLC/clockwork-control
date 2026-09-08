@if ($server->is_ignored)
    <div class="card p-6 text-center text-[var(--color-ink-muted)]">
        Updates are disabled for ignored servers.
    </div>
@else
    @php
        $hasUpdate = $server->upgrade_required;
        $needsReboot = $server->reboot_required;
        $jobActive = in_array($server->update_status, [\App\Models\Server::UPDATE_STATUS_QUEUED, \App\Models\Server::UPDATE_STATUS_RUNNING], true);
        $snapshot = $server->updateSnapshot;
    @endphp
    <div class="card p-5 mb-6">
        <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
            <div>
                <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                    <i class="fa-solid fa-cube text-[var(--color-ink-muted)] mr-1"></i>
                    System updates
                </h2>
                <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                    Polled daily via SSH. Security patches install nightly via unattended-upgrades; this covers everything else.
                </p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                @if ($hasUpdate)
                    <span class="status-pill status-yellow"><i class="fa-solid fa-cube"></i> Patches available</span>
                @else
                    <span class="status-pill status-green"><i class="fa-solid fa-circle-check"></i> Up to date</span>
                @endif
                @if ($needsReboot)
                    <span class="status-pill status-yellow"><i class="fa-solid fa-power-off"></i> Reboot required</span>
                @endif
                @if ($server->ubuntu_version)
                    <span class="text-xs text-[var(--color-ink-muted)] font-data">ubuntu {{ $server->ubuntu_version }}</span>
                @endif
            </div>
        </div>

        {{-- Apt-update snapshot — pulled via SSH by clockwork:poll-system-updates
             (daily 04:15 UTC; runs against servers SpinupWP flagged with
             upgrade_required=true, plus every non-SpinupWP-managed server).
             Gives us the count + security split + the actual per-package list
             that SpinupWP's API doesn't expose, so the operator can see
             exactly what would change before clicking Run updates. --}}
        @if ($snapshot)
            <div class="mb-4 rounded-md border border-[var(--color-border-light)] p-3 text-sm">
                @if ($snapshot->poll_status === \App\Models\ServerUpdateSnapshot::STATUS_OK)
                    <div class="flex items-center gap-4 flex-wrap">
                        <div>
                            <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Packages</span>
                            <div class="font-display text-2xl tabular-nums {{ $snapshot->total_updates > 0 ? 'text-[var(--color-status-yellow)]' : 'text-[var(--color-ink-strong)]' }}">{{ number_format($snapshot->total_updates) }}</div>
                        </div>
                        <div>
                            <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Security</span>
                            <div class="font-display text-2xl tabular-nums {{ $snapshot->security_updates > 0 ? 'text-[var(--color-status-red)]' : 'text-[var(--color-ink-strong)]' }}">{{ number_format($snapshot->security_updates) }}</div>
                        </div>
                        @if ($snapshot->reboot_required && ! empty($snapshot->reboot_required_pkgs))
                            <div class="flex-1 min-w-[200px]">
                                <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Reboot caused by</span>
                                <div class="font-data text-xs text-[var(--color-ink-strong)] truncate" title="{{ implode(', ', $snapshot->reboot_required_pkgs) }}">{{ implode(', ', array_slice($snapshot->reboot_required_pkgs, 0, 6)) }}@if (count($snapshot->reboot_required_pkgs) > 6) <span class="text-[var(--color-ink-soft)]">+ {{ count($snapshot->reboot_required_pkgs) - 6 }} more</span>@endif</div>
                            </div>
                        @endif
                        <div class="ml-auto text-xs text-[var(--color-ink-soft)] whitespace-nowrap" title="{{ $snapshot->polled_at }}">
                            polled {{ $snapshot->polled_at->diffForHumans() }}
                        </div>
                    </div>

                    @if (! empty($snapshot->upgradable_pkgs))
                        <details class="mt-3 border-t border-[var(--color-border-light)] pt-3">
                            <summary class="cursor-pointer text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)] select-none">
                                <i class="fa-solid fa-list text-[10px]"></i>
                                Show upgradable packages ({{ count($snapshot->upgradable_pkgs) }})
                            </summary>
                            <div class="mt-2 max-h-96 overflow-y-auto">
                                <table class="w-full text-xs font-data tabular-nums">
                                    <thead class="text-[var(--color-ink-soft)] text-[10px] uppercase tracking-wide">
                                        <tr>
                                            <th class="text-left py-1">Package</th>
                                            <th class="text-left py-1">Current</th>
                                            <th class="text-left py-1">Available</th>
                                            <th class="text-left py-1">Source</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-[var(--color-border-light)]">
                                        @foreach ($snapshot->upgradable_pkgs as $pkg)
                                            <tr class="{{ ($pkg['security'] ?? false) ? 'bg-[var(--color-status-red)]/5' : '' }}">
                                                <td class="py-1 pr-3 text-[var(--color-ink-strong)] truncate max-w-[260px]" title="{{ $pkg['name'] ?? '' }}">
                                                    @if ($pkg['security'] ?? false)
                                                        <i class="fa-solid fa-shield-halved text-[var(--color-status-red)] text-[9px] mr-1" title="Security update"></i>
                                                    @endif
                                                    {{ $pkg['name'] ?? '' }}
                                                </td>
                                                <td class="py-1 pr-3 text-[var(--color-ink-soft)] truncate max-w-[160px]" title="{{ $pkg['from'] ?? '' }}">{{ $pkg['from'] ?? '' }}</td>
                                                <td class="py-1 pr-3 text-[var(--color-primary-600)] truncate max-w-[160px]" title="{{ $pkg['to'] ?? '' }}">{{ $pkg['to'] ?? '' }}</td>
                                                <td class="py-1 text-[var(--color-ink-muted)] truncate max-w-[180px]" title="{{ $pkg['source'] ?? '' }}">{{ $pkg['source'] ?? '' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </details>
                    @endif
                @else
                    <div class="flex items-start gap-3 text-[var(--color-status-yellow)]">
                        <i class="fa-solid fa-triangle-exclamation mt-0.5"></i>
                        <div class="flex-1">
                            <div class="font-medium">Last poll {{ $snapshot->poll_status === \App\Models\ServerUpdateSnapshot::STATUS_SSH_FAILED ? 'failed (SSH)' : 'could not be parsed' }}</div>
                            <div class="text-xs text-[var(--color-ink-muted)]">{{ $snapshot->polled_at->diffForHumans() }} — counts shown above are from the last known upgrade-required flag only.</div>
                            @if ($snapshot->poll_error)
                                <details class="mt-1">
                                    <summary class="text-xs text-[var(--color-ink-soft)] cursor-pointer">error detail</summary>
                                    <pre class="mt-1 text-xs font-data text-[var(--color-ink-muted)] whitespace-pre-wrap">{{ $snapshot->poll_error }}</pre>
                                </details>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        @elseif ($hasUpdate)
            <div class="mb-4 rounded-md border border-dashed border-[var(--color-border-light)] p-3 text-xs text-[var(--color-ink-soft)]">
                <i class="fa-solid fa-circle-info"></i>
                Per-package detail not yet polled. Runs daily at 04:15, or trigger now: <code class="font-data">php artisan clockwork:poll-system-updates --server={{ $server->name }}</code>
            </div>
        @endif

        @if ($server->update_status === \App\Models\Server::UPDATE_STATUS_QUEUED)
            <div class="rounded-md bg-[var(--color-surface-alt)] p-3 mb-4 flex items-center gap-3 text-sm">
                <i class="fa-solid fa-clock text-[var(--color-ink-muted)]"></i>
                <div class="flex-1">
                    <span class="font-medium">Update queued.</span>
                    <span class="text-[var(--color-ink-muted)]">The processor runs every minute — typically picks up within ~60 seconds.</span>
                </div>
                <form method="POST" action="{{ route('servers.update.cancel', $server) }}">
                    @csrf
                    <button type="submit" class="btn-pill-nav text-xs">Cancel</button>
                </form>
            </div>
        @elseif ($server->update_status === \App\Models\Server::UPDATE_STATUS_RUNNING)
            <div class="rounded-md bg-[var(--color-surface-alt)] p-3 mb-4 flex items-center gap-3 text-sm">
                <i class="fa-solid fa-spinner fa-spin text-[var(--color-primary-500)]"></i>
                <div class="flex-1">
                    <span class="font-medium">Update running.</span>
                    <span class="text-[var(--color-ink-muted)]">Started {{ $server->update_started_at?->diffForHumans() }}. apt-get upgrade can take a few minutes — leave the page open or refresh later.</span>
                </div>
            </div>
            <script>setTimeout(() => location.reload(), 30000);</script>
        @elseif ($server->update_status === \App\Models\Server::UPDATE_STATUS_COMPLETED)
            <div class="rounded-md bg-[var(--color-surface-alt)] p-3 mb-4 flex items-start gap-3 text-sm">
                <i class="fa-solid fa-circle-check text-[var(--color-status-green)] mt-0.5"></i>
                <div class="flex-1">
                    <span class="font-medium">Last update completed</span>
                    <span class="text-[var(--color-ink-muted)]">{{ $server->update_completed_at?->diffForHumans() }}.</span>
                    @if ($server->scheduled_reboot_at && $server->scheduled_reboot_at->isFuture())
                        <span class="block mt-1 text-xs text-[var(--color-ink-muted)]">
                            Reboot scheduled for {{ $server->scheduled_reboot_at->format('M j, H:i T') }}.
                        </span>
                    @endif
                </div>
            </div>
        @elseif ($server->update_status === \App\Models\Server::UPDATE_STATUS_FAILED)
            <div class="rounded-md bg-[var(--color-surface-alt)] p-3 mb-4 flex items-start gap-3 text-sm">
                <i class="fa-solid fa-triangle-exclamation text-[var(--color-status-red)] mt-0.5"></i>
                <div class="flex-1">
                    <span class="font-medium text-[var(--color-status-red)]">Last update failed</span>
                    <span class="text-[var(--color-ink-muted)]">{{ $server->update_completed_at?->diffForHumans() }}. See log below.</span>
                </div>
            </div>
        @endif

        @unless ($jobActive)
            {{-- Two-row form: label + (input + button) on one row so they share
                 a baseline; helper text drops below the whole form so it doesn't
                 push the button out of alignment with the input. --}}
            <form method="POST" action="{{ route('servers.update.queue', $server) }}">
                @csrf
                <label class="block text-xs uppercase tracking-wide text-[var(--color-ink-soft)] mb-1">
                    Schedule reboot at (optional, server-local)
                </label>
                <div class="flex items-stretch gap-3 flex-wrap">
                    <input type="time" name="reboot_at"
                           class="font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                    <button type="submit" class="btn-primary"
                            onclick="return confirm('Run apt-get update + upgrade + autoremove on {{ $server->name }}? Takes 1–3 minutes.')">
                        <i class="fa-solid fa-cube"></i>
                        Run updates
                    </button>
                </div>
                <p class="text-xs text-[var(--color-ink-soft)] mt-2">Leave blank for no reboot. A time in the past = tomorrow.</p>
            </form>
        @endunless

        @if ($server->last_update_log)
            <details class="mt-4">
                <summary class="text-xs text-[var(--color-ink-soft)] cursor-pointer">Last update output</summary>
                <pre class="mt-2 bg-[var(--color-surface-alt)] border border-[var(--color-border-light)] rounded-md p-3 font-data text-xs text-[var(--color-ink-muted)] overflow-x-auto whitespace-pre-wrap max-h-96">{{ $server->last_update_log }}</pre>
            </details>
        @endif
    </div>

    {{-- Standalone reboot — separate from "Run updates" because a box can need a reboot
         without needing apt to do anything (typical after an unattended-upgrade kernel).
         Card also appears for ~15 min after a reboot so the user can confirm it took. --}}
    @php
        $rebootRecent = $server->scheduled_reboot_at
            && $server->scheduled_reboot_at->gt(now()->subMinutes(15));
    @endphp
    @if ($needsReboot || $rebootRecent)
        <div class="card p-5 mb-6">
            <div class="flex items-start justify-between gap-4 flex-wrap mb-4">
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        <i class="fa-solid fa-power-off text-[var(--color-status-yellow)] mr-1"></i>
                        Reboot
                    </h2>
                    <p class="text-xs text-[var(--color-ink-soft)] mt-0.5">
                        @if ($needsReboot)
                            <code>/var/run/reboot-required</code> is present — kernel/libc upgrade is waiting for a cycle.
                        @elseif ($rebootRecent && ! ($server->scheduled_reboot_at?->isFuture()))
                            Reboot was triggered {{ $server->scheduled_reboot_at->diffForHumans() }}. Click <strong>Recheck state</strong> once the box is back to confirm.
                        @else
                            No reboot is required right now, but a scheduled one is pending.
                        @endif
                    </p>
                </div>
                <button type="button" id="reboot-recheck"
                        data-url="{{ route('servers.reboot.probe', $server) }}"
                        class="text-xs text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] disabled:opacity-50">
                    <i class="fa-solid fa-rotate"></i> Recheck state
                </button>
            </div>

            <div id="reboot-recheck-result" class="hidden mb-3 text-sm"></div>

            @if ($server->scheduled_reboot_at && $server->scheduled_reboot_at->isFuture())
                <div class="rounded-md bg-[var(--color-surface-alt)] p-3 mb-4 flex items-center gap-3 text-sm">
                    <i class="fa-solid fa-clock text-[var(--color-ink-muted)]"></i>
                    <div class="flex-1">
                        <span class="font-medium">Reboot scheduled.</span>
                        <span class="text-[var(--color-ink-muted)]">Server-local {{ $server->scheduled_reboot_at->format('M j, H:i T') }} ({{ $server->scheduled_reboot_at->diffForHumans() }}).</span>
                    </div>
                    <form method="POST" action="{{ route('servers.reboot.cancel', $server) }}"
                          onsubmit="return confirm('Cancel the scheduled reboot on {{ $server->name }}?');">
                        @csrf
                        <button type="submit" class="btn-pill-nav text-xs">Cancel</button>
                    </form>
                </div>
            @endif

            @if ($needsReboot)
                <div class="flex items-end gap-3 flex-wrap">
                    <form method="POST" action="{{ route('servers.reboot', $server) }}" class="flex items-end gap-2"
                          onsubmit="return confirm('Reboot {{ $server->name }} in ~1 minute? Active SSH sessions will drop.');">
                        @csrf
                        <button type="submit" class="btn-primary">
                            <i class="fa-solid fa-power-off"></i>
                            Reboot now
                        </button>
                    </form>

                    <form method="POST" action="{{ route('servers.reboot', $server) }}" class="flex items-end gap-2"
                          onsubmit="if (! this.reboot_at.value) { alert('Pick a time first.'); return false; } return confirm('Reboot {{ $server->name }} at ' + this.reboot_at.value + ' (server-local)?');">
                        @csrf
                        <label class="block">
                            <span class="text-xs uppercase tracking-wide text-[var(--color-ink-soft)]">Or schedule for (server-local)</span>
                            <input type="time" name="reboot_at"
                                   class="mt-1 font-data border border-[var(--color-border)] rounded-md px-3 py-2 focus:outline-none focus:ring-2 focus:ring-[var(--color-primary-200)] focus:border-[var(--color-primary-500)]">
                        </label>
                        <button type="submit" class="btn-pill-nav">
                            <i class="fa-solid fa-clock"></i>
                            Schedule
                        </button>
                    </form>
                </div>
                <p class="text-xs text-[var(--color-ink-soft)] mt-3">
                    <i class="fa-solid fa-circle-info"></i>
                    "Reboot now" runs <code>shutdown -r +1</code> so this SSH session can return cleanly. A scheduled reboot can be cancelled until it actually starts.
                </p>
            @endif
        </div>

        <script>
            (function () {
                const recheck = document.getElementById('reboot-recheck');
                const result = document.getElementById('reboot-recheck-result');
                recheck?.addEventListener('click', async () => {
                    recheck.disabled = true;
                    recheck.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Probing…';
                    result.className = 'mb-3 text-sm text-[var(--color-ink-muted)]';
                    result.textContent = 'Asking the box if /var/run/reboot-required exists…';
                    result.classList.remove('hidden');
                    try {
                        const r = await fetch(recheck.dataset.url, {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
                        });
                        const data = await r.json();
                        if (data.ok) {
                            result.className = 'mb-3 text-sm status-pill ' + (data.reboot_required ? 'status-yellow' : 'status-green') + ' inline-block';
                            result.innerHTML = '<i class="fa-solid ' + (data.reboot_required ? 'fa-triangle-exclamation' : 'fa-circle-check') + '"></i> ' + data.message + ' Reloading…';
                            setTimeout(() => location.reload(), 1200);
                        } else {
                            result.className = 'mb-3 text-sm status-pill status-red inline-block';
                            result.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> ' + (data.message || 'Failed.');
                        }
                    } catch (e) {
                        result.className = 'mb-3 text-sm status-pill status-red inline-block';
                        result.textContent = 'Network error: ' + e.message;
                    } finally {
                        recheck.disabled = false;
                        recheck.innerHTML = '<i class="fa-solid fa-rotate"></i> Recheck state';
                    }
                });
            })();
        </script>
    @endif
@endif
