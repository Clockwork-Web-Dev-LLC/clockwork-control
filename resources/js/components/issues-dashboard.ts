/**
 * Issues Console Dashboard Component
 *
 * Mounted on `/issues` (`resources/views/dashboard/issues.blade.php`):
 *   <div x-data="issuesDashboard(...)">
 *
 * Manages fleet-wide issue tracking and classification:
 * - Tier filtering (all, core, security, health, operations) and priority filtering
 *   (emergency, pressing, not pressing).
 * - Real-time custom severity level overrides per issue category with AJAX persistence.
 * - Batch operations, inline issue resolution, and category management drawer.
 */
export interface IssuesDashboardOptions {
    initialTotals?: Record<string, number>;
    categories?: Record<string, { label?: string; tier?: string }>;
    initialLevels?: Record<string, string>;
    defaultLevels?: Record<string, string>;
    updateLevelUrl?: string;
    updateAllLevelsUrl?: string;
    resetLevelsUrl?: string;
    csrfToken?: string;
}

export function issuesDashboard({
    initialTotals = {},
    categories = {},
    initialLevels = {},
    defaultLevels = {},
    updateLevelUrl = '',
    updateAllLevelsUrl = '',
    resetLevelsUrl = '',
    csrfToken = '',
}: IssuesDashboardOptions = {}) {
    return {
        tierTab: 'all',
        priorityFilter: 'all', // 'all', 'emergency', 'pressing', 'not_pressing', 'off'
        categoriesOpen: false,
        prioritiesModalOpen: false,
        screenOptionsOpen: false,
        jumpMenuOpen: false,
        activePriorityMenu: null as string | null,
        savingLevels: false,
        feedbackToast: null as string | null,
        hiddenCategories: {} as Record<string, boolean>,
        collapsedSections: {} as Record<string, boolean>,
        totals: initialTotals,
        categoryMeta: categories,
        categoryLevels: { ...initialLevels },
        defaultLevels: { ...defaultLevels },
        updateLevelUrl,
        updateAllLevelsUrl,
        resetLevelsUrl,
        csrfToken,

        init() {
            try {
                const savedTab = localStorage.getItem('cw_issues_tier_tab');
                if (savedTab && ['all', 'critical', 'infrastructure', 'routine'].includes(savedTab)) {
                    this.tierTab = savedTab;
                }
            } catch (_) {}

            try {
                const savedPriority = localStorage.getItem('cw_issues_priority_filter');
                if (savedPriority && ['all', 'emergency', 'pressing', 'not_pressing', 'off'].includes(savedPriority)) {
                    this.priorityFilter = savedPriority;
                }
            } catch (_) {}

            try {
                const savedHidden = JSON.parse(localStorage.getItem('cw_issues_hidden_categories') || '{}');
                if (savedHidden && typeof savedHidden === 'object') {
                    this.hiddenCategories = savedHidden;
                }
            } catch (_) {}

            try {
                const savedCollapsed = JSON.parse(localStorage.getItem('cw_issues_collapsed_sections') || '{}');
                if (savedCollapsed && typeof savedCollapsed === 'object') {
                    this.collapsedSections = savedCollapsed;
                }
            } catch (_) {}

            this.initRecheckHandlers();
        },

        initRecheckHandlers() {
            const csrf =
                this.csrfToken || (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content || '';

            // 1. Uptime Re-check
            document.querySelectorAll('#section-down-sites .uptime-recheck-btn').forEach((btnEl) => {
                const btn = btnEl as HTMLButtonElement;
                btn.addEventListener('click', async () => {
                    const row = btn.closest('tr');
                    const original = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                    try {
                        const r = await fetch(btn.dataset.url || '', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                        });
                        const data = await r.json();
                        if (data.ok) {
                            if (data.state === 'up') {
                                if (row) {
                                    row.style.transition = 'opacity 400ms';
                                    row.style.opacity = '0';
                                    setTimeout(() => row.remove(), 450);
                                }
                            } else {
                                const failureCell = row?.querySelector('.cell-failure');
                                if (failureCell) {
                                    failureCell.innerHTML = data.status_code
                                        ? `HTTP ${data.status_code}`
                                        : '<span class="text-[var(--color-ink-muted)]">unreachable</span>';
                                }
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }
                        } else {
                            btn.innerHTML = `<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> ${data.message || 'Failed'}`;
                            setTimeout(() => {
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }, 4000);
                        }
                    } catch (_) {
                        btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
                        setTimeout(() => {
                            btn.innerHTML = original;
                            btn.disabled = false;
                        }, 4000);
                    }
                });
            });

            // 2. Server Health Re-check
            document.querySelectorAll('#section-health .health-recheck-btn').forEach((btnEl) => {
                const btn = btnEl as HTMLButtonElement;
                btn.addEventListener('click', async () => {
                    const row = btn.closest('tr');
                    const original = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                    try {
                        const r = await fetch(btn.dataset.url || '', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                        });
                        const data = await r.json();
                        if (data.ok) {
                            if (data.status === 'green' || data.status === 'yellow') {
                                if (row) {
                                    row.style.transition = 'opacity 400ms';
                                    row.style.opacity = '0';
                                    setTimeout(() => row.remove(), 450);
                                }
                            } else {
                                const polledCell = row?.querySelector('.cell-polled');
                                if (polledCell) polledCell.textContent = data.last_polled ?? 'just now';
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }
                        } else {
                            btn.innerHTML = `<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> ${data.message || 'Failed'}`;
                            setTimeout(() => {
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }, 4000);
                        }
                    } catch (_) {
                        btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
                        setTimeout(() => {
                            btn.innerHTML = original;
                            btn.disabled = false;
                        }, 4000);
                    }
                });
            });

            // 3. SSL Re-check
            document.querySelectorAll('#section-ssl .recheck-btn').forEach((btnEl) => {
                const btn = btnEl as HTMLButtonElement;
                btn.addEventListener('click', async () => {
                    const row = btn.closest('tr');
                    const original = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                    try {
                        const r = await fetch(btn.dataset.url || '', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                        });
                        const data = await r.json();
                        if (data.ok) {
                            if (data.state === 'green' || data.state === 'none') {
                                if (row) {
                                    row.style.transition = 'opacity 400ms';
                                    row.style.opacity = '0';
                                    setTimeout(() => row.remove(), 450);
                                }
                            } else {
                                const cls = data.state === 'red' ? 'status-red' : 'status-yellow';
                                const label = data.state === 'red' ? 'Expired' : 'Renewal needed';
                                const pill = document.createElement('span');
                                pill.className = `status-pill ${cls} text-[10px]`;
                                pill.textContent = label;
                                const stateCell = row?.querySelector('.cell-state');
                                if (stateCell) {
                                    stateCell.textContent = '';
                                    stateCell.appendChild(pill);
                                }
                                if (data.expires_at) {
                                    const expiresCell = row?.querySelector('.cell-expires');
                                    if (expiresCell)
                                        expiresCell.textContent = new Date(data.expires_at).toLocaleString();
                                }
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }
                        } else {
                            btn.innerHTML = `<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> ${data.message || 'Failed'}`;
                            setTimeout(() => {
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }, 4000);
                        }
                    } catch (_) {
                        btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
                        setTimeout(() => {
                            btn.innerHTML = original;
                            btn.disabled = false;
                        }, 4000);
                    }
                });
            });

            // 4. SEO Indexability Preflight
            document.querySelectorAll('#section-seo-indexability .preflight-btn').forEach((btnEl) => {
                const btn = btnEl as HTMLButtonElement;
                btn.addEventListener('click', async () => {
                    const row = btn.closest('tr');
                    const original = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Checking…';
                    try {
                        const r = await fetch(btn.dataset.url || '', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                        });
                        const data = await r.json();
                        if (data.ok) {
                            if (data.indexable) {
                                if (row) {
                                    row.style.transition = 'opacity 400ms';
                                    row.style.opacity = '0';
                                    setTimeout(() => row.remove(), 450);
                                }
                            } else {
                                const pillClass = data.is_staging ? 'status-gray' : 'status-red';
                                const pill = document.createElement('span');
                                pill.className = `status-pill ${pillClass} text-[10px]`;
                                pill.textContent = data.status_label;
                                const statusCell = row?.querySelector('.cell-status');
                                if (statusCell) {
                                    statusCell.textContent = '';
                                    statusCell.appendChild(pill);
                                }
                                if (data.reason) {
                                    const reasonCell = row?.querySelector('.cell-reason');
                                    if (reasonCell) reasonCell.textContent = data.reason;
                                }
                                if (data.snippet) {
                                    const codeEl = document.createElement('code');
                                    codeEl.className =
                                        'bg-[var(--color-surface-subtle)] px-1.5 py-0.5 rounded text-[11px]';
                                    codeEl.textContent = data.snippet.substring(0, 55);
                                    const snippetCell = row?.querySelector('.cell-snippet');
                                    if (snippetCell) {
                                        snippetCell.textContent = '';
                                        snippetCell.appendChild(codeEl);
                                    }
                                }
                                const checkedCell = row?.querySelector('.cell-checked');
                                if (checkedCell) checkedCell.textContent = 'just now';
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }
                        } else {
                            btn.innerHTML =
                                '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> Failed';
                            setTimeout(() => {
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }, 4000);
                        }
                    } catch (_) {
                        btn.innerHTML = '<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> Error';
                        setTimeout(() => {
                            btn.innerHTML = original;
                            btn.disabled = false;
                        }, 4000);
                    }
                });
            });

            // 5. Domain Expiration Re-check
            document.querySelectorAll('#section-domain-expiration .recheck-btn').forEach((btnEl) => {
                const btn = btnEl as HTMLButtonElement;
                btn.addEventListener('click', async () => {
                    const row = btn.closest('tr');
                    const original = btn.innerHTML;
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';
                    try {
                        const r = await fetch(btn.dataset.url || '', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                        });
                        const data = await r.json();
                        if (data.ok) {
                            if (data.state === 'green' || data.state === 'none') {
                                if (row) {
                                    row.style.transition = 'opacity 400ms';
                                    row.style.opacity = '0';
                                    setTimeout(() => row.remove(), 450);
                                }
                            } else {
                                const cls = data.state === 'red' ? 'status-red' : 'status-yellow';
                                const pill = document.createElement('span');
                                pill.className = `status-pill ${cls} text-[10px]`;
                                pill.textContent = data.state_label;
                                const stateCell = row?.querySelector('.cell-state');
                                if (stateCell) {
                                    stateCell.textContent = '';
                                    stateCell.appendChild(pill);
                                }
                                if (data.expires_formatted) {
                                    const expiresCell = row?.querySelector('.cell-expires');
                                    if (expiresCell) {
                                        expiresCell.textContent =
                                            data.expires_formatted +
                                            (data.days_remaining !== null ? ` (${data.days_remaining}d left)` : '');
                                    }
                                }
                                if (data.registrar) {
                                    const regCell = row?.querySelector('.cell-registrar');
                                    if (regCell) regCell.textContent = data.registrar;
                                }
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }
                        } else {
                            btn.innerHTML = `<i class="fa-solid fa-circle-xmark text-[var(--color-status-red)]"></i> Failed`;
                            setTimeout(() => {
                                btn.innerHTML = original;
                                btn.disabled = false;
                            }, 4000);
                        }
                    } catch (_) {
                        btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i>';
                        setTimeout(() => {
                            btn.innerHTML = original;
                            btn.disabled = false;
                        }, 4000);
                    }
                });
            });

            // 6. Reboot & Patches Handlers
            const resultBanner = document.getElementById('reboot-action-banner');
            const setBanner = (cls: string, html: string) => {
                if (!resultBanner) return;
                resultBanner.className = `card px-5 py-3 mb-4 text-sm ${cls}`;
                resultBanner.innerHTML = html;
                resultBanner.classList.remove('hidden');
            };
            const fadeOut = (row: HTMLElement | null) => {
                if (!row) return;
                row.style.transition = 'opacity 0.5s';
                row.style.opacity = '0';
                setTimeout(() => row.remove(), 500);
            };

            document.querySelectorAll('.reboot-recheck').forEach((btnEl) => {
                const btn = btnEl as HTMLButtonElement;
                btn.addEventListener('click', async () => {
                    const row = btn.closest('tr');
                    btn.disabled = true;
                    const original = btn.innerHTML;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Probing…';
                    setBanner('text-[var(--color-ink-muted)]', `Probing ${btn.dataset.serverName || ''}…`);
                    try {
                        const r = await fetch(btn.dataset.url || '', {
                            method: 'POST',
                            headers: { 'X-CSRF-TOKEN': csrf, Accept: 'application/json' },
                        });
                        const data = await r.json();
                        if (data.ok) {
                            if (data.reboot_required) {
                                setBanner(
                                    'text-[var(--color-status-yellow)]',
                                    `<i class="fa-solid fa-triangle-exclamation"></i> ${btn.dataset.serverName}: ${data.message}`,
                                );
                            } else {
                                setBanner(
                                    'text-[var(--color-status-green)]',
                                    `<i class="fa-solid fa-circle-check"></i> ${btn.dataset.serverName}: ${data.message} Removing from list.`,
                                );
                                fadeOut(row);
                            }
                        } else {
                            setBanner(
                                'text-[var(--color-status-red)]',
                                `<i class="fa-solid fa-circle-xmark"></i> ${btn.dataset.serverName}: ${data.message || 'Failed.'}`,
                            );
                        }
                    } catch (e: any) {
                        setBanner('text-[var(--color-status-red)]', `Network error: ${e?.message || 'unknown'}`);
                    } finally {
                        btn.disabled = false;
                        btn.innerHTML = original;
                    }
                });
            });

            const queuedMarkup =
                '<span class="text-[var(--color-primary-700)]"><i class="fa-solid fa-spinner"></i> queued</span>';
            const markQueued = (ids: (number | string)[]) => {
                ids.forEach((id) => {
                    const row = document.querySelector(`#patches-list tr[data-server-id="${id}"]`);
                    if (row) {
                        const cell = row.querySelector('.patch-action-cell');
                        if (cell) cell.innerHTML = queuedMarkup;
                    }
                });
                const allBtn = document.getElementById('patches-install-all') as HTMLButtonElement | null;
                if (allBtn) {
                    const remaining = (allBtn.dataset.serverIds || '')
                        .split(',')
                        .filter(Boolean)
                        .filter((id) => !ids.map(String).includes(String(id)));
                    allBtn.dataset.serverIds = remaining.join(',');
                    allBtn.innerHTML = `<i class="fa-solid fa-download"></i> Install updates on all ${remaining.length}`;
                    allBtn.disabled = remaining.length === 0;
                }
            };

            const queueUpdates = async (ids: number[], label: string, trigger: HTMLButtonElement) => {
                const rebootAtInput = document.getElementById('patches-reboot-at') as HTMLInputElement | null;
                const rebootAt = rebootAtInput?.value || '';
                const ok = await (window as any).confirmModal?.({
                    title: `Install updates on ${label}?`,
                    details: `Runs apt-get upgrade over SSH, one server per minute, then ${rebootAt ? `reboots at ${rebootAt} server-local` : 'reboots right away'} if the upgrade requires it. Sites stay up during the upgrade; each reboot takes ~30–90 seconds.`,
                    confirmText: 'Queue updates',
                    variant: 'warning',
                });
                if (!ok) return;

                trigger.disabled = true;
                const original = trigger.innerHTML;
                trigger.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Queueing…';
                setBanner('text-[var(--color-ink-muted)]', `Queueing updates on ${label}…`);
                try {
                    const body: Record<string, any> = { server_ids: ids, reboot_immediate: rebootAt === '' };
                    if (rebootAt) body.reboot_at = rebootAt;
                    const r = await fetch(trigger.dataset.url || '', {
                        method: 'POST',
                        headers: {
                            'X-CSRF-TOKEN': csrf,
                            Accept: 'application/json',
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify(body),
                    });
                    const data = await r.json();
                    if (data.ok) {
                        markQueued(data.queued_ids || ids);
                        const eta = Math.max(1, data.stats?.queued || ids.length);
                        setBanner(
                            'text-[var(--color-status-green)]',
                            `<i class="fa-solid fa-circle-check"></i> ${data.message} The processor runs one server per minute, so allow roughly ${eta}–${eta * 4} minutes. Progress: <a class="underline" href="/operations/server-updates">Operations → Server updates</a>.`,
                        );
                    } else {
                        setBanner(
                            'text-[var(--color-status-red)]',
                            `<i class="fa-solid fa-circle-xmark"></i> ${data.message || 'Nothing was queued.'}`,
                        );
                        trigger.disabled = false;
                        trigger.innerHTML = original;
                    }
                } catch (e: any) {
                    setBanner('text-[var(--color-status-red)]', `Network error: ${e?.message || 'unknown'}`);
                    trigger.disabled = false;
                    trigger.innerHTML = original;
                }
            };

            document.querySelectorAll('.patch-now').forEach((btnEl) => {
                const btn = btnEl as HTMLButtonElement;
                btn.addEventListener('click', () =>
                    queueUpdates([parseInt(btn.dataset.serverId || '0', 10)], btn.dataset.serverName || '', btn),
                );
            });

            const installAll = document.getElementById('patches-install-all') as HTMLButtonElement | null;
            if (installAll) {
                installAll.addEventListener('click', () => {
                    const ids = (installAll.dataset.serverIds || '')
                        .split(',')
                        .filter(Boolean)
                        .map((v) => parseInt(v, 10));
                    if (ids.length === 0) return;
                    queueUpdates(ids, `all ${ids.length} servers`, installAll);
                });
            }

            document.querySelectorAll('.reboot-now').forEach((btnEl) => {
                const btn = btnEl as HTMLButtonElement;
                btn.addEventListener('click', async () => {
                    const row = btn.closest('tr');
                    const name = btn.dataset.serverName || '';
                    const ok = await (window as any).confirmModal?.({
                        title: `Reboot ${name} now?`,
                        details: 'The box will go down momentarily and come back in ~30–90 seconds.',
                        confirmText: 'Reboot Server',
                        variant: 'warning',
                    });
                    if (!ok) return;

                    btn.disabled = true;
                    const original = btn.innerHTML;
                    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Issuing…';
                    setBanner('text-[var(--color-ink-muted)]', `Issuing reboot on ${name}…`);
                    try {
                        const r = await fetch(btn.dataset.url || '', {
                            method: 'POST',
                            headers: {
                                'X-CSRF-TOKEN': csrf,
                                Accept: 'application/json',
                                'Content-Type': 'application/json',
                            },
                            body: JSON.stringify({}),
                        });
                        const data = await r.json();
                        if (data.ok) {
                            setBanner(
                                'text-[var(--color-status-green)]',
                                `<i class="fa-solid fa-circle-check"></i> ${name}: ${data.message}`,
                            );
                            if (row?.closest('#patches-list')) {
                                const cell = row.querySelector('.patch-reboot-cell');
                                if (cell)
                                    cell.innerHTML =
                                        '<span class="text-[var(--color-ink-soft)]"><i class="fa-solid fa-circle-check"></i> rebooting…</span>';
                            } else {
                                fadeOut(row);
                            }
                        } else {
                            setBanner(
                                'text-[var(--color-status-red)]',
                                `<i class="fa-solid fa-circle-xmark"></i> ${name}: ${data.message || 'Reboot failed.'}`,
                            );
                        }
                    } catch (e: any) {
                        setBanner('text-[var(--color-status-red)]', `Network error: ${e?.message || 'unknown'}`);
                    } finally {
                        btn.disabled = false;
                        btn.innerHTML = original;
                    }
                });
            });
        },

        setTier(tier: string) {
            this.tierTab = tier;
            try {
                localStorage.setItem('cw_issues_tier_tab', tier);
            } catch (_) {}
        },

        setPriorityFilter(filter: string) {
            this.priorityFilter = filter;
            try {
                localStorage.setItem('cw_issues_priority_filter', filter);
            } catch (_) {}
        },

        getCategoryLevel(key: string) {
            return this.categoryLevels[key] || this.defaultLevels[key] || 'not_pressing';
        },

        isCategoryEnabled(key: string) {
            return this.getCategoryLevel(key) !== 'off';
        },

        isCategoryEmergency(key: string) {
            return this.getCategoryLevel(key) === 'emergency';
        },

        isCategoryPressing(key: string) {
            return this.getCategoryLevel(key) === 'pressing';
        },

        isCategoryNotPressing(key: string) {
            return this.getCategoryLevel(key) === 'not_pressing';
        },

        isCategoryOff(key: string) {
            return this.getCategoryLevel(key) === 'off';
        },

        isCategoryUrgent(key: string) {
            return this.isCategoryEmergency(key) || this.isCategoryPressing(key);
        },

        isCategoryVisible(key: string) {
            // If filter is explicitly 'off', show only muted categories
            if (this.priorityFilter === 'off') {
                return this.isCategoryOff(key) && !this.hiddenCategories[key];
            }

            // Categories turned completely off are muted fleet-wide
            if (!this.isCategoryEnabled(key)) {
                return false;
            }

            // Priority filter (all vs emergency only vs pressing only vs not pressing only)
            if (this.priorityFilter === 'emergency' && !this.isCategoryEmergency(key)) {
                return false;
            }
            if (this.priorityFilter === 'pressing' && !this.isCategoryPressing(key)) {
                return false;
            }
            if (this.priorityFilter === 'not_pressing' && !this.isCategoryNotPressing(key)) {
                return false;
            }

            // Local visibility toggle
            return !this.hiddenCategories[key];
        },

        async setCategoryLevel(category: string, level: string) {
            const prevLevel = this.categoryLevels[category];
            this.categoryLevels = { ...this.categoryLevels, [category]: level };
            this.activePriorityMenu = null;
            this.savingLevels = true;

            try {
                const res = await fetch(this.updateLevelUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify({ category, level }),
                });

                if (!res.ok) {
                    throw new Error(`Server returned HTTP ${res.status}`);
                }

                const data = await res.json();
                if (data.levels) {
                    this.categoryLevels = data.levels;
                }

                const label = this.categoryMeta[category]?.label || category;
                const levelNames: Record<string, string> = {
                    emergency: 'Emergency (Critical)',
                    pressing: 'Pressing (Urgent)',
                    not_pressing: 'Not Pressing (Routine)',
                    off: 'Turned Off',
                };
                this.showFeedback(`${label} set to ${levelNames[level] || level}`);
            } catch (err: any) {
                this.categoryLevels = { ...this.categoryLevels, [category]: prevLevel };
                await window.alertModal({
                    title: 'Save Failed',
                    message: `Failed to save category priority: ${err.message}`,
                    variant: 'danger',
                });
            } finally {
                this.savingLevels = false;
            }
        },

        restoreCategoryLevel(category: string) {
            const defaultLevel = this.defaultLevels[category] || 'pressing';
            this.setCategoryLevel(category, defaultLevel);
        },

        toggleCategoryTier(tierKey: string, enable: boolean) {
            const next = { ...this.categoryLevels };
            Object.entries(this.categoryMeta).forEach(([catKey, meta]) => {
                if (meta.tier === tierKey) {
                    if (enable) {
                        if (next[catKey] === 'off') {
                            next[catKey] = this.defaultLevels[catKey] || 'not_pressing';
                        }
                    } else {
                        next[catKey] = 'off';
                    }
                }
            });
            this.saveAllCategoryLevels(next);
        },

        turnAllOn() {
            const next = { ...this.categoryLevels };
            Object.keys(this.categoryMeta).forEach((catKey) => {
                if (next[catKey] === 'off') {
                    next[catKey] = this.defaultLevels[catKey] || 'not_pressing';
                }
            });
            this.saveAllCategoryLevels(next);
        },

        turnAllOff() {
            const next = { ...this.categoryLevels };
            Object.keys(this.categoryMeta).forEach((catKey) => {
                next[catKey] = 'off';
            });
            this.saveAllCategoryLevels(next);
        },

        async saveAllCategoryLevels(newLevels?: Record<string, string>) {
            const levelsToSave = newLevels ?? this.categoryLevels;
            this.savingLevels = true;
            try {
                const res = await fetch(this.updateAllLevelsUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                    body: JSON.stringify({ levels: levelsToSave }),
                });

                if (!res.ok) {
                    throw new Error(`Server returned HTTP ${res.status}`);
                }

                const data = await res.json();
                if (data.levels) {
                    this.categoryLevels = data.levels;
                }
                this.showFeedback('Category priorities updated.');
            } catch (err: any) {
                await window.alertModal({
                    title: 'Save Failed',
                    message: `Failed to save category priorities: ${err.message}`,
                    variant: 'danger',
                });
            } finally {
                this.savingLevels = false;
            }
        },

        async resetCategoryLevels() {
            const ok = await window.confirmModal({
                title: 'Reset Priorities?',
                message: 'Reset all alert category priorities to system defaults?',
                confirmText: 'Reset Defaults',
                variant: 'warning',
            });
            if (!ok) return;

            this.savingLevels = true;
            try {
                const res = await fetch(this.resetLevelsUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                    },
                });

                if (!res.ok) {
                    throw new Error(`Server returned HTTP ${res.status}`);
                }

                const data = await res.json();
                if (data.levels) {
                    this.categoryLevels = data.levels;
                }
                this.showFeedback('Priorities reset to system defaults.');
            } catch (err: any) {
                await window.alertModal({
                    title: 'Reset Failed',
                    message: `Failed to reset alert priorities: ${err.message}`,
                    variant: 'danger',
                });
            } finally {
                this.savingLevels = false;
            }
        },

        async muteRoutineCategories() {
            const routineKeys = ['plugins_outdated', 'plugins_closed', 'wp_admins'];
            const next = { ...this.categoryLevels };
            routineKeys.forEach((k) => {
                next[k] = 'off';
            });
            await this.saveAllCategoryLevels(next);
        },

        showFeedback(msg: string) {
            this.feedbackToast = msg;
            setTimeout(() => {
                if (this.feedbackToast === msg) {
                    this.feedbackToast = null;
                }
            }, 3500);
        },

        toggleCategory(key: string) {
            this.hiddenCategories = {
                ...this.hiddenCategories,
                [key]: !this.hiddenCategories[key],
            };
            this.persistHidden();
        },

        hideRoutine() {
            this.hiddenCategories = {
                ...this.hiddenCategories,
                plugins_outdated: true,
                plugins_closed: true,
                wp_admins: true,
            };
            this.persistHidden();
        },

        showOnlyCritical() {
            const next: Record<string, boolean> = {};
            Object.entries(this.categoryMeta).forEach(([k, meta]) => {
                if (meta.tier !== 'critical') {
                    next[k] = true;
                }
            });
            this.hiddenCategories = next;
            this.persistHidden();
        },

        showOnlyUrgent() {
            const next: Record<string, boolean> = {};
            Object.keys(this.categoryMeta).forEach((k) => {
                if (!this.isCategoryUrgent(k)) {
                    next[k] = true;
                }
            });
            this.hiddenCategories = next;
            this.persistHidden();
        },

        showAllCategories() {
            this.hiddenCategories = {};
            this.persistHidden();
        },

        jumpToCategory(htmlId: string) {
            const el = document.getElementById(htmlId);
            if (el) {
                el.scrollIntoView({ behavior: 'smooth', block: 'start' });
                el.classList.add('ring-2', 'ring-[var(--color-brand)]', 'transition-all');
                setTimeout(() => {
                    el.classList.remove('ring-2', 'ring-[var(--color-brand)]');
                }, 2000);
            }
        },

        resetCategories() {
            this.hiddenCategories = {};
            try {
                localStorage.removeItem('cw_issues_hidden_categories');
            } catch (_) {}
        },

        persistHidden() {
            try {
                localStorage.setItem('cw_issues_hidden_categories', JSON.stringify(this.hiddenCategories));
            } catch (_) {}
        },

        matchesTier(tier: string) {
            if (this.tierTab === 'all') return true;
            return this.tierTab === tier;
        },

        matchesTab(tier: string) {
            return this.matchesTier(tier);
        },

        isSectionCollapsed(key: string) {
            return !!this.collapsedSections[key];
        },

        toggleSection(key: string) {
            this.collapsedSections = {
                ...this.collapsedSections,
                [key]: !this.collapsedSections[key],
            };
            this.persistCollapsed();
        },

        get isAllCollapsed() {
            const activeKeys = Object.keys(this.categoryMeta).filter(
                (k) => (this.totals[k] ?? 0) > 0 && this.isCategoryVisible(k),
            );
            if (activeKeys.length === 0) return false;
            return activeKeys.every((k) => this.collapsedSections[k]);
        },

        toggleCollapseAll() {
            const shouldCollapse = !this.isAllCollapsed;
            const next = { ...this.collapsedSections };
            Object.keys(this.categoryMeta).forEach((k) => {
                next[k] = shouldCollapse;
            });
            this.collapsedSections = next;
            this.persistCollapsed();
        },

        persistCollapsed() {
            try {
                localStorage.setItem('cw_issues_collapsed_sections', JSON.stringify(this.collapsedSections));
            } catch (_) {}
        },

        get disabledCategories() {
            return Object.keys(this.categoryMeta).filter((k) => this.isCategoryOff(k));
        },

        get disabledCategoriesCount() {
            return this.disabledCategories.length;
        },

        get disabledItemsCount() {
            return this.disabledCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get emergencyCategories() {
            return Object.keys(this.categoryMeta).filter((k) => this.isCategoryEmergency(k));
        },

        get emergencyItemsCount() {
            return this.emergencyCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get pressingCategories() {
            return Object.keys(this.categoryMeta).filter((k) => this.isCategoryPressing(k));
        },

        get pressingItemsCount() {
            return this.pressingCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get notPressingCategories() {
            return Object.keys(this.categoryMeta).filter((k) => this.isCategoryNotPressing(k));
        },

        get notPressingItemsCount() {
            return this.notPressingCategories.reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get activeItemsCount() {
            return Object.keys(this.categoryMeta)
                .filter((k) => this.isCategoryEnabled(k))
                .reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get hiddenCategoriesCount() {
            return Object.keys(this.hiddenCategories).filter(
                (k) => this.hiddenCategories[k] && this.isCategoryEnabled(k) && (this.totals[k] ?? 0) > 0,
            ).length;
        },

        get hiddenItemsCount() {
            return Object.keys(this.hiddenCategories)
                .filter((k) => this.hiddenCategories[k] && this.isCategoryEnabled(k))
                .reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        get visibleItemsCount() {
            return Object.keys(this.categoryMeta)
                .filter((k) => {
                    if (!this.isCategoryVisible(k)) return false;
                    if (this.tierTab !== 'all' && this.categoryMeta[k]?.tier !== this.tierTab) return false;
                    return true;
                })
                .reduce((sum, k) => sum + (this.totals[k] ?? 0), 0);
        },

        hasVisibleCategoryInTier(tier: string) {
            return Object.entries(this.categoryMeta).some(([k, meta]) => {
                return meta.tier === tier && (this.totals[k] ?? 0) > 0 && this.isCategoryVisible(k);
            });
        },
    };
}
