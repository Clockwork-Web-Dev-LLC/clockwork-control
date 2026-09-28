/**
 * In-App Visual Feedback & Collaboration Overlay Component
 *
 * Mounted globally when the Feedback module is enabled:
 *   <div id="cw-feedback-overlay-root" x-data="feedbackOverlay()">
 *
 * Features:
 * - Intercepts right-clicks across the page to show an in-app "Leave Feedback" context menu
 *   (preserving native browser context menu via Shift + Right-Click).
 * - Auto-captures clicked element's CSS selector, HTML tag, text snippet, and screen coordinates.
 * - Interactive numbered visual pins (①, ②, ③) placed directly on the live page over target elements.
 * - Atarim-style threaded discussion popover for real-time back-and-forth collaboration.
 * - One-click "Copy Claude Prompt" containing the entire conversation transcript and code context.
 * - Discreet floating toggle pill in the bottom corner with Show/Hide pins switch.
 */

declare global {
    interface Window {
        __CW_FEEDBACK_SERVER_CONTEXT__?: {
            url?: string;
            path?: string;
            routeName?: string;
            controllerAction?: string;
            viewName?: string;
            viewPath?: string;
        };
    }
}

export interface FeedbackPinAuthor {
    name: string;
    email: string;
    avatar: string;
}

export interface FeedbackPinComment {
    id: number;
    content: string;
    created_at: string;
    author: FeedbackPinAuthor;
}

export interface FeedbackPinData {
    id: number;
    number: number;
    title: string;
    content: string;
    type: 'bug' | 'tweak' | 'feature' | 'copy';
    type_label: string;
    type_class: string;
    status: 'open' | 'approved' | 'in_progress' | 'resolved' | 'dismissed';
    status_class: string;
    path?: string;
    route_name?: string | null;
    view_name?: string | null;
    selector: string | null;
    element_tag: string | null;
    element_text: string | null;
    x_pos: number;
    y_pos: number;
    created_at: string;
    author: FeedbackPinAuthor;
    comments_count: number;
    comments: FeedbackPinComment[];
    claude_prompt: string;
    metadata?: Record<string, any>;
    // Computed screen coordinates
    screenX?: number;
    screenY?: number;
}

export function feedbackOverlay() {
    let initialPinsVisible = true;
    try {
        if (typeof localStorage !== 'undefined') {
            const saved = localStorage.getItem('cw_feedback_pins');
            if (saved !== null) {
                initialPinsVisible = saved === 'true';
            }
        }
    } catch (_) {}

    return {
        pinsVisible: initialPinsVisible,
        minimized: false,
        pins: [] as FeedbackPinData[],
        activePin: null as FeedbackPinData | null,
        replyContent: '',
        replySubmitting: false,

        // Context menu state with rich page, container, and AI code context
        contextMenu: {
            visible: false,
            x: 0,
            y: 0,
            targetEl: null as HTMLElement | null,
            selector: '',
            tag: '',
            text: '',
            pagePath: '',
            pageUrl: '',
            pageTitle: '',
            routeName: '',
            controllerAction: '',
            viewName: '',
            viewPath: '',
            nearestHeading: '',
            containerSummary: '',
            hierarchy: '',
        },

        // New Feedback modal state
        newModalOpen: false,
        newForm: {
            type: 'bug' as 'bug' | 'tweak' | 'feature' | 'copy',
            title: '',
            content: '',
            submitting: false,
        },

        toast: {
            visible: false,
            message: '',
        },

        init() {
            this.fetchPins();
            this.setupRightClickListener();
            this.setupRepositionOnScrollResize();

            // Check if URL has ?feedback_pin=ID to jump to and highlight a specific pin
            try {
                const params = new URLSearchParams(window.location.search);
                const targetPinId = params.get('feedback_pin');
                if (targetPinId) {
                    const id = Number.parseInt(targetPinId, 10);
                    setTimeout(() => {
                        this.openPinById(id);
                    }, 600);
                }
            } catch (_) {}
        },

        togglePins() {
            this.pinsVisible = !this.pinsVisible;
            try {
                if (typeof localStorage !== 'undefined') {
                    localStorage.setItem('cw_feedback_pins', String(this.pinsVisible));
                }
            } catch (_) {}
        },

        showToast(msg: string) {
            this.toast.message = msg;
            this.toast.visible = true;
            setTimeout(() => {
                this.toast.visible = false;
            }, 3000);
        },

        async fetchPins() {
            try {
                const path = window.location.pathname;
                const res = await fetch(`/feedback/pins?path=${encodeURIComponent(path)}`, {
                    headers: { Accept: 'application/json' },
                });
                const data = await res.json();
                if (data.ok && Array.isArray(data.pins)) {
                    this.pins = data.pins;
                    this.updatePinCoordinates();
                }
            } catch (_) {}
        },

        updatePinCoordinates() {
            for (const pin of this.pins) {
                if (pin.selector) {
                    try {
                        const el = document.querySelector(pin.selector) as HTMLElement | null;
                        if (el) {
                            const rect = el.getBoundingClientRect();
                            pin.screenX = rect.left + window.scrollX + Math.min(rect.width, 24);
                            pin.screenY = rect.top + window.scrollY - 10;
                            continue;
                        }
                    } catch (_) {}
                }

                // Fallback to relative document coordinates
                pin.screenX = pin.x_pos > 0 ? (pin.x_pos / 100) * document.documentElement.scrollWidth : 20;
                pin.screenY = pin.y_pos > 0 ? (pin.y_pos / 100) * document.documentElement.scrollHeight : 100;
            }
        },

        setupRepositionOnScrollResize() {
            const update = () => {
                if (this.pins.length > 0) {
                    this.updatePinCoordinates();
                }
            };

            window.addEventListener('resize', update, { passive: true });
            window.addEventListener('scroll', update, { passive: true });
        },

        findNearestHeading(el: HTMLElement): string {
            // 1. Check if el or its parent container has a header or title
            const container = el.closest('section, article, .card, main, [role="region"], header, nav');
            if (container) {
                const heading = container.querySelector('h1, h2, h3, h4, h5, [role="heading"], .font-display');
                if (heading && heading !== el && heading.textContent?.trim()) {
                    return heading.textContent.trim().replace(/\s+/g, ' ').slice(0, 70);
                }
            }

            // 2. Scan previous siblings and upwards
            let curr: Element | null = el;
            while (curr && curr !== document.body) {
                let prev = curr.previousElementSibling;
                while (prev) {
                    if (prev.matches('h1, h2, h3, h4, h5, [role="heading"]')) {
                        const txt = prev.textContent?.trim().replace(/\s+/g, ' ');
                        if (txt) return txt.slice(0, 70);
                    }
                    const nested = prev.querySelector('h1, h2, h3, h4, h5, [role="heading"]');
                    if (nested?.textContent?.trim()) {
                        return nested.textContent.trim().replace(/\s+/g, ' ').slice(0, 70);
                    }
                    prev = prev.previousElementSibling;
                }
                curr = curr.parentElement;
            }

            // 3. Fallback to main page h1
            const h1 = document.querySelector('h1');
            return h1?.textContent?.trim().replace(/\s+/g, ' ').slice(0, 70) || '';
        },

        getContainerSummary(el: HTMLElement): string {
            const container = el.closest('.card, table, form, nav, header, aside, section, [id]');
            if (!container || container === el) {
                return '';
            }

            let desc = '';
            if (container.id) {
                desc += `#${container.id}`;
            } else if (container.classList.contains('card')) {
                desc += 'Card Container';
            } else {
                desc += `<${container.tagName.toLowerCase()}>`;
            }

            const ariaLabel = container.getAttribute('aria-label');
            if (ariaLabel) {
                desc += ` (${ariaLabel})`;
            }

            return desc;
        },

        getElementHierarchy(el: HTMLElement): string {
            const parts: string[] = [];
            let curr: HTMLElement | null = el;
            while (curr && curr !== document.body && parts.length < 4) {
                let name = curr.tagName.toLowerCase();
                if (curr.id) {
                    name += `#${curr.id}`;
                } else if (curr.className && typeof curr.className === 'string') {
                    const firstClass = curr.className
                        .split(' ')
                        .filter((c) => c && !c.includes(':') && !c.includes('[') && !c.includes('/'))[0];
                    if (firstClass) name += `.${firstClass}`;
                }
                parts.unshift(name);
                curr = curr.parentElement;
            }
            return parts.join(' > ');
        },

        setupRightClickListener() {
            document.addEventListener('contextmenu', (e: MouseEvent) => {
                // If user holds Shift, allow native browser context menu
                if (e.shiftKey) return;

                const target = e.target as HTMLElement | null;
                if (!target) return;

                // Don't intercept right clicks inside feedback modals or overlays
                if (target.closest('#cw-feedback-overlay-root') || target.closest('.cw-feedback-no-intercept')) {
                    return;
                }

                e.preventDefault();

                const selector = this.computeSelector(target);
                const tag = target.tagName.toLowerCase();
                const text = (
                    target.innerText ||
                    target.getAttribute('title') ||
                    target.getAttribute('aria-label') ||
                    ''
                )
                    .replace(/\s+/g, ' ')
                    .trim()
                    .slice(0, 100);

                const serverCtx = window.__CW_FEEDBACK_SERVER_CONTEXT__;
                const pagePath = window.location.pathname;
                const pageUrl = window.location.href;
                const pageTitle = document.title;
                const routeName = serverCtx?.routeName || '';
                const controllerAction = serverCtx?.controllerAction || '';
                const viewName = serverCtx?.viewName || '';
                const viewPath = serverCtx?.viewPath || '';

                const nearestHeading = this.findNearestHeading(target);
                const containerSummary = this.getContainerSummary(target);
                const hierarchy = this.getElementHierarchy(target);

                // Ensure context menu stays on-screen
                const menuWidth = 320;
                const menuHeight = 220;
                const x =
                    e.clientX + menuWidth > window.innerWidth
                        ? Math.max(10, window.innerWidth - menuWidth - 10)
                        : e.clientX;
                const y =
                    e.clientY + menuHeight > window.innerHeight
                        ? Math.max(10, window.innerHeight - menuHeight - 10)
                        : e.clientY;

                this.contextMenu = {
                    visible: true,
                    x,
                    y,
                    targetEl: target,
                    selector,
                    tag,
                    text,
                    pagePath,
                    pageUrl,
                    pageTitle,
                    routeName,
                    controllerAction,
                    viewName,
                    viewPath,
                    nearestHeading,
                    containerSummary,
                    hierarchy,
                };
            });

            // Close context menu on left click anywhere or escape
            document.addEventListener('click', (e: MouseEvent) => {
                const target = e.target as HTMLElement | null;
                if (!target?.closest('#cw-feedback-context-menu')) {
                    this.contextMenu.visible = false;
                }
            });

            document.addEventListener('keydown', (e: KeyboardEvent) => {
                if (e.key === 'Escape') {
                    this.contextMenu.visible = false;
                    if (this.newModalOpen) this.newModalOpen = false;
                    if (this.activePin) this.activePin = null;
                }
            });
        },

        computeSelector(el: HTMLElement): string {
            if (el.id) {
                return `#${el.id}`;
            }

            const path: string[] = [];
            let curr: HTMLElement | null = el;

            while (curr && curr.nodeType === Node.ELEMENT_NODE && curr !== document.body) {
                let sel = curr.tagName.toLowerCase();
                if (curr.id) {
                    sel = `#${curr.id}`;
                    path.unshift(sel);
                    break;
                }

                // Add significant class names (excluding dynamic or utility prefixes)
                const classes = Array.from(curr.classList).filter(
                    (c) =>
                        !c.startsWith('hover:') &&
                        !c.startsWith('focus:') &&
                        !c.startsWith('dark:') &&
                        !c.startsWith('transition') &&
                        !c.startsWith('text-[') &&
                        !c.startsWith('bg-['),
                );
                if (classes.length > 0) {
                    sel += `.${classes.slice(0, 2).join('.')}`;
                }

                // Add nth-of-type if siblings exist with same selector
                if (curr.parentElement) {
                    const siblings = Array.from(curr.parentElement.children).filter((s) => s.tagName === curr?.tagName);
                    if (siblings.length > 1) {
                        const index = siblings.indexOf(curr) + 1;
                        sel += `:nth-of-type(${index})`;
                    }
                }

                path.unshift(sel);
                curr = curr.parentElement;
                if (path.length >= 4) break; // Keep selector concise
            }

            return path.join(' > ');
        },

        openNewFeedbackModal() {
            this.contextMenu.visible = false;
            this.newForm.type = 'bug';
            const contextPrefix = this.contextMenu.nearestHeading
                ? `${this.contextMenu.nearestHeading}: `
                : this.contextMenu.containerSummary
                  ? `${this.contextMenu.containerSummary}: `
                  : '';
            this.newForm.title = this.contextMenu.text
                ? `${contextPrefix}${this.contextMenu.text.slice(0, 50)}`
                : `${contextPrefix}Issue on <${this.contextMenu.tag}>`;
            this.newForm.content = '';
            this.newModalOpen = true;

            // Highlight target element temporarily
            if (this.contextMenu.targetEl) {
                this.contextMenu.targetEl.classList.add('cw-feedback-target-pulse');
            }
        },

        closeNewFeedbackModal() {
            this.newModalOpen = false;
            if (this.contextMenu.targetEl) {
                this.contextMenu.targetEl.classList.remove('cw-feedback-target-pulse');
            }
        },

        async submitNewFeedback() {
            if (!this.newForm.title.trim() || !this.newForm.content.trim() || this.newForm.submitting) return;

            this.newForm.submitting = true;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            const rect = this.contextMenu.targetEl?.getBoundingClientRect();
            const docWidth = document.documentElement.scrollWidth || window.innerWidth;
            const docHeight = document.documentElement.scrollHeight || window.innerHeight;

            const xPos = rect
                ? ((rect.left + window.scrollX) / docWidth) * 100
                : (this.contextMenu.x / window.innerWidth) * 100;
            const yPos = rect
                ? ((rect.top + window.scrollY) / docHeight) * 100
                : (this.contextMenu.y / window.innerHeight) * 100;

            try {
                const res = await fetch('/feedback', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        url: this.contextMenu.pageUrl || window.location.href,
                        path: this.contextMenu.pagePath || window.location.pathname,
                        route_name: this.contextMenu.routeName || null,
                        controller_action: this.contextMenu.controllerAction || null,
                        view_name: this.contextMenu.viewPath || null,
                        title: this.newForm.title.trim(),
                        content: this.newForm.content.trim(),
                        type: this.newForm.type,
                        selector: this.contextMenu.selector,
                        element_tag: this.contextMenu.tag,
                        element_text: this.contextMenu.text,
                        x_pos: xPos,
                        y_pos: yPos,
                        viewport_width: window.innerWidth,
                        viewport_height: window.innerHeight,
                        metadata: {
                            page_title: this.contextMenu.pageTitle || document.title,
                            nearest_heading: this.contextMenu.nearestHeading || null,
                            container: this.contextMenu.containerSummary || null,
                            hierarchy: this.contextMenu.hierarchy || null,
                            theme: document.documentElement.getAttribute('data-theme') || 'light',
                            userAgent: navigator.userAgent,
                        },
                    }),
                });

                const data = await res.json();
                if (data.ok) {
                    this.closeNewFeedbackModal();
                    this.showToast('Feedback pinned & saved!');
                    await this.fetchPins();
                    this.pinsVisible = true;
                }
            } catch (_) {
                this.showToast('Error saving feedback.');
            } finally {
                this.newForm.submitting = false;
            }
        },

        openPin(pin: FeedbackPinData) {
            this.activePin = pin;
            this.replyContent = '';

            // Highlight target element if present
            if (pin.selector) {
                try {
                    const el = document.querySelector(pin.selector) as HTMLElement | null;
                    if (el) {
                        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        el.classList.add('cw-feedback-target-pulse');
                        setTimeout(() => {
                            el.classList.remove('cw-feedback-target-pulse');
                        }, 2500);
                    }
                } catch (_) {}
            }
        },

        openPinById(id: number) {
            const found = this.pins.find((p) => p.id === id);
            if (found) {
                this.openPin(found);
            }
        },

        closeActivePin() {
            this.activePin = null;
        },

        async submitReply() {
            if (!this.activePin || !this.replyContent.trim() || this.replySubmitting) return;

            this.replySubmitting = true;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            try {
                const res = await fetch(`/feedback/${this.activePin.id}/comments`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        content: this.replyContent.trim(),
                    }),
                });

                const data = await res.json();
                if (data.ok && data.comment) {
                    this.activePin.comments.push(data.comment);
                    this.activePin.comments_count = this.activePin.comments.length;
                    if (data.updated_prompt) {
                        this.activePin.claude_prompt = data.updated_prompt;
                    }
                    this.replyContent = '';
                    this.showToast('Reply added.');
                }
            } catch (_) {
                this.showToast('Error adding reply.');
            } finally {
                this.replySubmitting = false;
            }
        },

        async updateStatus(newStatus: 'open' | 'approved' | 'in_progress' | 'resolved' | 'dismissed') {
            if (!this.activePin) return;

            const targetPinId = this.activePin.id;
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

            try {
                const res = await fetch(`/feedback/${targetPinId}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                        Accept: 'application/json',
                    },
                    body: JSON.stringify({
                        status: newStatus,
                    }),
                });

                const data = await res.json();
                if (data.ok) {
                    if (this.activePin && this.activePin.id === targetPinId) {
                        this.activePin.status = newStatus;
                        this.activePin.status_class = data.status_class;
                    }

                    if (newStatus === 'resolved' || newStatus === 'dismissed') {
                        // Immediately remove pin from page overlay
                        this.pins = this.pins.filter((p) => p.id !== targetPinId);
                        this.activePin = null;
                        this.showToast('Issue marked as resolved and removed from screen.');
                    } else {
                        this.showToast(`Status updated to ${newStatus.replace('_', ' ')}.`);
                    }
                }
            } catch (_) {
                this.showToast('Error updating status.');
            }
        },

        copyClaudePrompt(promptText?: string) {
            const textToCopy = promptText || this.activePin?.claude_prompt || '';
            if (!textToCopy) return;

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy).then(() => {
                    this.showToast('Claude prompt copied to clipboard!');
                });
            } else {
                // Fallback for non-secure contexts
                const textArea = document.createElement('textarea');
                textArea.value = textToCopy;
                textArea.style.position = 'fixed';
                textArea.style.opacity = '0';
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    this.showToast('Claude prompt copied to clipboard!');
                } catch (_) {
                    this.showToast('Failed to copy to clipboard.');
                }
                document.body.removeChild(textArea);
            }
        },
    };
}
