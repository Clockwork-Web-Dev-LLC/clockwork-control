/**
 * Clockwork Control — Client Application Entry Point
 *
 * Boots the Alpine.js runtime, registers modular UI & chart components,
 * exposes backward-compatible globals, and initializes system services
 * (theming, typography scaling, layout switcher, tooltips, and confirmation modals).
 */
import Alpine from 'alpinejs';
import { echarts } from './charts/echarts.js';
import { performanceTrendChart } from './charts/performance-chart.js';
import { serverMetricsChart } from './charts/server-metrics-chart.js';
import { trafficCharts } from './charts/traffic-charts.js';
import { columnToggle } from './components/column-toggle.js';
import { docsSearch, docsSidebar } from './components/docs.js';
import { feedbackOverlay } from './components/feedback-overlay.js';
import { issuesDashboard } from './components/issues-dashboard.js';
import { monitoringPage } from './components/monitoring-page.js';
import { siteDashboardReorder } from './components/site-dashboard-reorder.js';
import { sitesPage } from './components/sites-page.js';
import { sortableTable } from './components/sortable-table.js';
import { wpPluginsManager } from './components/wp-plugins-manager.js';
import { initConfirmModalSystem } from './confirm-modal.js';
import { initFontScaleSystem } from './font-scale.js';
import { initLayoutStyleSystem } from './layout-style.js';
import { appChrome } from './system/app-chrome.js';
import { initThemeSystem } from './theme.js';
import { initTooltipSystem } from './tooltip.js';

// Expose globals for backward compatibility with inline scripts and legacy views
window.Alpine = Alpine;
window.echarts = echarts;
window.appChrome = appChrome;

// Register Alpine Components
Alpine.data('sortableTable', sortableTable);
Alpine.data('columnToggle', columnToggle);
Alpine.data('docsSidebar', docsSidebar);
Alpine.data('docsSearch', docsSearch);
Alpine.data('siteDashboardReorder', siteDashboardReorder);
Alpine.data('issuesDashboard', issuesDashboard);
Alpine.data('sitesPage', sitesPage);
Alpine.data('monitoringPage', monitoringPage);
Alpine.data('feedbackOverlay', feedbackOverlay);
Alpine.data('trafficCharts', trafficCharts);
Alpine.data('performanceTrendChart', performanceTrendChart);
Alpine.data('serverMetricsChart', serverMetricsChart);
Alpine.data('wpPluginsManager', wpPluginsManager);
Alpine.data('appChrome', appChrome);

// Initialize System Services
initThemeSystem(Alpine);
initFontScaleSystem(Alpine);
initLayoutStyleSystem(Alpine);
initTooltipSystem();
initConfirmModalSystem(Alpine);

// Start Alpine
Alpine.start();
