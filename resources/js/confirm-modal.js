/**
 * Clockwork Control — Professional Modal Confirmation & Prompt System
 *
 * Provides a unified, accessible, and theme-aware confirmation modal
 * across the entire application, replacing ugly browser-native confirm(),
 * prompt(), and alert() dialogs.
 *
 * Usage in HTML (declarative):
 *   <form method="POST" action="..."
 *         data-confirm="Remove site from monitoring?"
 *         data-confirm-details="This archives the site row and removes it from listings."
 *         data-confirm-btn="Remove Site"
 *         data-confirm-variant="danger">
 *       ...
 *   </form>
 *
 *   <button type="button"
 *           data-confirm="Reboot server now?"
 *           data-confirm-variant="warning"
 *           @click="...">Reboot</button>
 *
 *   <form method="POST" action="..."
 *         data-confirm="Permanently delete server?"
 *         data-confirm-details="This cascade-deletes all sites and data."
 *         data-confirm-match="server-name"
 *         data-confirm-variant="danger">
 *
 * Usage in JavaScript (programmatic):
 *   if (await window.confirmModal({
 *       title: 'Reboot Server',
 *       message: 'Reboot server-01 now?',
 *       details: 'The server will be offline for 30–90 seconds.',
 *       variant: 'warning',
 *       confirmText: 'Reboot Now'
 *   })) { ... }
 *
 *   const input = await window.promptModal({
 *       title: 'Enter Server Name',
 *       message: 'Type the server name to confirm deletion:',
 *       placeholder: 'server-name'
 *   });
 *
 *   await window.alertModal({
 *       title: 'Selection Required',
 *       message: 'Pick at least one plugin to update.',
 *       variant: 'warning'
 *   });
 */

export function initConfirmModalSystem(Alpine) {
    // Current active modal promise resolver
    let activeResolver = null;

    Alpine.data('confirmModal', () => ({
        open: false,
        title: 'Confirm Action',
        message: '',
        details: '',
        confirmText: 'Confirm',
        cancelText: 'Cancel',
        variant: 'danger', // 'danger' | 'warning' | 'primary' | 'info'
        icon: '',
        isPrompt: false,
        isAlert: false,
        inputValue: '',
        inputPlaceholder: '',
        requireMatch: '',
        loading: false,

        init() {
            window.addEventListener('cw-open-confirm-modal', (e) => {
                const opts = e.detail || {};
                this.title = opts.title || (opts.variant === 'danger' ? 'Confirm Deletion' : (opts.isAlert ? 'Notice' : 'Confirmation'));
                this.message = opts.message || '';
                this.details = opts.details || '';
                this.confirmText = opts.confirmText || (opts.isAlert ? 'OK' : (opts.variant === 'danger' ? 'Delete' : 'Confirm'));
                this.cancelText = opts.cancelText || 'Cancel';
                this.variant = opts.variant || 'danger';
                this.icon = opts.icon || '';
                this.isPrompt = Boolean(opts.isPrompt);
                this.isAlert = Boolean(opts.isAlert);
                this.inputValue = opts.defaultValue || '';
                this.inputPlaceholder = opts.placeholder || '';
                this.requireMatch = opts.requireMatch || '';
                this.loading = false;
                this.open = true;

                this.$nextTick(() => {
                    if (this.requireMatch) {
                        this.$refs.matchInput?.focus();
                        this.$refs.matchInput?.select();
                    } else if (this.isPrompt) {
                        this.$refs.promptInput?.focus();
                        this.$refs.promptInput?.select();
                    } else if (this.$refs.confirmBtn) {
                        this.$refs.confirmBtn.focus();
                    }
                });
            });
        },

        get iconClass() {
            if (this.icon) return this.icon;
            if (this.isAlert) return 'fa-solid fa-circle-info';
            switch (this.variant) {
                case 'danger':
                    return 'fa-solid fa-triangle-exclamation';
                case 'warning':
                    return 'fa-solid fa-circle-exclamation';
                case 'primary':
                case 'info':
                default:
                    return 'fa-solid fa-circle-question';
            }
        },

        get iconColorClass() {
            switch (this.variant) {
                case 'danger':
                    return 'bg-red-500/10 text-red-600 dark:text-red-400 ring-1 ring-red-500/20';
                case 'warning':
                    return 'bg-amber-500/10 text-amber-600 dark:text-amber-400 ring-1 ring-amber-500/20';
                case 'primary':
                case 'info':
                default:
                    return 'bg-[var(--color-brand)]/10 text-[var(--color-brand)] ring-1 ring-[var(--color-brand)]/20';
            }
        },

        get confirmBtnClass() {
            switch (this.variant) {
                case 'danger':
                    return 'bg-red-600 hover:bg-red-700 active:bg-red-800 text-white shadow-xs focus:ring-red-500';
                case 'warning':
                    return 'bg-amber-600 hover:bg-amber-700 active:bg-amber-800 text-white shadow-xs focus:ring-amber-500';
                case 'primary':
                case 'info':
                default:
                    return 'bg-[var(--color-brand)] hover:bg-[var(--color-brand-deep)] active:bg-[var(--color-brand-deep)] text-white shadow-xs focus:ring-[var(--color-brand)]';
            }
        },

        get canConfirm() {
            if (this.requireMatch) {
                return this.inputValue.trim() === this.requireMatch.trim();
            }
            if (this.isPrompt && this.inputPlaceholder && !this.inputValue.trim()) {
                return false;
            }
            return true;
        },

        handleConfirm() {
            if (!this.canConfirm) return;
            this.loading = true;
            const res = this.isPrompt ? this.inputValue : true;
            this.open = false;
            if (activeResolver) {
                activeResolver(res);
                activeResolver = null;
            }
        },

        handleCancel() {
            this.open = false;
            const res = this.isPrompt ? null : false;
            if (activeResolver) {
                activeResolver(res);
                activeResolver = null;
            }
        },

        handleKeydown(e) {
            if (e.key === 'Escape') {
                this.handleCancel();
            } else if (e.key === 'Enter' && !this.loading && this.canConfirm) {
                this.handleConfirm();
            }
        }
    }));

    // Programmatic APIs
    window.confirmModal = function (options) {
        if (typeof options === 'string') {
            options = parseConfirmString(options);
        }
        return new Promise((resolve) => {
            activeResolver = resolve;
            window.dispatchEvent(new CustomEvent('cw-open-confirm-modal', {
                detail: {
                    title: options.title || 'Confirm Action',
                    message: options.message || '',
                    details: options.details || '',
                    confirmText: options.confirmText || (options.variant === 'danger' ? 'Confirm' : 'Yes, proceed'),
                    cancelText: options.cancelText || 'Cancel',
                    variant: options.variant || detectVariant(options.message + ' ' + (options.title || '')),
                    icon: options.icon || '',
                    requireMatch: options.requireMatch || '',
                    isPrompt: false,
                    isAlert: false,
                }
            }));
        });
    };

    window.promptModal = function (options) {
        if (typeof options === 'string') {
            options = { message: options };
        }
        return new Promise((resolve) => {
            activeResolver = resolve;
            window.dispatchEvent(new CustomEvent('cw-open-confirm-modal', {
                detail: {
                    title: options.title || 'Input Required',
                    message: options.message || '',
                    details: options.details || '',
                    confirmText: options.confirmText || 'Submit',
                    cancelText: options.cancelText || 'Cancel',
                    variant: options.variant || 'primary',
                    icon: options.icon || 'fa-solid fa-pen-to-square',
                    isPrompt: true,
                    isAlert: false,
                    placeholder: options.placeholder || '',
                    defaultValue: options.defaultValue || '',
                    requireMatch: options.requireMatch || '',
                }
            }));
        });
    };

    window.alertModal = function (options) {
        if (typeof options === 'string') {
            options = { message: options };
        }
        return new Promise((resolve) => {
            activeResolver = resolve;
            window.dispatchEvent(new CustomEvent('cw-open-confirm-modal', {
                detail: {
                    title: options.title || 'Notice',
                    message: options.message || '',
                    details: options.details || '',
                    confirmText: options.buttonText || 'OK',
                    variant: options.variant || 'primary',
                    icon: options.icon || 'fa-solid fa-circle-info',
                    isPrompt: false,
                    isAlert: true,
                }
            }));
        });
    };

    // Helper to intelligently split message and details if string contains standard question formatting
    function parseConfirmString(str) {
        // e.g. "Remove example.com from monitoring? This archives the site row."
        const match = str.match(/^([^?]+\?)([\s\S]*)$/);
        if (match) {
            return {
                title: match[1].trim(),
                message: '',
                details: match[2].trim(),
                variant: detectVariant(str)
            };
        }
        return {
            title: 'Please Confirm',
            message: str,
            details: '',
            variant: detectVariant(str)
        };
    }

    function detectVariant(text) {
        const lower = text.toLowerCase();
        if (/delete|remove|destroy|revoke|prune|cancel scheduled|drop/i.test(lower)) {
            return 'danger';
        }
        if (/reboot|restart|reset|pause|ignore/i.test(lower)) {
            return 'warning';
        }
        return 'primary';
    }

    // Global Event Delegation for declarative data-confirm
    document.addEventListener('submit', async (e) => {
        const form = e.target;
        if (!form || !(form instanceof HTMLFormElement)) return;

        // If bypass flag is active, allow submission to proceed natively
        if (form._cwConfirmed) {
            delete form._cwConfirmed;
            return;
        }

        // Check form itself or active submit button
        const submitter = e.submitter;
        const confirmSource = (submitter && submitter.hasAttribute('data-confirm'))
            ? submitter
            : (form.hasAttribute('data-confirm') ? form : null);

        if (!confirmSource) return;

        e.preventDefault();
        e.stopImmediatePropagation();

        const rawConfirm = confirmSource.getAttribute('data-confirm');
        const details = confirmSource.getAttribute('data-confirm-details') || '';
        const btnText = confirmSource.getAttribute('data-confirm-btn') || '';
        const cancelText = confirmSource.getAttribute('data-confirm-cancel') || 'Cancel';
        const requireMatch = confirmSource.getAttribute('data-confirm-match') || '';
        const variant = confirmSource.getAttribute('data-confirm-variant') || detectVariant(rawConfirm + ' ' + details);

        let title = rawConfirm;
        let message = '';
        if (rawConfirm.includes('?')) {
            const parts = rawConfirm.split('?');
            title = parts[0] + '?';
            message = parts.slice(1).join('?').trim();
        }

        const confirmed = await window.confirmModal({
            title: title.trim(),
            message: message,
            details: details,
            confirmText: btnText || (variant === 'danger' ? 'Confirm' : 'Continue'),
            cancelText: cancelText,
            variant: variant,
            requireMatch: requireMatch,
        });

        if (confirmed) {
            form._cwConfirmed = true;
            if (requireMatch) {
                const matchInput = form.querySelector('input[name="confirm_name"]');
                if (matchInput) {
                    matchInput.value = requireMatch;
                }
            }
            if (submitter && submitter.name) {
                // Include submitter name/value if present
                const hidden = document.createElement('input');
                hidden.type = 'hidden';
                hidden.name = submitter.name;
                hidden.value = submitter.value;
                form.appendChild(hidden);
            }
            form.submit();
        }
    }, true);

    document.addEventListener('click', async (e) => {
        const target = e.target?.closest?.('a[data-confirm], button[data-confirm]');
        if (!target) return;

        // If button is inside a form with type="submit" or no type, the submit listener handles it
        if (target.tagName === 'BUTTON' && target.closest('form') && target.type === 'submit') {
            return;
        }

        e.preventDefault();
        e.stopImmediatePropagation();

        const rawConfirm = target.getAttribute('data-confirm');
        const details = target.getAttribute('data-confirm-details') || '';
        const btnText = target.getAttribute('data-confirm-btn') || '';
        const cancelText = target.getAttribute('data-confirm-cancel') || 'Cancel';
        const requireMatch = target.getAttribute('data-confirm-match') || '';
        const variant = target.getAttribute('data-confirm-variant') || detectVariant(rawConfirm + ' ' + details);

        let title = rawConfirm;
        let message = '';
        if (rawConfirm.includes('?')) {
            const parts = rawConfirm.split('?');
            title = parts[0] + '?';
            message = parts.slice(1).join('?').trim();
        }

        const confirmed = await window.confirmModal({
            title: title.trim(),
            message: message,
            details: details,
            confirmText: btnText || (variant === 'danger' ? 'Confirm' : 'Continue'),
            cancelText: cancelText,
            variant: variant,
            requireMatch: requireMatch,
        });

        if (confirmed) {
            if (target.tagName === 'A' && target.href) {
                window.location.href = target.href;
            } else {
                // Custom click callback execution if defined
                target.dispatchEvent(new CustomEvent('cw-confirmed'));
            }
        }
    }, true);

    // Safety fallback: route native window.alert calls to the modal
    window.alert = function (message) {
        return window.alertModal(message);
    };
}
