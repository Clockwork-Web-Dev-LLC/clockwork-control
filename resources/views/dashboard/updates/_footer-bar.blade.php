{{-- Sticky footer bar — select all + count + bulk actions.
     Lives inside the parent <form id="updates-form">. The Update / Ignore
     buttons share the form via formaction so they post the same selection
     to different endpoints. --}}

<div class="sticky bottom-0 left-0 right-0 px-5 py-3 border-t border-[var(--color-border-light)] bg-[var(--color-surface)] flex items-center gap-3 z-10">
    <label class="flex items-center gap-2 cursor-pointer text-sm">
        <input type="checkbox" data-select-all class="rounded">
        <span class="text-[var(--color-ink-strong)]">Select all</span>
    </label>
    <span class="text-xs text-[var(--color-ink-soft)]">
        <span data-selection-count>None</span> selected
    </span>

    <div class="ml-auto flex items-center gap-2">
        {{-- Bulk Ignore is intentionally only available for a single-row selection.
             Ignoring fleet-wide is destructive — operators do that one plugin at a
             time via the per-group "Ignore everywhere" button, never as a swept
             selection. JS in _form-script hides this when N > 1. --}}
        <button type="submit" formaction="{{ route('updates.bulkIgnore') }}" data-bulk-action data-bulk-ignore
                class="px-4 py-1.5 rounded-md border border-[var(--color-border)] text-sm font-medium text-[var(--color-ink-strong)] bg-[var(--color-surface-alt)] hover:bg-[var(--color-border-light)] disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
            Ignore
        </button>
        @if (! empty($filters['show_ignored']))
            <button type="submit" formaction="{{ route('updates.bulkUnignore') }}" data-bulk-action data-bulk-ignore
                    class="px-4 py-1.5 rounded-md border border-[var(--color-border)] text-sm font-medium text-[var(--color-ink-strong)] bg-[var(--color-surface-alt)] hover:bg-[var(--color-border-light)] disabled:opacity-50 disabled:cursor-not-allowed transition-colors">
                Unignore
            </button>
        @endif
        <button type="submit" formaction="{{ route('updates.bulkUpdate', ['tab' => $activeTab]) }}" data-bulk-action
                class="px-4 py-1.5 rounded-md bg-[var(--color-primary-600)] text-white text-sm font-medium hover:opacity-90 disabled:opacity-50 disabled:cursor-not-allowed transition-opacity flex items-center gap-1.5">
            <i class="fa-solid fa-arrow-up-from-bracket text-xs"></i>
            <span data-update-label>Update</span>
        </button>
    </div>
</div>
