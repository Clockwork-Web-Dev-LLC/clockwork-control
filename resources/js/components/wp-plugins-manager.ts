/**
 * WordPress Plugins Fleet Manager Component
 *
 * Mounted on `/settings/wordpress-plugins` (`resources/views/settings/wordpress-plugins.blade.php`):
 *   <div x-data="wpPluginsManager({ csrf: '...' })">
 *
 * Manages fleet-wide WordPress plugin status verification and installations:
 * - Companion Installation: Pushes source via SSH or dispatches Pressable background jobs,
 *   with exponential-backoff polling on queued tasks.
 * - Plugin Re-probing: Executes remote SSH `wp plugin list` probes to refresh status flags.
 * - Live DOM Feedback: Injects dismissable result rows with sanitized HTML (`escapeHtml`),
 *   command output disclosure (<details>), and Gatekeeper pill rendering precedence.
 */
const escapeHtml = (s: string | number) =>
    String(s).replace(
        /[&<>"']/g,
        (c) =>
            ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;',
            })[c] || c,
    );

const STATUS_BG: Record<string, string> = {
    'status-green': 'bg-[var(--color-status-green-bg)] border-l-4 border-[var(--color-status-green)]',
    'status-yellow': 'bg-[var(--color-status-yellow-bg)] border-l-4 border-[var(--color-status-yellow)]',
    'status-red': 'bg-[var(--color-status-red-bg)] border-l-4 border-[var(--color-status-red)]',
};

const STATUS_TEXT: Record<string, string> = {
    'status-green': 'text-[var(--color-status-green)]',
    'status-yellow': 'text-[var(--color-status-yellow)]',
    'status-red': 'text-[var(--color-status-red)]',
};

function showRowResult(
    row: HTMLTableRowElement,
    cls: string,
    icon: string,
    html: string,
    details: string | null = null,
    autoDismiss = false,
) {
    const existing = row.nextElementSibling;
    if (existing?.classList.contains('clockwork-result-row')) {
        existing.remove();
    }

    const tr = document.createElement('tr');
    tr.className = `clockwork-result-row ${STATUS_BG[cls] || ''}`;

    const td = document.createElement('td');
    td.colSpan = 7;
    td.className = 'px-5 py-3';

    let body =
        `<div class="flex items-start gap-3">` +
        `<i class="fa-solid ${icon} ${STATUS_TEXT[cls] || ''} text-lg pt-0.5"></i>` +
        `<div class="flex-1 min-w-0 text-sm text-[var(--color-ink-strong)]">${html}`;
    if (details) {
        body +=
            `<details class="mt-2">` +
            `<summary class="cursor-pointer text-xs text-[var(--color-ink-muted)] hover:text-[var(--color-ink)]">show command output</summary>` +
            `<pre class="mt-2 text-[11px] font-data bg-[var(--color-surface)] border border-[var(--color-border-light)] p-2 rounded max-h-48 overflow-auto whitespace-pre-wrap">${escapeHtml(details)}</pre></details>`;
    }
    body +=
        `</div>` +
        `<button type="button" class="dismiss-result text-[var(--color-ink-soft)] hover:text-[var(--color-ink)] text-lg leading-none px-1 cursor-pointer" title="Dismiss">×</button>` +
        `</div>`;

    td.innerHTML = body;
    tr.appendChild(td);
    row.parentNode?.insertBefore(tr, row.nextSibling);

    tr.querySelector('.dismiss-result')?.addEventListener('click', () => tr.remove());
    tr.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    if (autoDismiss) {
        setTimeout(() => tr.remove(), 4000);
    }
}

function renderCompanionOutcome(
    row: HTMLTableRowElement,
    btn: HTMLButtonElement,
    domain: string,
    original: string,
    data: any,
) {
    if (data.ok) {
        const cls = data.result === 'installed' || data.result === 'updated' ? 'status-green' : 'status-yellow';
        const icon = data.result === 'installed' || data.result === 'updated' ? 'fa-circle-check' : 'fa-circle-info';
        showRowResult(
            row,
            cls,
            icon,
            `<strong>${escapeHtml(domain)}</strong> — ${escapeHtml(data.message)}`,
            data.output || null,
            cls === 'status-green',
        );

        if (['installed', 'updated', 'already-current'].includes(data.result)) {
            const compCell = row.querySelector('[data-col="companion"]');
            if (compCell) {
                compCell.innerHTML = `<span class="status-pill status-green text-xs inline-flex items-center gap-1.5"><i class="fa-solid fa-circle-check"></i> Installed${data.version ? ` v${escapeHtml(data.version)}` : ''}</span>`;
            }
            row.dataset.companion = '1';
            row.dataset.sortCompanion = '1';
            btn.remove();
        }
    } else {
        showRowResult(
            row,
            'status-red',
            'fa-circle-xmark',
            `<strong>${escapeHtml(domain)}</strong> — ${escapeHtml(data.message || 'Failed.')}`,
            data.output || null,
        );
        btn.disabled = false;
        btn.innerHTML = original;
    }
}

function pollCompanionStatus(
    row: HTMLTableRowElement,
    btn: HTMLButtonElement,
    domain: string,
    original: string,
    statusUrl: string,
    sinceIso: string,
) {
    let attempts = 0;
    const tick = async () => {
        attempts++;
        try {
            const r = await fetch(`${statusUrl}?since=${encodeURIComponent(sinceIso)}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await r.json();
            if (data.done) {
                renderCompanionOutcome(row, btn, domain, original, data);
                return;
            }
        } catch (_) {
            // Transient — keep polling until attempts run out.
        }
        if (attempts >= 90) {
            showRowResult(
                row,
                'status-yellow',
                'fa-circle-info',
                `<strong>${escapeHtml(domain)}</strong> — Still running on Pressable. Refresh this page in a bit to see the result.`,
            );
            btn.disabled = false;
            btn.innerHTML = original;
            return;
        }
        setTimeout(tick, 5000);
    };
    setTimeout(tick, 5000);
}

export function wpPluginsManager({ csrf = '' }: { csrf?: string } = {}) {
    return {
        csrfToken: csrf,

        init() {
            if (!this.csrfToken) {
                this.csrfToken =
                    (document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement)?.content ||
                    (document.querySelector('input[name="_token"]') as HTMLInputElement)?.value ||
                    '';
            }
            this.bindEvents();
        },

        bindEvents() {
            // Companion install buttons
            document.querySelectorAll<HTMLButtonElement>('.companion-install-btn').forEach((btn) => {
                btn.addEventListener('click', () => this.handleCompanionInstall(btn));
            });

            // Refresh / Probe buttons
            document.querySelectorAll<HTMLButtonElement>('.wp-refresh-btn').forEach((btn) => {
                btn.addEventListener('click', () => this.handlePluginRefresh(btn));
            });
        },

        async handleCompanionInstall(btn: HTMLButtonElement) {
            const domain = btn.dataset.siteDomain || btn.dataset.domain || 'this site';
            const ok = await window.confirmModal({
                title: 'Install Clockwork Companion?',
                message: `Install Clockwork Companion on ${domain}?`,
                details: 'Pushes the source, configures the per-site secret, and verifies /health.',
                confirmText: 'Install Companion',
                variant: 'primary',
            });
            if (!ok) return;

            const row = btn.closest('tr') as HTMLTableRowElement | null;
            if (!row) return;

            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Installing…';

            showRowResult(
                row,
                'status-yellow',
                'fa-spinner fa-spin',
                `<strong>${escapeHtml(domain)}</strong> — Pushing Companion source to site…`,
            );

            try {
                const r = await fetch(btn.dataset.url || '', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, Accept: 'application/json' },
                });
                const data = await r.json();
                if (data.result === 'queued') {
                    showRowResult(
                        row,
                        'status-yellow',
                        'fa-spinner fa-spin',
                        `<strong>${escapeHtml(domain)}</strong> — ${escapeHtml(data.message)}`,
                    );
                    pollCompanionStatus(row, btn, domain, original, btn.dataset.statusUrl || '', data.queued_at || '');
                    return;
                }
                renderCompanionOutcome(row, btn, domain, original, data);
            } catch (e: any) {
                showRowResult(
                    row,
                    'status-red',
                    'fa-circle-xmark',
                    `<strong>${escapeHtml(domain)}</strong> — Network error: ${escapeHtml(e?.message || 'Unknown error')}`,
                );
                btn.disabled = false;
                btn.innerHTML = original;
            }
        },

        async handlePluginRefresh(btn: HTMLButtonElement) {
            const domain = btn.dataset.siteDomain || btn.dataset.domain || 'this site';
            const row = btn.closest('tr') as HTMLTableRowElement | null;
            if (!row) return;

            const original = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

            showRowResult(
                row,
                'status-yellow',
                'fa-spinner fa-spin',
                `<strong>${escapeHtml(domain)}</strong> — Re-probing plugins over SSH…`,
            );

            try {
                const r = await fetch(btn.dataset.url || '', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, Accept: 'application/json' },
                });
                const data = await r.json();
                if (data.ok) {
                    const wfCell = row.querySelector('[data-col="wordfence"]');
                    if (wfCell) {
                        wfCell.innerHTML = data.wordfence_enabled
                            ? '<span class="status-pill status-green text-xs inline-flex items-center gap-1.5"><i class="fa-solid fa-shield-halved"></i> Active</span>'
                            : '<span class="text-[var(--color-ink-soft)] inline-flex items-center gap-1"><i class="fa-solid fa-minus text-[10px]"></i> Inactive</span>';
                    }
                    row.dataset.wordfence = data.wordfence_enabled ? '1' : '0';
                    row.dataset.sortWordfence = data.wordfence_enabled ? '1' : '0';

                    showRowResult(
                        row,
                        'status-green',
                        'fa-circle-check',
                        `<strong>${escapeHtml(domain)}</strong> — ${escapeHtml(data.message)}`,
                        null,
                        true,
                    );
                } else {
                    showRowResult(
                        row,
                        'status-red',
                        'fa-circle-xmark',
                        `<strong>${escapeHtml(domain)}</strong> — ${escapeHtml(data.message || 'Refresh failed.')}`,
                        data.output || null,
                    );
                }
            } catch (e: any) {
                showRowResult(
                    row,
                    'status-red',
                    'fa-circle-xmark',
                    `<strong>${escapeHtml(domain)}</strong> — Network error: ${escapeHtml(e?.message || 'Unknown error')}`,
                );
            } finally {
                btn.disabled = false;
                btn.innerHTML = original;
            }
        },
    };
}
