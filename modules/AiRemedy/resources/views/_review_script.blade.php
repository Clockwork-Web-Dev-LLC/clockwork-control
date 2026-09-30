@once
<script>
/**
 * Copilot approval panel state: lets an admin pick which proposed commands to
 * run (some, all, or none), optionally edit them, and execute the selection.
 * The backend re-checks everything (policy, safety guard, status); this only
 * mirrors those rules so the UI never offers what the server would refuse.
 */
function aiRemedyReview(review, runId) {
    return {
        runId: runId,
        review: review || { commands: [], executable: false, can_execute: false },
        items: ((review && review.commands) || []).map((c) => ({
            ...c,
            original: c.command,
            // Tier 1 starts ticked; Tier 2 needs a deliberate click; Tier 3 can't be ticked.
            selected: c.allowed && c.tier === 'tier_1_safe',
        })),
        executing: false,
        done: false,
        ok: false,
        message: null,
        output: null,
        results: [],

        get selectedItems() {
            return this.items.filter((i) => i.selected);
        },

        get canRun() {
            return !!this.review.can_execute
                && !this.done
                && !this.executing
                && this.selectedItems.length > 0
                && this.selectedItems.every((i) => i.command.trim() !== '');
        },

        isEdited(item) {
            return item.command.trim() !== item.original.trim();
        },

        tierLabel(tier) {
            switch (tier) {
                case 'tier_1_safe': return 'Tier 1 · Safe';
                case 'tier_2_cautious': return 'Tier 2 · Cautious';
                case 'tier_3_prohibited': return 'Tier 3 · Blocked';
                default: return 'Unknown';
            }
        },

        tierClass(tier) {
            switch (tier) {
                case 'tier_1_safe': return 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400 border-emerald-500/30';
                case 'tier_2_cautious': return 'bg-amber-500/15 text-amber-600 dark:text-amber-400 border-amber-500/30';
                default: return 'bg-rose-500/15 text-rose-600 dark:text-rose-400 border-rose-500/30';
            }
        },

        // Swap in Clockwork's corrected command (real site path/user). The server
        // re-checks it on run like any other edit.
        useSuggestion(item) {
            if (!item.suggestion) return;
            item.command = item.suggestion;
            item.allowed = true;
            item.reason = null;
            item.suggestion = null;
            item.selected = item.tier === 'tier_1_safe';
        },

        resultFor(item) {
            return this.results.find((r) => r.command === item.command.trim()) || null;
        },

        needsSudo() {
            return !!this.output && (this.output.includes('sudo: a password is required') || this.output.includes('terminal is required to read the password'));
        },

        async run() {
            if (!this.canRun) return;

            const cautious = this.selectedItems.filter((i) => i.tier === 'tier_2_cautious').length;
            if (cautious > 0 && !window.confirm(`${cautious} selected command(s) are Tier 2 (cautious) and may briefly disrupt traffic. Run them now?`)) {
                return;
            }

            this.executing = true;
            this.message = null;

            try {
                const res = await fetch(`/ai-remedy/runs/${this.runId}/execute`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=\'csrf-token\']')?.getAttribute('content') || '',
                    },
                    body: JSON.stringify({
                        selected: this.selectedItems.map((i) => ({ index: i.index, command: i.command.trim() })),
                    }),
                });

                const data = await res.json().catch(() => ({}));
                this.ok = !!data.ok;
                this.message = data.message || (res.ok ? 'Done.' : 'Execution failed.');
                this.output = data.output || null;
                this.results = data.results || [];
                // Once anything ran (or the run is no longer executable), there is no retry from here.
                this.done = this.ok || res.status === 409 || this.results.length > 0;

                window.dispatchEvent(new CustomEvent('ai-remedy-executed', {
                    detail: { runId: this.runId, ok: this.ok, run: data.run || null },
                }));
            } catch (e) {
                this.message = 'Request failed: ' + e.message;
            } finally {
                this.executing = false;
            }
        },
    };
}
</script>
@endonce
