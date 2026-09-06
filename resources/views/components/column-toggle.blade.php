@props([
    // Unique stable id for this table — keys the localStorage entry.
    'id',
    // Array of [['key' => 'foo', 'label' => 'Foo', 'default' => true|false], ...]
    // 'default' is whether the column is visible on first load (default: true).
    'columns',
])

@php
    // Compute the initial hidden-cols list server-side so the page renders with the
    // right columns missing immediately — no flicker on first paint. Alpine init()
    // then overlays any user-saved preferences from localStorage.
    $initialHidden = collect($columns)
        ->filter(fn ($c) => ($c['default'] ?? true) === false)
        ->pluck('key')
        ->implode(' ');
@endphp

{{-- Per-table CSS — one rule per managed column. Generic CSS can't iterate over
     attribute values, so we generate the rules with the column keys we know about. --}}
<style>
    @foreach ($columns as $col)
    [data-column-toggle="{{ $id }}"][data-hidden-cols~="{{ $col['key'] }}"] [data-col="{{ $col['key'] }}"] {
        display: none;
    }
    @endforeach
</style>

<div x-data="columnToggle({
        id: '{{ $id }}',
        columns: {{ \Illuminate\Support\Js::from($columns) }},
        initialHidden: '{{ $initialHidden }}'
    })"
    class="relative inline-block"
    x-cloak>
    <button type="button"
            @click="open = !open"
            class="btn-pill-nav text-xs"
            :class="open ? 'bg-[var(--color-surface-alt)]' : ''"
            title="Show or hide table columns. Saved per-browser.">
        <i class="fa-solid fa-table-columns"></i>
        Columns
    </button>

    <div x-show="open"
         @click.outside="open = false"
         x-transition.opacity.duration.100ms
         class="absolute right-0 mt-2 w-56 rounded-[var(--radius-card)] border border-[var(--color-border-light)] bg-[var(--color-surface)] shadow-lg py-2 z-30">
        <div class="px-3 py-1.5 text-[10px] uppercase tracking-wide text-[var(--color-ink-soft)]">Columns</div>
        <template x-for="col in columns" :key="col.key">
            <label class="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-[var(--color-surface-alt)]">
                <input type="checkbox"
                       :checked="visible[col.key]"
                       @change="toggle(col.key)"
                       class="cursor-pointer accent-[var(--color-primary-600)]" />
                <span x-text="col.label" class="text-[var(--color-ink-strong)]"></span>
            </label>
        </template>
        <button type="button"
                @click="reset()"
                class="w-full text-left px-3 py-1.5 text-xs text-[var(--color-ink-muted)] hover:bg-[var(--color-surface-alt)] border-t border-[var(--color-border-light)] mt-1">
            <i class="fa-solid fa-rotate-left mr-1"></i> Reset to defaults
        </button>
    </div>
</div>
