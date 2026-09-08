<div class="card p-5 flex flex-col justify-between h-full"
     x-data="{
        notes: {{ json_encode($site->notes ?? '') }},
        originalNotes: {{ json_encode($site->notes ?? '') }},
        saving: false,
        savedStatus: null,
        async saveNote() {
            if (this.saving) return;
            this.saving = true;
            this.savedStatus = null;
            try {
                const response = await fetch('{{ route('sites.notes.update', $site) }}', {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': '{{ csrf_token() }}'
                    },
                    body: JSON.stringify({ notes: this.notes })
                });
                const data = await response.json();
                if (response.ok && data.ok) {
                    this.originalNotes = this.notes;
                    this.savedStatus = 'saved';
                    setTimeout(() => { if (this.savedStatus === 'saved') this.savedStatus = null; }, 3000);
                } else {
                    this.savedStatus = 'error';
                }
            } catch (err) {
                this.savedStatus = 'error';
            } finally {
                this.saving = false;
            }
        }
     }">
    <div>
        <div class="flex items-center justify-between mb-3">
            <h3 class="font-display font-semibold text-sm text-[var(--color-ink-strong)] flex items-center gap-2">
                <i class="fa-solid fa-note-sticky text-amber-500"></i>
                Site Notes
            </h3>
            <div class="flex items-center gap-1.5 text-xs">
                <template x-if="savedStatus === 'saved'">
                    <span class="text-emerald-600 font-medium text-[11px] flex items-center gap-1">
                        <i class="fa-solid fa-circle-check text-[10px]"></i> Saved
                    </span>
                </template>
                <template x-if="savedStatus === 'error'">
                    <span class="text-rose-600 font-medium text-[11px] flex items-center gap-1">
                        <i class="fa-solid fa-triangle-exclamation text-[10px]"></i> Save failed
                    </span>
                </template>
            </div>
        </div>

        <div class="relative">
            <textarea
                x-model="notes"
                placeholder="Add operator notes, staging URLs, DNS hosts, or client instructions for this site..."
                rows="4"
                class="w-full text-xs font-sans p-3 rounded-lg border border-[var(--color-border-light)] bg-[var(--color-surface-alt)]/40 focus:bg-[var(--color-surface)] focus:ring-1 focus:ring-[var(--color-brand)] focus:border-[var(--color-brand)] transition-colors outline-none resize-y min-h-[96px] text-[var(--color-ink-strong)] placeholder:text-[var(--color-ink-muted)]"></textarea>
        </div>
    </div>

    <div class="mt-3 pt-3 border-t border-[var(--color-border-light)] flex items-center justify-between">
        <span class="text-[10px] text-[var(--color-ink-muted)]">
            Private to operators
        </span>
        <button
            type="button"
            @click="saveNote()"
            :disabled="saving || notes === originalNotes"
            class="btn-pill-primary text-xs py-1.5 px-3 disabled:opacity-50 disabled:cursor-not-allowed">
            <i class="fa-solid" :class="saving ? 'fa-spinner fa-spin' : 'fa-check'"></i>
            <span x-text="saving ? 'Saving...' : 'Save Note'"></span>
        </button>
    </div>
</div>
