import type { Alpine as AlpineType } from 'alpinejs';
import type * as echarts from 'echarts/core';

/**
 * Options accepted by window.confirmModal(). Mirrors what
 * resources/js/confirm-modal.js actually reads off the options object —
 * keep the two in sync or callers silently lose fields (this is how the
 * `details` line went missing from the WP Plugins install prompts).
 */
export interface ConfirmModalOptions {
    title?: string;
    message: string;
    /** Secondary line rendered under the message — consequences, scope, etc. */
    details?: string;
    confirmText?: string;
    cancelText?: string;
    variant?: 'danger' | 'warning' | 'primary' | 'info';
    /** Font Awesome class list, e.g. 'fa-solid fa-trash'. */
    icon?: string;
    /** When set, the user must type this exact string before confirm enables. */
    requireMatch?: string;
}

export interface PromptModalOptions extends ConfirmModalOptions {
    placeholder?: string;
    defaultValue?: string;
}

export interface AlertModalOptions {
    title?: string;
    message: string;
    details?: string;
    /** alertModal reads `buttonText`, not `confirmText`. */
    buttonText?: string;
    variant?: 'danger' | 'warning' | 'primary' | 'info';
    icon?: string;
}

declare global {
    interface Window {
        Alpine: AlpineType;
        echarts: typeof echarts;
        confirmModal: (options: ConfirmModalOptions | string) => Promise<boolean>;
        /** Resolves to the entered string, or null when cancelled. */
        promptModal: (options: PromptModalOptions | string) => Promise<string | null>;
        alertModal: (options: AlertModalOptions | string) => Promise<boolean>;
        appChrome: () => Record<string, unknown>;
        themePicker: () => Record<string, unknown>;
        layoutStylePicker: () => Record<string, unknown>;
        monitoringFilterSearch?: (term: string) => void;
        monitoringOpenClassify?: (siteId: number, domain: string, reason?: string, notes?: string) => void;
        monitoringCloseClassify?: () => void;
        cwQuickJumpItems?: Array<Record<string, unknown>>;
    }
}
