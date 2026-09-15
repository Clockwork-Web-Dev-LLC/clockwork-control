@props(['category'])

<div class="relative inline-flex items-center" @click.stop @click.outside="activePriorityMenu === '{{ $category }}' ? activePriorityMenu = null : null">
    <button type="button"
            @click="activePriorityMenu = (activePriorityMenu === '{{ $category }}' ? null : '{{ $category }}')"
            class="px-2 py-0.5 rounded text-[11px] font-medium border flex items-center gap-1 transition-all cursor-pointer select-none"
            :class="isCategoryPressing('{{ $category }}')
                ? 'bg-red-500/10 text-[var(--color-status-red)] border-red-500/20 hover:bg-red-500/20'
                : 'bg-amber-500/10 text-[var(--color-status-yellow)] border-amber-500/20 hover:bg-amber-500/20'"
            title="Priority: click to toggle pressing/not pressing or turn off fleet-wide">
        <span class="w-1.5 h-1.5 rounded-full" :class="isCategoryPressing('{{ $category }}') ? 'bg-[var(--color-status-red)]' : 'bg-[var(--color-status-yellow)]'"></span>
        <span x-text="isCategoryPressing('{{ $category }}') ? 'Pressing' : 'Not Pressing'"></span>
        <i class="fa-solid fa-chevron-down text-[9px] opacity-60"></i>
    </button>

    <div x-show="activePriorityMenu === '{{ $category }}'"
         x-transition.opacity.duration.100ms
         x-cloak
         class="absolute right-0 mt-1 top-full w-48 rounded-[var(--radius-card)] border border-[var(--color-border-light)] bg-[var(--color-surface)] shadow-xl py-1 z-30 text-xs text-left">
        <div class="px-3 py-1 font-semibold text-[10px] uppercase tracking-wider text-[var(--color-ink-soft)] border-b border-[var(--color-border-light)] mb-1">
            Category Priority
        </div>
        <button type="button"
                @click="setCategoryLevel('{{ $category }}', 'pressing')"
                class="w-full px-3 py-1.5 text-left flex items-center justify-between hover:bg-[var(--color-surface-alt)] cursor-pointer transition-colors"
                :class="isCategoryPressing('{{ $category }}') ? 'text-[var(--color-status-red)] font-semibold bg-red-500/5' : 'text-[var(--color-ink-strong)]'">
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-[var(--color-status-red)]"></span>
                Pressing (Urgent)
            </span>
            <i x-show="isCategoryPressing('{{ $category }}')" class="fa-solid fa-check text-[10px]"></i>
        </button>
        <button type="button"
                @click="setCategoryLevel('{{ $category }}', 'not_pressing')"
                class="w-full px-3 py-1.5 text-left flex items-center justify-between hover:bg-[var(--color-surface-alt)] cursor-pointer transition-colors"
                :class="isCategoryNotPressing('{{ $category }}') ? 'text-[var(--color-status-yellow)] font-semibold bg-amber-500/5' : 'text-[var(--color-ink-strong)]'">
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-[var(--color-status-yellow)]"></span>
                Not Pressing (Routine)
            </span>
            <i x-show="isCategoryNotPressing('{{ $category }}')" class="fa-solid fa-check text-[10px]"></i>
        </button>
        <div class="border-t border-[var(--color-border-light)] my-1"></div>
        <button type="button"
                @click="setCategoryLevel('{{ $category }}', 'off')"
                class="w-full px-3 py-1.5 text-left flex items-center justify-between text-[var(--color-ink-muted)] hover:text-red-500 hover:bg-red-500/10 cursor-pointer transition-colors"
                title="Mute this category completely fleet-wide">
            <span class="flex items-center gap-1.5">
                <i class="fa-solid fa-bell-slash text-[10px]"></i>
                Turn Off Fleet-Wide
            </span>
        </button>
    </div>
</div>
