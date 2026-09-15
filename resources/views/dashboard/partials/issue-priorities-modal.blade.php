{{-- MANAGE CATEGORY PRIORITIES MODAL --}}
<div x-show="prioritiesModalOpen"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     x-cloak
     class="fixed inset-0 z-50 overflow-y-auto bg-black/60 backdrop-blur-xs flex items-center justify-center p-4 sm:p-6"
     @keydown.escape.window="prioritiesModalOpen = false">

    <div class="bg-[var(--color-surface)] border border-[var(--color-border-light)] rounded-[var(--radius-card)] shadow-2xl w-full max-w-3xl max-h-[90vh] flex flex-col overflow-hidden text-[var(--color-ink-strong)]"
         @click.outside="prioritiesModalOpen = false">

        {{-- Modal Header --}}
        <div class="px-6 py-4 border-b border-[var(--color-border-light)] flex items-start justify-between gap-4 bg-[var(--color-surface-alt)]/40">
            <div>
                <div class="flex items-center gap-2">
                    <div class="w-8 h-8 rounded-lg bg-[var(--color-brand)]/10 text-[var(--color-brand)] flex items-center justify-center">
                        <i class="fa-solid fa-sliders"></i>
                    </div>
                    <div>
                        <h2 class="font-display text-lg font-semibold text-[var(--color-ink-strong)]">
                            Alert Categories & Priorities
                        </h2>
                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5">
                            Configure alert urgency fleet-wide to reduce noise. Changes are saved to system settings.
                        </p>
                    </div>
                </div>
            </div>
            <button type="button"
                    @click="prioritiesModalOpen = false"
                    class="text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] p-1.5 rounded-lg hover:bg-[var(--color-surface-alt)] cursor-pointer transition-colors"
                    title="Close dialog">
                <i class="fa-solid fa-xmark text-sm"></i>
            </button>
        </div>

        {{-- Presets & Status Bar --}}
        <div class="px-6 py-2.5 bg-[var(--color-surface)] border-b border-[var(--color-border-light)] flex items-center justify-between gap-3 flex-wrap text-xs">
            <div class="flex items-center gap-2">
                <span class="font-medium text-[var(--color-ink-soft)]">Presets:</span>
                <button type="button"
                        @click="muteRoutineCategories()"
                        class="px-2.5 py-1 rounded-md border border-[var(--color-border-light)] hover:border-[var(--color-border-strong)] bg-[var(--color-surface-alt)] font-medium text-[var(--color-ink-strong)] cursor-pointer transition-colors"
                        title="Turn off outdated plugins, closed plugins, and flagged WP admins">
                    <i class="fa-solid fa-bell-slash text-[10px] text-amber-500 mr-1"></i>
                    Turn Off Routine Upkeep
                </button>
                <button type="button"
                        @click="resetCategoryLevels()"
                        class="px-2.5 py-1 rounded-md border border-[var(--color-border-light)] hover:border-[var(--color-border-strong)] text-[var(--color-ink-soft)] hover:text-[var(--color-ink-strong)] cursor-pointer transition-colors"
                        title="Reset all categories to default priority levels">
                    <i class="fa-solid fa-rotate-left text-[10px] mr-1"></i>
                    Reset to Defaults
                </button>
            </div>

            <div class="flex items-center gap-2">
                <span x-show="savingLevels" class="text-xs text-[var(--color-brand)] font-medium flex items-center gap-1.5" x-cloak>
                    <i class="fa-solid fa-circle-notch fa-spin text-xs"></i>
                    Saving…
                </span>
                <span x-show="!savingLevels && disabledCategoriesCount > 0" class="text-xs text-[var(--color-ink-muted)]" x-cloak>
                    <span x-text="disabledCategoriesCount" class="font-semibold text-amber-500 font-mono"></span> categories muted
                </span>
            </div>
        </div>

        {{-- Categories List --}}
        <div class="flex-1 overflow-y-auto px-6 py-4 space-y-6">
            @foreach (['critical' => ['title' => 'Critical & Security', 'color' => 'bg-[var(--color-status-red)]'], 'infrastructure' => ['title' => 'Infrastructure & Gaps', 'color' => 'bg-[var(--color-status-yellow)]'], 'routine' => ['title' => 'Routine Upkeep & Maintenance', 'color' => 'bg-slate-400']] as $tierKey => $tierInfo)
                <div>
                    <div class="flex items-center gap-2 pb-2 mb-3 border-b border-[var(--color-border-light)]">
                        <span class="w-2 h-2 rounded-full {{ $tierInfo['color'] }}"></span>
                        <h3 class="text-xs font-semibold uppercase tracking-wider text-[var(--color-ink-soft)]">
                            {{ $tierInfo['title'] }}
                        </h3>
                    </div>

                    <div class="divide-y divide-[var(--color-border-light)]">
                        @foreach ($categoryDefinitions as $catKey => $cat)
                            @if ($cat['tier'] === $tierKey)
                                <div class="py-3 flex items-center justify-between gap-4 flex-wrap sm:flex-nowrap">
                                    {{-- Category info --}}
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <i class="fa-solid {{ $cat['icon'] }} text-xs opacity-70 w-4 text-center"></i>
                                            <span class="font-medium text-sm text-[var(--color-ink-strong)]">{{ $cat['label'] }}</span>
                                            @if (($totals[$catKey] ?? 0) > 0)
                                                <span class="status-pill {{ $cat['class'] }} text-[10px] px-1.5 py-0.2">
                                                    {{ $totals[$catKey] }}
                                                </span>
                                            @else
                                                <span class="text-[10px] text-[var(--color-ink-soft)]">0</span>
                                            @endif
                                        </div>
                                        <p class="text-xs text-[var(--color-ink-muted)] mt-0.5 ml-6">
                                            {{ $cat['description'] }}
                                        </p>
                                    </div>

                                    {{-- 3-Way Segmented Control --}}
                                    <div class="flex items-center p-0.5 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)] text-xs shrink-0 select-none">
                                        {{-- Pressing --}}
                                        <button type="button"
                                                @click="setCategoryLevel('{{ $catKey }}', 'pressing')"
                                                :class="isCategoryPressing('{{ $catKey }}')
                                                    ? 'bg-red-500 text-white font-semibold shadow-xs'
                                                    : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                                                class="px-2.5 py-1 rounded-md transition-all flex items-center gap-1.5 cursor-pointer"
                                                title="Mark as Pressing / Urgent (triggers primary red badge)">
                                            <span class="w-1.5 h-1.5 rounded-full" :class="isCategoryPressing('{{ $catKey }}') ? 'bg-white' : 'bg-red-500'"></span>
                                            <span>Pressing</span>
                                        </button>

                                        {{-- Not Pressing --}}
                                        <button type="button"
                                                @click="setCategoryLevel('{{ $catKey }}', 'not_pressing')"
                                                :class="isCategoryNotPressing('{{ $catKey }}')
                                                    ? 'bg-amber-500 text-white font-semibold shadow-xs'
                                                    : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                                                class="px-2.5 py-1 rounded-md transition-all flex items-center gap-1.5 cursor-pointer"
                                                title="Mark as Not Pressing (monitored, but routine)">
                                            <span class="w-1.5 h-1.5 rounded-full" :class="isCategoryNotPressing('{{ $catKey }}') ? 'bg-white' : 'bg-amber-500'"></span>
                                            <span>Not Pressing</span>
                                        </button>

                                        {{-- Off --}}
                                        <button type="button"
                                                @click="setCategoryLevel('{{ $catKey }}', 'off')"
                                                :class="isCategoryOff('{{ $catKey }}')
                                                    ? 'bg-slate-600 text-white font-semibold shadow-xs'
                                                    : 'text-[var(--color-ink-muted)] hover:text-[var(--color-ink-strong)]'"
                                                class="px-2.5 py-1 rounded-md transition-all flex items-center gap-1.5 cursor-pointer"
                                                title="Turn completely off fleet-wide (muted)">
                                            <i class="fa-solid fa-bell-slash text-[10px]" :class="isCategoryOff('{{ $catKey }}') ? 'text-white' : 'opacity-60'"></i>
                                            <span>Off</span>
                                        </button>
                                    </div>
                                </div>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Modal Footer --}}
        <div class="px-6 py-3 border-t border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/50 flex items-center justify-between text-xs">
            <span class="text-[var(--color-ink-soft)]">
                <i class="fa-solid fa-circle-info mr-1 text-[var(--color-brand)]"></i>
                Pressing categories surface in red and power the navbar counter.
            </span>
            <button type="button"
                    @click="prioritiesModalOpen = false"
                    class="btn-pill-nav font-medium px-4 py-1.5 cursor-pointer">
                Done
            </button>
        </div>
    </div>
</div>
