export interface SiteDashboardReorderOptions {
    updateUrl: string;
    csrf: string;
    order?: string[];
    isCustom?: boolean;
}

export function siteDashboardReorder({ updateUrl, csrf, order = [], isCustom = false }: SiteDashboardReorderOptions) {
    return {
        order: [...order],
        previousOrder: [...order],
        isCustom,
        draggedWidget: null as string | null,
        dragOverWidget: null as string | null,
        saving: false,
        savedToast: false,
        errorToast: false,
        errorMessage: '',

        onDragStart(event: DragEvent, widget: string) {
            this.draggedWidget = widget;
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', widget);
            }
        },

        onDragEnd() {
            this.draggedWidget = null;
            this.dragOverWidget = null;
        },

        onDragOver(_event: DragEvent, widget: string) {
            if (this.draggedWidget && this.draggedWidget !== widget) {
                this.dragOverWidget = widget;
            }
        },

        onDragLeave() {
            // Handled dynamically
        },

        async onDrop(event: DragEvent, targetWidget: string) {
            const sourceWidget = this.draggedWidget || event.dataTransfer?.getData('text/plain');
            if (!sourceWidget || sourceWidget === targetWidget) {
                this.draggedWidget = null;
                this.dragOverWidget = null;
                return;
            }

            const grid = document.getElementById('site-dashboard-grid');
            if (!grid) return;

            const sourceEl = grid.querySelector(`[data-widget="${sourceWidget}"]`);
            const targetEl = grid.querySelector(`[data-widget="${targetWidget}"]`);
            if (!sourceEl || !targetEl) return;

            const fromIndex = this.order.indexOf(sourceWidget);
            const toIndex = this.order.indexOf(targetWidget);

            if (fromIndex !== -1 && toIndex !== -1) {
                this.previousOrder = [...this.order];
                this.order.splice(fromIndex, 1);
                this.order.splice(toIndex, 0, sourceWidget);

                if (fromIndex < toIndex) {
                    grid.insertBefore(sourceEl, targetEl.nextSibling);
                } else {
                    grid.insertBefore(sourceEl, targetEl);
                }

                this.isCustom = true;
                await this.saveLayout();
            }

            this.draggedWidget = null;
            this.dragOverWidget = null;
        },

        async saveLayout() {
            this.saving = true;
            this.savedToast = false;
            this.errorToast = false;
            try {
                const response = await fetch(updateUrl, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ layout: this.order }),
                });
                if (response.ok) {
                    this.previousOrder = [...this.order];
                    this.savedToast = true;
                    setTimeout(() => {
                        this.savedToast = false;
                    }, 3000);
                } else {
                    let msg = 'Failed to save layout';
                    try {
                        const data = await response.json();
                        if (data?.message) {
                            msg = data.message;
                        }
                    } catch (_) {}
                    this.showError(msg);
                    this.rollbackDom();
                }
            } catch (err) {
                console.error('Failed saving dashboard layout', err);
                this.showError('Network error saving layout');
                this.rollbackDom();
            } finally {
                this.saving = false;
            }
        },

        async resetLayout() {
            const ok = await window.confirmModal({
                title: 'Reset Dashboard Layout?',
                message: 'Reset dashboard cards to the default layout?',
                confirmText: 'Reset Layout',
                variant: 'warning',
            });
            if (!ok) return;
            this.saving = true;
            this.savedToast = false;
            this.errorToast = false;
            try {
                const response = await fetch(updateUrl, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ reset: true }),
                });
                if (response.ok) {
                    window.location.reload();
                } else {
                    let msg = 'Failed to reset layout';
                    try {
                        const data = await response.json();
                        if (data?.message) {
                            msg = data.message;
                        }
                    } catch (_) {}
                    this.showError(msg);
                }
            } catch (err) {
                console.error('Failed resetting dashboard layout', err);
                this.showError('Network error resetting layout');
            } finally {
                this.saving = false;
            }
        },

        showError(message: string) {
            this.errorMessage = message;
            this.errorToast = true;
            setTimeout(() => {
                this.errorToast = false;
            }, 5000);
        },

        rollbackDom() {
            const grid = document.getElementById('site-dashboard-grid');
            if (!grid) return;
            this.order = [...this.previousOrder];
            this.order.forEach((widgetKey) => {
                const el = grid.querySelector(`[data-widget="${widgetKey}"]`);
                if (el) {
                    grid.appendChild(el);
                }
            });
        },
    };
}
