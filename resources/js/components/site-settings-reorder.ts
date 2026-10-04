/**
 * Site Settings Card Reordering Component
 *
 * Mounted on `/sites/{site}?tab=settings` (`resources/views/dashboard/site/tab-settings.blade.php`):
 *   <div x-data="siteSettingsCardManager(...)">
 *
 * Implements HTML5 drag-and-drop and arrow-based card arrangement for site settings:
 * - Live drag-over indicator and reordering.
 * - Accessible up/down arrow buttons for keyboard and mobile users.
 * - Live DOM arrangement preserving all existing child elements, forms, and event listeners.
 * - Asynchronous persistence to backend (`PATCH /sites/{site}/settings-layout`).
 * - Toast feedback and reset to default layout.
 */
export function siteSettingsCardManager(
    initialLayout: string[] = [],
    defaultLayout: string[] = [],
    updateUrl = '',
    csrfToken = '',
) {
    const defaultList =
        Array.isArray(defaultLayout) && defaultLayout.length > 0
            ? [...defaultLayout]
            : [
                  'cert',
                  'cloudflare',
                  'security',
                  'server_tools',
                  'care_plan',
                  'uptime',
                  'status',
                  'companion',
                  'forms',
                  'gatekeeper',
              ];

    const initialList =
        Array.isArray(initialLayout) && initialLayout.length > 0 ? [...initialLayout] : [...defaultList];

    return {
        order: [...initialList],
        defaultOrder: [...defaultList],
        draggedCard: null as string | null,
        dragOverCard: null as string | null,
        saving: false,
        savedToast: false,
        savedMessage: '',
        toastTimeout: null as any,

        get isCustom(): boolean {
            if (this.order.length !== this.defaultOrder.length) return true;
            return this.order.some((id: string, idx: number) => id !== this.defaultOrder[idx]);
        },

        init() {
            queueMicrotask(() => {
                this.reorderDom();
            });
        },

        reorderDom() {
            const grid = document.getElementById('site-settings-cards-grid');
            if (!grid) return;

            this.order.forEach((id: string) => {
                const el = grid.querySelector(`[data-card-id="${id}"]`);
                if (el) {
                    grid.appendChild(el);
                }
            });
        },

        moveCard(cardId: string, direction: number) {
            const currentIndex = this.order.indexOf(cardId);
            if (currentIndex === -1) return;

            const targetIndex = currentIndex + direction;
            if (targetIndex < 0 || targetIndex >= this.order.length) return;

            const newOrder = [...this.order];
            const temp = newOrder[currentIndex];
            newOrder[currentIndex] = newOrder[targetIndex];
            newOrder[targetIndex] = temp;

            this.order = newOrder;
            this.reorderDom();
            this.persistOrder();
        },

        onDragStart(event: DragEvent, cardId: string) {
            this.draggedCard = cardId;
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', cardId);
            }
        },

        onDragOver(event: DragEvent, targetCardId: string) {
            if (!this.draggedCard || this.draggedCard === targetCardId) {
                this.dragOverCard = null;
                return;
            }
            this.dragOverCard = targetCardId;
            if (event.dataTransfer) {
                event.dataTransfer.dropEffect = 'move';
            }
        },

        onDragLeave(event: DragEvent) {
            if (
                event.currentTarget &&
                !(event.currentTarget as HTMLElement).contains(event.relatedTarget as Node | null)
            ) {
                this.dragOverCard = null;
            }
        },

        onDrop(event: DragEvent, targetCardId: string) {
            this.dragOverCard = null;
            const sourceCardId =
                this.draggedCard || (event.dataTransfer ? event.dataTransfer.getData('text/plain') : null);
            this.draggedCard = null;

            if (!sourceCardId || sourceCardId === targetCardId) return;

            const fromIndex = this.order.indexOf(sourceCardId);
            const toIndex = this.order.indexOf(targetCardId);

            if (fromIndex === -1 || toIndex === -1) return;

            const newOrder = [...this.order];
            newOrder.splice(fromIndex, 1);
            newOrder.splice(toIndex, 0, sourceCardId);

            this.order = newOrder;
            this.reorderDom();
            this.persistOrder();
        },

        onDragEnd() {
            this.draggedCard = null;
            this.dragOverCard = null;
        },

        async resetOrder() {
            this.order = [...this.defaultOrder];
            this.reorderDom();
            await this.persistOrder(true);
        },

        async resetLayout() {
            await this.resetOrder();
        },

        async persistOrder(isReset = false) {
            this.saving = true;
            try {
                const response = await fetch(updateUrl, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({
                        layout: isReset ? null : this.order,
                    }),
                });

                if (response.ok) {
                    this.showToast(isReset ? 'Layout reset to default' : 'Layout saved');
                } else {
                    console.error('Failed to save settings layout');
                }
            } catch (err) {
                console.error('Error saving settings layout:', err);
            } finally {
                this.saving = false;
            }
        },

        showToast(msg: string) {
            this.savedMessage = msg;
            this.savedToast = true;
            if (this.toastTimeout) clearTimeout(this.toastTimeout);
            this.toastTimeout = setTimeout(() => {
                this.savedToast = false;
            }, 2500);
        },
    };
}

if (typeof window !== 'undefined') {
    (window as any).siteSettingsCardManager = siteSettingsCardManager;
}
