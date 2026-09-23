@props(['category'])

<div class="relative inline-flex items-center"
     :class="{ 'z-50': activePriorityMenu === '{{ $category }}' }"
     @click.stop
     @click.outside="activePriorityMenu === '{{ $category }}' ? activePriorityMenu = null : null">
    <button type="button"
            @click="activePriorityMenu = (activePriorityMenu === '{{ $category }}' ? null : '{{ $category }}')"
            class="px-2 py-0.5 rounded text-[11px] font-medium border flex items-center gap-1 transition-all cursor-pointer select-none"
            :class="{
                'bg-red-500/10 text-[var(--color-status-red)] border-red-500/20 hover:bg-red-500/20': isCategoryEmergency('{{ $category }}'),
                'bg-amber-500/10 text-[var(--color-status-yellow)] border-amber-500/20 hover:bg-amber-500/20': isCategoryPressing('{{ $category }}'),
                'bg-slate-500/10 text-slate-400 border-slate-500/20 hover:bg-slate-500/20': isCategoryNotPressing('{{ $category }}'),
                'bg-gray-500/10 text-gray-400 border-gray-500/20 hover:bg-gray-500/20 line-through opacity-70': isCategoryOff('{{ $category }}')
            }"
            title="Category Priority & Visibility: click to change priority or toggle on/off">
        <template x-if="!isCategoryOff('{{ $category }}')">
            <span class="w-1.5 h-1.5 rounded-full"
                  :class="{
                      'bg-[var(--color-status-red)]': isCategoryEmergency('{{ $category }}'),
                      'bg-[var(--color-status-yellow)]': isCategoryPressing('{{ $category }}'),
                      'bg-slate-400': isCategoryNotPressing('{{ $category }}')
                  }"></span>
        </template>
        <template x-if="isCategoryOff('{{ $category }}')">
            <i class="fa-solid fa-bell-slash text-[9px]"></i>
        </template>
        <span x-text="isCategoryOff('{{ $category }}') ? 'Off' : (isCategoryEmergency('{{ $category }}') ? 'Emergency' : (isCategoryPressing('{{ $category }}') ? 'Pressing' : 'Not Pressing'))"></span>
        <i class="fa-solid fa-chevron-down text-[9px] opacity-60"></i>
    </button>

    <div x-show="activePriorityMenu === '{{ $category }}'"
         x-transition.opacity.duration.100ms
         x-cloak
         class="absolute right-0 mt-1 top-full w-52 rounded-[var(--radius-card)] border border-[var(--color-border-light)] bg-[var(--color-surface)] shadow-2xl py-1 z-50 text-xs text-left">
        <div class="px-3 py-1 font-semibold text-[10px] uppercase tracking-wider text-[var(--color-ink-soft)] border-b border-[var(--color-border-light)] mb-1">
            Category Priority
        </div>

        {{-- Emergency --}}
        <button type="button"
                @click="setCategoryLevel('{{ $category }}', 'emergency')"
                class="w-full px-3 py-1.5 text-left flex items-center justify-between hover:bg-[var(--color-surface-alt)] cursor-pointer transition-colors"
                :class="isCategoryEmergency('{{ $category }}') ? 'text-[var(--color-status-red)] font-semibold bg-red-500/5' : 'text-[var(--color-ink-strong)]'">
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-[var(--color-status-red)]"></span>
                Emergency (Critical)
            </span>
            <i x-show="isCategoryEmergency('{{ $category }}')" class="fa-solid fa-check text-[10px]"></i>
        </button>

        {{-- Pressing --}}
        <button type="button"
                @click="setCategoryLevel('{{ $category }}', 'pressing')"
                class="w-full px-3 py-1.5 text-left flex items-center justify-between hover:bg-[var(--color-surface-alt)] cursor-pointer transition-colors"
                :class="isCategoryPressing('{{ $category }}') ? 'text-[var(--color-status-yellow)] font-semibold bg-amber-500/5' : 'text-[var(--color-ink-strong)]'">
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-[var(--color-status-yellow)]"></span>
                Pressing (Urgent)
            </span>
            <i x-show="isCategoryPressing('{{ $category }}')" class="fa-solid fa-check text-[10px]"></i>
        </button>

        {{-- Not Pressing --}}
        <button type="button"
                @click="setCategoryLevel('{{ $category }}', 'not_pressing')"
                class="w-full px-3 py-1.5 text-left flex items-center justify-between hover:bg-[var(--color-surface-alt)] cursor-pointer transition-colors"
                :class="isCategoryNotPressing('{{ $category }}') ? 'text-slate-400 font-semibold bg-slate-500/5' : 'text-[var(--color-ink-strong)]'">
            <span class="flex items-center gap-1.5">
                <span class="w-2 h-2 rounded-full bg-slate-400"></span>
                Not Pressing (Routine)
            </span>
            <i x-show="isCategoryNotPressing('{{ $category }}')" class="fa-solid fa-check text-[10px]"></i>
        </button>

        <div class="border-t border-[var(--color-border-light)] my-1"></div>

        {{-- Toggle Off / On Fleet-Wide --}}
        <button type="button"
                x-show="!isCategoryOff('{{ $category }}')"
                @click="setCategoryLevel('{{ $category }}', 'off')"
                class="w-full px-3 py-1.5 text-left flex items-center justify-between text-[var(--color-ink-muted)] hover:text-red-500 hover:bg-red-500/10 cursor-pointer transition-colors"
                title="Mute this category completely fleet-wide">
            <span class="flex items-center gap-1.5">
                <i class="fa-solid fa-bell-slash text-[10px]"></i>
                Turn Off Fleet-Wide
            </span>
        </button>

        <button type="button"
                x-show="isCategoryOff('{{ $category }}')"
                @click="restoreCategoryLevel('{{ $category }}')"
                class="w-full px-3 py-1.5 text-left flex items-center justify-between text-emerald-600 hover:bg-emerald-500/10 cursor-pointer transition-colors font-medium"
                title="Turn monitoring back on for this category">
            <span class="flex items-center gap-1.5">
                <i class="fa-solid fa-bell text-[10px]"></i>
                Turn Back On
            </span>
        </button>
    </div>
</div>
