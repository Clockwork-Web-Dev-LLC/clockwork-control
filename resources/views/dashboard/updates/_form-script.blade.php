{{-- Form behavior: keep the "N selected" counter accurate, group-toggle
     checkboxes parent-and-children, master Select All, per-row + per-group
     instant Update buttons. Vanilla JS, no Alpine — keeps the page fast
     and the source readable. --}}
<script>
    (function () {
        const form = document.getElementById('updates-form');
        if (! form) return;

        const counter = form.querySelector('[data-selection-count]');
        const selectAll = form.querySelector('[data-select-all]');
        const bulkButtons = form.querySelectorAll('[data-bulk-action]');
        const bulkIgnoreButtons = form.querySelectorAll('[data-bulk-ignore]');
        const groupToggles = form.querySelectorAll('[data-group-toggle]');
        const updateBtnLabel = form.querySelector('[data-update-label]');
        const targetCheckboxes = () => form.querySelectorAll('input[name="targets[]"]:not(:disabled)');

        const selectedCount = () => form.querySelectorAll('input[name="targets[]"]:checked').length;

        function syncCounter() {
            const n = selectedCount();
            const totalAvailable = targetCheckboxes().length;
            if (counter) counter.textContent = n === 0 ? 'None' : n;
            bulkButtons.forEach(b => { b.disabled = n === 0; });
            // Bulk ignore is only available for a single-row selection. > 1 hides it.
            bulkIgnoreButtons.forEach(b => { b.hidden = n > 1; });
            if (updateBtnLabel) {
                if (n === 0) {
                    updateBtnLabel.textContent = 'Update';
                } else if (n === 1) {
                    updateBtnLabel.textContent = 'Update 1 selected';
                } else if (n === totalAvailable && totalAvailable > 1) {
                    updateBtnLabel.textContent = `Update all ${n}`;
                } else {
                    updateBtnLabel.textContent = `Update ${n} selected`;
                }
            }
            // Master select-all reflects: all-checked, none-checked, or indeterminate
            if (selectAll) {
                const all = targetCheckboxes();
                const checkedCount = Array.from(all).filter(c => c.checked).length;
                if (checkedCount === 0) {
                    selectAll.checked = false;
                    selectAll.indeterminate = false;
                } else if (checkedCount === all.length) {
                    selectAll.checked = true;
                    selectAll.indeterminate = false;
                } else {
                    selectAll.checked = false;
                    selectAll.indeterminate = true;
                }
            }
            // Group toggles: each reflects its child rows
            groupToggles.forEach(t => {
                const slug = t.dataset.groupToggle;
                const children = form.querySelectorAll(`input[name="targets[]"][data-group-row="${CSS.escape(slug)}"]:not(:disabled)`);
                const checked = Array.from(children).filter(c => c.checked).length;
                if (checked === 0) {
                    t.checked = false;
                    t.indeterminate = false;
                } else if (checked === children.length) {
                    t.checked = true;
                    t.indeterminate = false;
                } else {
                    t.checked = false;
                    t.indeterminate = true;
                }
            });
        }

        // Master select-all
        if (selectAll) {
            selectAll.addEventListener('change', () => {
                const want = selectAll.checked;
                targetCheckboxes().forEach(c => { c.checked = want; });
                syncCounter();
            });
        }

        // Group-level toggle: ticking propagates to all child rows
        groupToggles.forEach(t => {
            t.addEventListener('change', () => {
                const slug = t.dataset.groupToggle;
                const children = form.querySelectorAll(`input[name="targets[]"][data-group-row="${CSS.escape(slug)}"]:not(:disabled)`);
                children.forEach(c => { c.checked = t.checked; });
                syncCounter();
            });
        });

        // Any row checkbox change re-syncs everything
        form.addEventListener('change', e => {
            if (e.target.matches('input[name="targets[]"]')) syncCounter();
        });

        // Submits the current checkbox selection through the bulk-update action.
        function submitBulkUpdate() {
            const updateBtn = Array.from(bulkButtons).find(b => b.formAction.includes('bulk-update'));
            if (updateBtn) updateBtn.click();
        }

        // Per-group "Update [all N]": ticks just this group's children, submits.
        // Same shape as the "Ignore everywhere" handler so behaviour stays consistent.
        form.querySelectorAll('[data-update-group]').forEach(btn => {
            btn.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                const slug = btn.dataset.updateGroup;
                const children = form.querySelectorAll(`input[name="targets[]"][data-group-row="${CSS.escape(slug)}"]:not(:disabled)`);
                if (children.length === 0) return;
                form.querySelectorAll('input[name="targets[]"]:checked').forEach(c => c.checked = false);
                children.forEach(c => c.checked = true);
                syncCounter();
                submitBulkUpdate();
            });
        });

        // Per-row "Update": ticks just this single target, submits.
        form.querySelectorAll('[data-update-row]').forEach(btn => {
            btn.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                const target = btn.dataset.updateRow;
                const cb = form.querySelector(`input[name="targets[]"][value="${CSS.escape(target)}"]:not(:disabled)`);
                if (! cb) return;
                form.querySelectorAll('input[name="targets[]"]:checked').forEach(c => c.checked = false);
                cb.checked = true;
                syncCounter();
                submitBulkUpdate();
            });
        });

        // "Ignore everywhere" on a parent group: untick everything else,
        // tick this group's children, submit to bulk-ignore endpoint. The
        // confirm() guards against the not-uncommon misclick where the
        // operator meant to expand but caught the button instead.
        form.querySelectorAll('[data-ignore-group]').forEach(btn => {
            btn.addEventListener('click', e => {
                e.preventDefault();
                e.stopPropagation();
                const slug = btn.dataset.ignoreGroup;
                const children = form.querySelectorAll(`input[name="targets[]"][data-group-row="${CSS.escape(slug)}"]:not(:disabled)`);
                if (children.length === 0) return;
                if (! confirm(`Ignore this on all ${children.length} site(s)? It'll stop showing in pending lists until you unignore.`)) return;

                form.querySelectorAll('input[name="targets[]"]:checked').forEach(c => c.checked = false);
                children.forEach(c => c.checked = true);
                syncCounter();

                const ignoreBtn = Array.from(bulkButtons).find(b => b.formAction.includes('bulk-ignore'));
                if (ignoreBtn) ignoreBtn.click();
            });
        });

        syncCounter();
    })();

    // Sortable plugin/theme list ------------------------------------------------
    (function () {
        const list = document.getElementById('sort-list');
        if (! list) return;

        // Default: alphabetical by name.
        let sortKey = 'name';
        let sortDir = 'asc'; // 'asc' | 'desc'

        const icons = {
            neutral: 'fa-sort',
            asc:     'fa-sort-up',
            desc:    'fa-sort-down',
        };

        function updateIcons(activeKey, dir) {
            document.querySelectorAll('[data-sort-icon]').forEach(el => {
                const key = el.dataset.sortIcon;
                el.className = 'fa-solid ' + (key === activeKey ? icons[dir] : icons.neutral);
            });
        }

        function sortList(key, dir) {
            const rows = Array.from(list.querySelectorAll(':scope > details'));
            rows.sort((a, b) => {
                let av = a.dataset['sort' + key.charAt(0).toUpperCase() + key.slice(1)];
                let bv = b.dataset['sort' + key.charAt(0).toUpperCase() + key.slice(1)];
                if (key === 'count') { av = parseInt(av, 10); bv = parseInt(bv, 10); }
                const cmp = av < bv ? -1 : av > bv ? 1 : 0;
                return dir === 'asc' ? cmp : -cmp;
            });
            rows.forEach(r => list.appendChild(r));
            updateIcons(key, dir);
        }

        document.querySelectorAll('[data-sort-by]').forEach(btn => {
            btn.addEventListener('click', () => {
                const key = btn.dataset.sortBy;
                if (key === sortKey) {
                    sortDir = sortDir === 'desc' ? 'asc' : 'desc';
                } else {
                    sortKey = key;
                    // Default direction: count→desc (most first), name→asc (A-Z).
                    sortDir = key === 'count' ? 'desc' : 'asc';
                }
                sortList(sortKey, sortDir);
            });
        });

        // Apply default sort on load (server renders count-desc; re-sort to name-asc).
        sortList(sortKey, sortDir);
    })();
</script>
