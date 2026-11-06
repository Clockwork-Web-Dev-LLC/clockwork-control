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
                // The drawer is only reachable while no persistent nav is on screen: the studio
                // tabs return at md (modern layout), the sidebar rail at lg (command-center).
                // Close it once the persistent nav for the current layout is back.
                const md = window.matchMedia('(min-width: 768px)');
                const lg = window.matchMedia('(min-width: 1024px)');
                const dismiss = () => {
                    const persistentNav = (this as any).style === 'command-center' ? lg.matches : md.matches;
                    if (persistentNav) {
                        this.mobileNavOpen = false;
                    }
                };
                for (const query of [md, lg]) {
                    if (typeof query.addEventListener === 'function') {
                        query.addEventListener('change', dismiss);
                    } else if (typeof (query as any).addListener === 'function') {
                        (query as any).addListener(dismiss);
                    }
                }
            }
        },
    };

    Object.defineProperties(chrome, Object.getOwnPropertyDescriptors(quickJump));
    return chrome;
}
