import { layoutStylePicker } from '../layout-style.js';
import { quickJumpPicker } from '../quick-jump.js';
import { themePicker } from '../theme.js';

/**
 * Application Chrome Shell Component
 *
 * Mounted on the root `<body>` tag in `resources/views/layouts/app.blade.php`:
 *   <body x-data="appChrome()">
 *
 * Unifies the master application shell state:
 * - Desktop sidebar toggle with persistence in localStorage (`cw_cc_sidebar`).
 * - Mobile drawer navigation state with body-scroll locking and breakpoint auto-dismiss.
 * - Composes `layoutStylePicker()`, `themePicker()`, and `quickJumpPicker()` into a single
 *   component to prevent Alpine `init()` lifecycle collisions.
 */
export function appChrome(): Record<string, any> {
    const layout = layoutStylePicker();
    const theme = themePicker();
    const quickJump = quickJumpPicker();
    const layoutInit = layout.init;
    const themeInit = theme.init;
    const quickJumpInit = quickJump.initQuickJump;

    const chrome: Record<string, any> = {
        sidebarOpen: typeof localStorage !== 'undefined' && localStorage.getItem('cw_cc_sidebar') !== 'false',
        mobileNavOpen: false,
        userMenuOpen: false,
        sidebarUserMenuOpen: false,
        toggleSidebar() {
            this.sidebarOpen = !this.sidebarOpen;
            try {
                localStorage.setItem('cw_cc_sidebar', this.sidebarOpen);
            } catch (_e) {}
        },
        closeMobileNav() {
            this.mobileNavOpen = false;
        },
        ...layout,
        ...theme,
        init() {
            layoutInit.call(this);
            themeInit.call(this);
            if (typeof quickJumpInit === 'function') {
                quickJumpInit.call(this);
            }

            (this as any).$watch('mobileNavOpen', (open: boolean) => {
                document.body.classList.toggle('cw-mobile-nav-open', open);
            });

            if (typeof window.matchMedia === 'function') {
                const desktop = window.matchMedia('(min-width: 768px)');
                const dismiss = (event: MediaQueryListEvent | MediaQueryList) => {
                    if (event.matches) {
                        this.mobileNavOpen = false;
                    }
                };
                if (typeof desktop.addEventListener === 'function') {
                    desktop.addEventListener('change', dismiss);
                } else if (typeof (desktop as any).addListener === 'function') {
                    (desktop as any).addListener(dismiss);
                }
            }
        },
    };

    Object.defineProperties(chrome, Object.getOwnPropertyDescriptors(quickJump));
    return chrome;
}
