import { layoutStylePicker } from '../layout-style.js';
import { quickJumpPicker } from '../quick-jump.js';
import { themePicker } from '../theme.js';

// One Alpine component for the app shell. Spreading layoutStylePicker() and
// themePicker() into an object literal would collide on init() — Alpine only
// calls the last one, so layout state stayed "modern" after a FOUC restore.
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
