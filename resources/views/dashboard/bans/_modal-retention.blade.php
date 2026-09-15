<div x-data="{
    open: false,
    tab: 'policy',
    selectedMonths: '{{ $retentionMonths ?? 12 }}',
    pruneMonths: '3',
    saving: false
}"
@open-ban-retention-modal.window="open = true; if ($event.detail && $event.detail.mode) tab = $event.detail.mode;"
x-show="open"
x-transition:enter="transition ease-out duration-200"
x-transition:enter-start="opacity-0"
x-transition:enter-end="opacity-100"
x-transition:leave="transition ease-in duration-150"
x-transition:leave-start="opacity-100"
x-transition:leave-end="opacity-0"
x-cloak
class="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 sm:p-6"
@keydown.escape.window="open = false">

    <div class="bg-[var(--color-surface)] border border-[var(--color-border-light)] rounded-[var(--radius-card)] shadow-2xl w-full max-w-2xl max-h-[90vh] flex flex-col overflow-hidden text-[var(--color-ink-strong)]"
         @click.outside="open = false">

        {{-- Modal Header --}}
        <div class="px-6 py-4 border-b border-[var(--color-border-light)] flex items-start justify-between gap-4 bg-[var(--color-surface-alt)]/40">
            <div class="flex items-center gap-3">
                <div class="w-9 h-9 rounded-lg bg-[var(--color-brand)]/10 text-[var(--color-brand)] flex items-center justify-center text-base">
                    <i class="fa-solid fa-clock-rotate-left"></i>
                </div>
                <div>
                    <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                        Ban Retention &amp; Cleanup
                    </h2>
                    <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                        Manage automated expiration policies and bulk prune stale active bans.
                    </p>
                </div>
            </div>
            <button type="button"
                    @click="open = false"
                    class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] p-1.5 rounded-lg hover:bg-[var(--color-surface-alt)] cursor-pointer transition-colors"
                    title="Close dialog">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        {{-- Tab Switcher --}}
        <div class="px-6 py-2.5 bg-[var(--color-surface)] border-b border-[var(--color-border-light)] flex items-center gap-2">
            <button type="button"
                    @click="tab = 'policy'"
                    class="px-3 py-1.5 rounded-md text-xs font-medium cursor-pointer transition-colors"
                    :class="tab === 'policy' ? 'bg-[var(--color-brand)]/10 text-[var(--color-brand)] font-semibold' : 'text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]'">
                <i class="fa-solid fa-sliders text-[10px] mr-1.5"></i>
                Retention Policy
            </button>
            <button type="button"
                    @click="tab = 'bulk'"
                    class="px-3 py-1.5 rounded-md text-xs font-medium cursor-pointer transition-colors"
                    :class="tab === 'bulk' ? 'bg-[var(--color-brand)]/10 text-[var(--color-brand)] font-semibold' : 'text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]'">
                <i class="fa-solid fa-broom text-[10px] mr-1.5"></i>
                Bulk Clear / Prune Now
            </button>
        </div>

        {{-- Modal Body --}}
        <div class="p-6 overflow-y-auto space-y-5 flex-1">

            {{-- Tab 1: Retention Policy --}}
            <div x-show="tab === 'policy'" class="space-y-4">
                <div class="p-3.5 rounded-lg bg-blue-50/60 dark:bg-blue-950/30 border border-blue-100 dark:border-blue-900/40 text-xs text-[var(--color-ink)] leading-relaxed">
                    <div class="flex items-start gap-2.5">
                        <i class="fa-solid fa-circle-info text-blue-500 mt-0.5 shrink-0"></i>
                        <div>
                            <strong>How Ban Expiration Works:</strong> Linux Fail2ban jails automatically release kernel iptables blocks after 24 hours. Active ban records in Clockwork are preserved for repeat-offender intelligence and security auditing. Nightly at <strong>04:33</strong>, the automated cleaner moves bans older than your retention period into History as <em>expired</em>.
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('bans.retention.update') }}" class="space-y-4">
                    @csrf
                    @method('PATCH')

                    <div>
                        <label class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-2">
                            Automatic Retention Period
                        </label>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5">
                            @php
                                $retentionOptions = [
                                    ['val' => '1', 'title' => '1 Month', 'desc' => 'Aggressive pruning (30 days)'],
                                    ['val' => '3', 'title' => '3 Months', 'desc' => 'Quarterly retention'],
                                    ['val' => '6', 'title' => '6 Months', 'desc' => 'Semi-annual retention'],
                                    ['val' => '12', 'title' => '12 Months', 'desc' => '1 Year (Default / Recommended)'],
                                    ['val' => '24', 'title' => '24 Months', 'desc' => '2 Years extended audit memory'],
                                    ['val' => '0', 'title' => 'Never', 'desc' => 'Retain active bans indefinitely'],
                                ];
                            @endphp

                            @foreach ($retentionOptions as $opt)
                                <label class="flex items-start gap-3 p-3 rounded-lg border cursor-pointer transition-all"
                                       :class="selectedMonths === '{{ $opt['val'] }}' ? 'border-[var(--color-brand)] bg-[var(--color-brand)]/5 ring-1 ring-[var(--color-brand)]/40' : 'border-[var(--color-border-light)] hover:bg-[var(--color-surface-alt)]'">
                                    <input type="radio" name="months" value="{{ $opt['val'] }}" x-model="selectedMonths" class="mt-0.5 text-[var(--color-brand)] focus:ring-[var(--color-brand)]">
                                    <div class="min-w-0">
                                        <div class="text-xs font-semibold text-[var(--color-ink-strong)]">{{ $opt['title'] }}</div>
                                        <div class="text-[11px] text-[var(--color-ink-soft)] truncate">{{ $opt['desc'] }}</div>
                                    </div>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <div class="pt-2 flex items-center justify-end gap-3 border-t border-[var(--color-border-light)]">
                        <button type="button" @click="open = false" class="px-4 py-2 text-xs rounded-md text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">
                            Cancel
                        </button>
                        <button type="submit" class="btn-primary text-xs px-4 py-2">
                            <i class="fa-solid fa-check mr-1.5"></i> Save Retention Policy
                        </button>
                    </div>
                </form>
            </div>

            {{-- Tab 2: Bulk Clear / Prune Now --}}
            <div x-show="tab === 'bulk'" class="space-y-4">
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-2">
                        Current Active Ban Breakdown
                    </h3>
                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-center">
                        <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Total Active</div>
                            <div class="text-base font-bold font-data text-[var(--color-ink-strong)]">{{ number_format($banBreakdown['total'] ?? 0) }}</div>
                        </div>
                        <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">&gt; 1 Month</div>
                            <div class="text-base font-bold font-data text-amber-600 dark:text-amber-400">{{ number_format($banBreakdown['older_than_1m'] ?? 0) }}</div>
                        </div>
                        <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">&gt; 3 Months</div>
                            <div class="text-base font-bold font-data text-amber-600 dark:text-amber-400">{{ number_format($banBreakdown['older_than_3m'] ?? 0) }}</div>
                        </div>
                        <div class="p-2.5 rounded-lg bg-[var(--color-surface-alt)] border border-[var(--color-border-light)]">
                            <div class="text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">&gt; 12 Months</div>
                            <div class="text-base font-bold font-data text-[var(--color-ink-soft)]">{{ number_format($banBreakdown['older_than_12m'] ?? 0) }}</div>
                        </div>
                    </div>
                </div>

                <div class="p-3.5 rounded-lg bg-amber-50/70 dark:bg-amber-950/30 border border-amber-200/70 dark:border-amber-900/40 text-xs text-[var(--color-ink)] leading-relaxed">
                    <div class="flex items-start gap-2.5">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5 shrink-0"></i>
                        <div>
                            <strong>Bulk Clear Notice:</strong> Pruning removes selected bans from the active table and archives them to History as <em>expired</em> (original LLM verdict is kept). Since Linux Fail2ban iptables bans are temporary (24 hours), unpruned old records do not affect live network traffic.
                        </div>
                    </div>
                </div>

                <form method="POST" action="{{ route('bans.prune') }}" class="space-y-4"
                      onsubmit="return confirm('Are you sure you want to prune expired bans matching this cutoff? They will be archived to History.');">
                    @csrf

                    <div>
                        <label for="prune-months-select" class="block text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)] mb-1.5">
                            Prune Cutoff Threshold
                        </label>
                        <select id="prune-months-select" name="months" x-model="pruneMonths"
                                class="w-full text-xs rounded-md border border-[var(--color-border-light)] bg-[var(--color-surface)] text-[var(--color-ink-strong)] px-3 py-2.5 focus:ring-2 focus:ring-[var(--color-brand)] focus:outline-none">
                            <option value="1">Older than 1 month ({{ number_format($banBreakdown['older_than_1m'] ?? 0) }} bans)</option>
                            <option value="3">Older than 3 months ({{ number_format($banBreakdown['older_than_3m'] ?? 0) }} bans)</option>
                            <option value="6">Older than 6 months ({{ number_format($banBreakdown['older_than_6m'] ?? 0) }} bans)</option>
                            <option value="12">Older than 12 months ({{ number_format($banBreakdown['older_than_12m'] ?? 0) }} bans)</option>
                            <option value="0">All active bans ({{ number_format($banBreakdown['total'] ?? 0) }} bans)</option>
                        </select>
                    </div>

                    <div class="pt-2 flex items-center justify-end gap-3 border-t border-[var(--color-border-light)]">
                        <button type="button" @click="open = false" class="px-4 py-2 text-xs rounded-md text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)]">
                            Cancel
                        </button>
                        <button type="submit" class="btn-pill-nav text-xs px-4 py-2 text-amber-700 dark:text-amber-300 border-amber-300 dark:border-amber-800 hover:bg-amber-50 dark:hover:bg-amber-950/40">
                            <i class="fa-solid fa-broom mr-1.5"></i> Prune Expired Bans Now
                        </button>
                    </div>
                </form>
            </div>

        </div>
    </div>
</div>
