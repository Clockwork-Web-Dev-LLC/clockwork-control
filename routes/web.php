<?php

use App\Http\Controllers\AppearanceSettingsController;
use App\Http\Controllers\Auth\DevLoginController;
use App\Http\Controllers\Auth\GoogleAuthController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BansController;
use App\Http\Controllers\BlockedIpsController;
use App\Http\Controllers\CapacityController;
use App\Http\Controllers\CarePlanSettingsController;
use App\Http\Controllers\CompanionDownloadController;
use App\Http\Controllers\CompanionSettingsController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\DocsController;
use App\Http\Controllers\IngestSettingsController;
use App\Http\Controllers\IntegrationCredentialsController;
use App\Http\Controllers\IssuesController;
use App\Http\Controllers\MaintenanceController;
use App\Http\Controllers\MaintenanceHistoryController;
use App\Http\Controllers\ModuleDirectoryController;
use App\Http\Controllers\MonitoringController;
use App\Http\Controllers\NotificationSettingsController;
use App\Http\Controllers\OperationsUpdatesController;
use App\Http\Controllers\ReviewQueueController;
use App\Http\Controllers\SecurityAdminsController;
use App\Http\Controllers\SecurityScansController;
use App\Http\Controllers\SecurityScansSettingsController;
use App\Http\Controllers\ServerCredentialsController;
use App\Http\Controllers\ServerProvisionController;
use App\Http\Controllers\ServersController;
use App\Http\Controllers\ServerUpdateController;
use App\Http\Controllers\ServiceApiLimitsController;
use App\Http\Controllers\Settings\BackupRelaySettingsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\Sites\DomainExpirationController;
use App\Http\Controllers\Sites\SeoPreflightController;
use App\Http\Controllers\SitesController;
use App\Http\Controllers\SiteWorkLogsController;
use App\Http\Controllers\SystemUpdatesController;
use App\Http\Controllers\TagsController;
use App\Http\Controllers\UpdatesController;
use App\Http\Controllers\UsersSettingsController;
use App\Http\Controllers\WeirdStatsController;
use App\Http\Controllers\WordPressPluginsController;
use App\Http\Middleware\RedirectToSetupIfFreshInstall;
use Illuminate\Support\Facades\Route;

// Public — auth flow only. Everything else is gated below.
Route::get('/login', [LoginController::class, 'show'])->name('login');
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:5,1')->name('login.attempt');
Route::middleware('throttle:10,1')->group(function () {
    Route::get('/auth/google/redirect', [GoogleAuthController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/auth/google/callback', [GoogleAuthController::class, 'callback'])->name('auth.google.callback');
});

Route::get('/dev-login', DevLoginController::class)->name('dev-login');

// Everything below this line requires an authenticated, non-revoked user.
// auth + active (revoked_at) are applied via this single group wrapper rather
// than per-route so it's hard to forget. New routes go INSIDE the closure.
Route::middleware(['auth', 'active'])->group(function () {

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    // Team (allowlist) management — admin-only. Operators can use the fleet
    // but cannot add, revoke, restore, or reset passwords for other users.
    Route::middleware('admin')->group(function () {
        Route::get('/settings/users', [UsersSettingsController::class, 'index'])->name('settings.users.index');
        Route::post('/settings/users', [UsersSettingsController::class, 'store'])->name('settings.users.store');
        Route::patch('/settings/users/{user}/revoke', [UsersSettingsController::class, 'revoke'])->name('settings.users.revoke');
        Route::patch('/settings/users/{user}/restore', [UsersSettingsController::class, 'restore'])->name('settings.users.restore');
        Route::patch('/settings/users/{user}/password', [UsersSettingsController::class, 'updatePassword'])->name('settings.users.password');
    });

    // Appearance & theme preferences
    Route::post('/settings/appearance', [AppearanceSettingsController::class, 'update'])->name('settings.appearance.update');

    Route::get('/', [DashboardController::class, 'index'])
        ->middleware(RedirectToSetupIfFreshInstall::class)
        ->name('dashboard');

    Route::get('/setup', [SetupController::class, 'step1'])->name('setup.index');
    Route::get('/setup/step-1', [SetupController::class, 'step1'])->name('setup.step1');
    Route::post('/setup/step-1', [SetupController::class, 'step1Save'])->name('setup.step1.save');
    Route::post('/setup/toggle', [SetupController::class, 'toggleService'])->name('setup.toggle');
    Route::get('/setup/configure', [SetupController::class, 'step2'])->name('setup.step2');
    Route::post('/setup/configure', [SetupController::class, 'step2Save'])->name('setup.step2.save');
    Route::get('/setup/modules', [SetupController::class, 'step1'])->name('setup.modules.edit');
    Route::patch('/setup/modules', [SetupController::class, 'step1Save'])->name('setup.modules.update');

    Route::get('/servers/credentials', [ServerCredentialsController::class, 'bulk'])
        ->name('servers.credentials.bulk');
    Route::post('/servers/credentials', [ServerCredentialsController::class, 'bulkUpdate'])
        ->name('servers.credentials.bulkUpdate');

    Route::get('/servers/credentials/feed', [ServerCredentialsController::class, 'feed'])
        ->name('servers.credentials.feed');
    Route::post('/servers/credentials/feed', [ServerCredentialsController::class, 'feedParse'])
        ->name('servers.credentials.feedParse');
    Route::post('/servers/credentials/feed/apply', [ServerCredentialsController::class, 'feedApply'])
        ->name('servers.credentials.feedApply');

    Route::get('/servers/new', [ServersController::class, 'create'])->name('servers.create');
    Route::post('/servers', [ServersController::class, 'store'])->name('servers.store');

    Route::post('/servers/refresh-spinupwp', [ServersController::class, 'refreshFromSpinupWp'])
        ->name('servers.refreshFromSpinupWp');
    Route::post('/servers/refresh-gridpane', [ServersController::class, 'refreshFromGridPane'])
        ->name('servers.refreshFromGridPane');

    // Tab-aware server detail. The {tab?} segment is constrained to known tab names so other
    // /servers/{server}/* routes (edit, test, provision, etc.) still resolve normally — Laravel
    // falls through when the constraint doesn't match.
    Route::get('/servers/{server}/{tab?}', [DashboardController::class, 'show'])
        ->whereIn('tab', ['sites', 'stats', 'updates', 'bans', 'settings'])
        ->name('servers.show');
    Route::get('/servers/{server}/edit', [ServerCredentialsController::class, 'edit'])
        ->name('servers.credentials.edit');
    Route::patch('/servers/{server}/credentials', [ServerCredentialsController::class, 'update'])
        ->name('servers.credentials.update');
    Route::post('/servers/{server}/test', [ServerCredentialsController::class, 'test'])
        ->name('servers.test');
    Route::post('/servers/{server}/toggle-ignore', [ServersController::class, 'toggleIgnore'])
        ->name('servers.toggleIgnore');
    Route::delete('/servers/{server}', [ServersController::class, 'destroy'])
        ->name('servers.destroy');
    Route::post('/servers/{server}/provision/fail2ban', [ServerProvisionController::class, 'fail2ban'])
        ->name('servers.provision.fail2ban');
    Route::post('/servers/{server}/ban-ip', [BlockedIpsController::class, 'ban'])
        ->name('servers.ban');
    Route::post('/servers/{server}/auto-ban-llar', [ServersController::class, 'toggleAutoBanLlar'])
        ->name('servers.toggleAutoBanLlar');
    Route::post('/servers/{server}/auto-ban-wordfence', [ServersController::class, 'toggleAutoBanWordfence'])
        ->name('servers.toggleAutoBanWordfence');
    Route::post('/servers/{server}/recheck-health', [ServersController::class, 'recheckHealth'])
        ->name('servers.recheck-health');

    Route::post('/servers/{server}/update/queue', [ServerUpdateController::class, 'queue'])
        ->name('servers.update.queue');
    Route::post('/servers/{server}/update/cancel', [ServerUpdateController::class, 'cancel'])
        ->name('servers.update.cancel');
    Route::post('/servers/{server}/reboot', [ServerUpdateController::class, 'reboot'])
        ->name('servers.reboot');
    Route::post('/servers/{server}/reboot/cancel', [ServerUpdateController::class, 'cancelReboot'])
        ->name('servers.reboot.cancel');
    Route::post('/servers/{server}/reboot/probe', [ServerUpdateController::class, 'probeReboot'])
        ->name('servers.reboot.probe');

    // Bans page — unified queue + active bans + history (replaces /review and /blocked-ips
    // as the user-facing destination). The POST endpoints below stay where they are because
    // they map to resources, not pages — forms inside the new bans partials still post to them.
    Route::get('/bans', [BansController::class, 'index'])->name('bans.index');
    Route::get('/bans/queue', [BansController::class, 'queue'])->name('bans.queue');
    Route::get('/bans/active', [BansController::class, 'active'])->name('bans.active');
    Route::get('/bans/history', [BansController::class, 'history'])->name('bans.history');

    // Old GET URLs → 301 to the new tabs. Keeps bookmarks working but retrains muscle memory.
    // request()->query() returns the array of query params directly (no ->all() needed).
    Route::get('/review', fn () => redirect()->route('bans.queue', request()->query(), 301))->name('review-queue.index');
    Route::get('/blocked-ips', fn () => redirect()->route('bans.active', request()->query(), 301))->name('blocked-ips.index');

    // Review queue mutation endpoints — unchanged URLs + names so existing forms keep posting here.
    Route::post('/review/auto-approve/toggle', [ReviewQueueController::class, 'toggleAutoApprove'])
        ->name('review-queue.toggleAutoApprove');
    Route::post('/review/bulk-approve', [ReviewQueueController::class, 'bulkApprove'])
        ->name('review-queue.bulkApprove');
    Route::post('/review/bulk-dismiss', [ReviewQueueController::class, 'bulkDismiss'])
        ->name('review-queue.bulkDismiss');
    Route::post('/review/{entry}/approve', [ReviewQueueController::class, 'approve'])
        ->name('review-queue.approve');
    Route::post('/review/{entry}/dismiss', [ReviewQueueController::class, 'dismiss'])
        ->name('review-queue.dismiss');

    Route::get('/issues', [IssuesController::class, 'index'])->name('issues.index');
    Route::post('/issues/poll-servers', [IssuesController::class, 'pollServers'])->name('issues.poll-servers');
    Route::post('/issues/fetch-all-db-creds', [IssuesController::class, 'fetchAllDbCreds'])->name('issues.fetch-all-db-creds');
    Route::delete('/issues/orphans/{siteId}', [IssuesController::class, 'destroyOrphan'])->name('issues.orphans.destroy');
    Route::post('/issues/ignore', [IssuesController::class, 'ignore'])->name('issues.ignore');
    Route::post('/issues/unignore/{ignoredIssue}', [IssuesController::class, 'unignore'])->name('issues.unignore');

    Route::get('/capacity', [CapacityController::class, 'index'])->name('capacity.index');
    Route::get('/capacity/settings', [CapacityController::class, 'settings'])->name('capacity.settings');
    Route::patch('/capacity/settings', [CapacityController::class, 'updateSettings'])->name('capacity.settings.update');
    Route::get('/settings/capacity', fn () => redirect()->route('capacity.settings'))->name('settings.capacity.index');
    Route::post('/capacity/site-metrics/toggle', [CapacityController::class, 'toggleSiteMetrics'])->name('capacity.site-metrics.toggle');

    // In-app team documentation. Markdown files live in resources/docs/, organized
    // by section subdirectories. Path-traversal guarded inside DocsManifest.
    Route::get('/docs', [DocsController::class, 'index'])->name('docs.index');
    Route::get('/docs/{path}', [DocsController::class, 'show'])
        ->where('path', '[a-z0-9\-/]+')
        ->name('docs.show');

    // Top-level Monitoring section — fleet-wide uptime activity + global settings.
    // The probe schedule reads `monitoring.uptime_interval_minutes` from Settings;
    // UptimeStateUpdater reads `monitoring.uptime_failure_threshold`.
    Route::prefix('monitoring')->name('monitoring.')->group(function () {
        Route::get('/', [MonitoringController::class, 'index'])->name('index');
        Route::post('/refresh', [MonitoringController::class, 'refresh'])->name('refresh');
        Route::get('/settings', [MonitoringController::class, 'settings'])->name('settings');
        Route::patch('/settings', [MonitoringController::class, 'updateSettings'])->name('settings.update');
        Route::post('/sites/{site}/classify-outage', [MonitoringController::class, 'classifyOutage'])->name('sites.classify-outage');
        Route::post('/events/{event}/classify', [MonitoringController::class, 'classifyEvent'])->name('events.classify');
    });

    // Security scans — fleet inventory of latest Sucuri SiteCheck + wp core
    // verify-checksums per site. Replaces the security feature of ManageWP.
    Route::prefix('security')->name('security.')->group(function () {
        Route::get('/scans', [SecurityScansController::class, 'index'])->name('scans');
        Route::get('/admins', [SecurityAdminsController::class, 'index'])->name('admins');
        Route::patch('/admins/allowlist', [SecurityAdminsController::class, 'updateAllowlist'])->name('admins.allowlist');
        Route::post('/admins/{site}/ignore', [SecurityAdminsController::class, 'ignore'])->name('admins.ignore');
        Route::delete('/admins/{site}/ignore/{ignoredWpAdmin}', [SecurityAdminsController::class, 'unignore'])->name('admins.unignore');
        Route::post('/scans/{site}/run', [SecurityScansController::class, 'runForSite'])->name('scans.run');

        // File-contents view + per-site allowlist for core_checksums findings.
        // The viewer SSHs to read the flagged file (path-validated against the
        // latest scan). Allowlist entries suppress benign findings from /issues.
        Route::get('/scans/{site}/file', [SecurityScansController::class, 'viewFile'])->name('scans.file');
        Route::post('/scans/{site}/allowlist', [SecurityScansController::class, 'addToAllowlist'])->name('scans.allowlist.add');
        Route::delete('/scans/{site}/allowlist/{entry}', [SecurityScansController::class, 'removeFromAllowlist'])->name('scans.allowlist.remove');
    });

    // Fleet-wide Updates page — plugins/themes/core/translations grouped by
    // name with bulk Update / Ignore actions. Replaces the per-site update
    // flow for batch work; the per-site Updates tab still exists for one-off
    // updates. See Plans/image-19-i-feel-dynamic-stallman.md for context.
    Route::prefix('updates')->name('updates.')->group(function () {
        Route::get('/', [UpdatesController::class, 'index'])->name('index');
        Route::get('/care-plan', [UpdatesController::class, 'carePlan'])->name('carePlan');
        Route::post('/bulk-update', [UpdatesController::class, 'bulkUpdate'])->name('bulkUpdate');
        Route::post('/bulk-ignore', [UpdatesController::class, 'bulkIgnore'])->name('bulkIgnore');
        Route::post('/bulk-unignore', [UpdatesController::class, 'bulkUnignore'])->name('bulkUnignore');
        Route::get('/batches/{batchId}/status', [UpdatesController::class, 'batchStatus'])
            ->where('batchId', '[0-9a-f-]{36}')
            ->name('batches.status');
    });

    Route::get('/maintenance-history', [MaintenanceHistoryController::class, 'index'])->name('maintenance-history.index');

    // Operations workspace root — redirects to Capacity overview
    Route::get('/operations', fn () => redirect()->route('capacity.index'))->name('operations.index');

    // Fleet-wide server updates dashboard (Operations → Fleet Updates). Lists every
    // non-ignored server with its latest apt-update snapshot and lets the
    // operator queue updates one-by-one or in bulk.
    Route::prefix('operations/server-updates')->name('operations.server-updates.')->group(function () {
        Route::get('/', [OperationsUpdatesController::class, 'index'])->name('index');
        Route::post('/queue-bulk', [OperationsUpdatesController::class, 'queueBulk'])->name('queueBulk');
        Route::post('/refresh', [OperationsUpdatesController::class, 'refresh'])->name('refresh');
        // GET handler exists only so a browser reloading the URL after a
        // POST doesn't 405 — bounces straight to the index.
        Route::get('/refresh', [OperationsUpdatesController::class, 'refreshRedirect']);
    });

    // Backwards-compatible aliases for legacy /operations/system-updates URL
    Route::get('/operations/system-updates', fn () => redirect()->route('operations.server-updates.index', request()->query(), 301))
        ->name('operations.system-updates.index');
    Route::post('/operations/system-updates/queue-bulk', [OperationsUpdatesController::class, 'queueBulk'])
        ->name('operations.system-updates.queueBulk');
    Route::post('/operations/system-updates/refresh', [OperationsUpdatesController::class, 'refresh'])
        ->name('operations.system-updates.refresh');
    Route::get('/operations/system-updates/refresh', [OperationsUpdatesController::class, 'refreshRedirect']);

    Route::get('/sites', [SitesController::class, 'index'])->name('sites.index');
    Route::get('/sites/create', [SitesController::class, 'create'])->name('sites.create');
    Route::post('/sites', [SitesController::class, 'store'])->name('sites.store');
    Route::get('/companion/download', [CompanionDownloadController::class, 'downloadZip'])->name('companion.download');
    Route::get('/search/sites', [SitesController::class, 'search'])->name('sites.search');

    // Tab-aware site detail. The {tab?} segment is constrained to known tab names so other
    // /sites/{site}/* routes (cert, bans, etc.) still resolve normally — Laravel falls
    // through when the constraint doesn't match. Mirrors the servers.show pattern.
    Route::get('/sites/{site}/{tab?}', [SitesController::class, 'show'])
        ->whereIn('tab', ['overview', 'traffic', 'bans', 'security', 'performance', 'settings', 'forms', 'updates'])
        ->name('sites.show');
    Route::patch('/sites/{site}/cert', [SitesController::class, 'updateCert'])->name('sites.cert.update');
    Route::patch('/sites/{site}/notes', [SitesController::class, 'updateNotes'])->name('sites.notes.update');
    Route::post('/sites/{site}/work-logs', [SiteWorkLogsController::class, 'store'])->name('sites.work-logs.store');
    Route::patch('/sites/{site}/work-logs/{workLog}', [SiteWorkLogsController::class, 'update'])->name('sites.work-logs.update');
    Route::delete('/sites/{site}/work-logs/{workLog}', [SiteWorkLogsController::class, 'destroy'])->name('sites.work-logs.destroy');
    Route::patch('/sites/{site}/uptime-keyword', [SitesController::class, 'updateUptimeKeyword'])->name('sites.uptime-keyword.update');
    Route::post('/sites/{site}/uptime-body-check', [SitesController::class, 'toggleUptimeBodyCheck'])->name('sites.uptime-body-check.toggle');
    Route::post('/sites/{site}/cache/purge', [SitesController::class, 'purgeCache'])->name('sites.cache.purge');
    Route::patch('/sites/{site}/layout', [SitesController::class, 'updateLayout'])->name('sites.layout.update');
    Route::post('/sites/{site}/cert/recheck', [SitesController::class, 'recheckCert'])->name('sites.cert.recheck');
    Route::post('/sites/{site}/uptime/recheck', [SitesController::class, 'recheckUptime'])->name('sites.uptime.recheck');
    Route::post('/sites/{site}/domain/recheck', [DomainExpirationController::class, 'recheck'])->name('sites.domain.recheck');
    Route::post('/sites/{site}/seo/pre-flight-check', [SeoPreflightController::class, 'preflight'])->name('sites.seo.preflight');
    Route::post('/sites/{site}/bans/{blockedIp}/unban', [SitesController::class, 'unbanIp'])->name('sites.bans.unban');
    Route::post('/sites/{site}/bans/unban-all', [SitesController::class, 'unbanAll'])->name('sites.bans.unban-all');
    Route::post('/sites/{site}/install-llar', [SitesController::class, 'installLlar'])->name('sites.llar.install');
    // Cleaner alias for the Companion installer — same controller method as the
    // contact-form-named route below, but reachable from any page that needs it
    // (e.g. the WordPress plugins fleet inventory).
    Route::post('/sites/{site}/install-companion', [SitesController::class, 'installCompanion'])->name('sites.companion.install');
    // Polled by the frontend after a 202 'queued' response (Pressable installs
    // run off-request — see SitesController::installCompanion).
    Route::get('/sites/{site}/install-companion/status', [SitesController::class, 'installCompanionStatus'])->name('sites.companion.install-status');
    Route::post('/sites/{site}/companion/push-update', [SitesController::class, 'pushCompanionData'])->name('sites.companion.push-update');
    Route::post('/sites/{site}/companion/refresh-snapshot', [SitesController::class, 'refreshCompanionSnapshot'])->name('sites.companion.refresh-snapshot');
    Route::post('/sites/{site}/companion/sso', [SitesController::class, 'ssoLaunch'])->name('sites.companion.sso');
    Route::post('/sites/{site}/companion/plugin-update', [SitesController::class, 'updatePlugin'])->name('sites.companion.plugin-update');
    Route::post('/sites/{site}/pressable/flush-object-cache', [SitesController::class, 'flushPressableObjectCache'])->name('sites.pressable.flush-object-cache');
    Route::get('/sites/{site}/pressable/resource-metrics', [SitesController::class, 'pressableResourceMetrics'])->name('sites.pressable.resource-metrics');
    Route::get('/sites/{site}/backups-history', [SitesController::class, 'backupsHistory'])->name('sites.backups.history');
    Route::patch('/sites/{site}/backup-relay', [SitesController::class, 'updateBackupRelay'])->name('sites.backup-relay.update');
    Route::post('/sites/{site}/backup-relay/run-now', [SitesController::class, 'runBackupNow'])->name('sites.backup-relay.run-now');
    Route::post('/sites/{site}/backup-relay/restore/stage', [SitesController::class, 'backupRelayRestoreStage'])->name('sites.backup-relay.restore.stage');
    Route::post('/sites/{site}/backup-relay/restore/apply', [SitesController::class, 'backupRelayRestoreApply'])->name('sites.backup-relay.restore.apply');
    Route::get('/sites/{site}/backup-relay/restore/status', [SitesController::class, 'backupRelayRestoreStatus'])->name('sites.backup-relay.restore.status');
    Route::get('/sites/{site}/backup-relay/restore/precheck', [SitesController::class, 'backupRelayRestorePrecheck'])->name('sites.backup-relay.restore.precheck');
    Route::post('/sites/{site}/backup-relay/restore/discard', [SitesController::class, 'backupRelayRestoreDiscard'])->name('sites.backup-relay.restore.discard');
    Route::post('/sites/{site}/care-plan', [SitesController::class, 'toggleCarePlan'])->name('sites.care-plan');
    Route::post('/sites/{site}/care-plan/clear-override', [SitesController::class, 'clearCarePlanOverride'])->name('sites.care-plan.clear-override');
    Route::post('/sites/{site}/auto-updates/toggle', [SitesController::class, 'togglePauseAutoUpdates'])->name('sites.auto-updates.toggle');
    Route::post('/sites/{site}/uptime-monitoring', [SitesController::class, 'toggleUptimeMonitoring'])->name('sites.uptime-monitoring.toggle');
    Route::post('/sites/{site}/uptime-ignore', [SitesController::class, 'toggleUptimeIgnore'])->name('sites.uptime-ignore.toggle');
    Route::post('/sites/{site}/inactive', [SitesController::class, 'toggleInactive'])->name('sites.inactive.toggle');
    Route::post('/sites/{site}/refresh-wp-plugins', [SitesController::class, 'refreshWpPlugins'])->name('sites.wp-plugins.refresh');
    Route::post('/sites/{site}/fetch-db-creds', [SitesController::class, 'fetchDbCreds'])->name('sites.fetch-db-creds');
    Route::post('/sites/{site}/email-vuln-report', [SitesController::class, 'emailVulnerabilityReport'])->name('sites.email-vuln-report');

    // Contact form testing Companion install & secret rotation (delegated to SitesController).
    // The form-test CRUD routes (/forms, /sites/{site}/form-tests) are provided by Modules\ContactForms.
    Route::post('/sites/{site}/contact-form/install-companion', [SitesController::class, 'installCompanion'])->name('sites.contact-form.install-companion');
    Route::post('/sites/{site}/contact-form/rotate-secret', [SitesController::class, 'rotateCompanionSecret'])->name('sites.contact-form.rotate-secret');

    // Soft-remove a site from monitoring. Requires the operator to type the
    // domain to confirm. The Site model's global archived-at scope hides the
    // row from every listing automatically — historical data is retained.
    Route::post('/sites/{site}/archive', [SitesController::class, 'archive'])->name('sites.archive');
    // Unarchive uses a string id + withoutGlobalScopes() inside the controller
    // because the route-model binder would 404 on archived sites.
    Route::post('/sites/{siteId}/unarchive', [SitesController::class, 'unarchive'])->name('sites.unarchive');

    // Settings Hub — centralized overview across configuration, integrations, operations, and system
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');

    // Settings — ingest schedule (LLAR pull + future ingest sources)
    Route::get('/settings/ingest', [IngestSettingsController::class, 'index'])->name('settings.ingest.index');
    Route::patch('/settings/ingest', [IngestSettingsController::class, 'update'])->name('settings.ingest.update');
    Route::patch('/settings/ingest/retention', [IngestSettingsController::class, 'updateRetention'])->name('settings.ingest.retention');
    Route::post('/settings/ingest/prune-now', [IngestSettingsController::class, 'pruneNow'])->name('settings.ingest.pruneNow');
    Route::post('/settings/ingest/rebuild-partitions', [IngestSettingsController::class, 'rebuildPartitions'])->name('settings.ingest.rebuildPartitions');
    Route::post('/settings/ingest/run-now', [IngestSettingsController::class, 'runNow'])->name('settings.ingest.runNow');
    Route::get('/settings/wordpress-plugins', [WordPressPluginsController::class, 'index'])->name('settings.wordpress-plugins.index');

    // Companion white-label branding & customization settings
    Route::get('/settings/companion', [CompanionSettingsController::class, 'index'])->name('settings.companion.index');
    Route::get('/settings/companion/preview', [CompanionSettingsController::class, 'preview'])->name('settings.companion.preview');
    Route::match(['patch', 'post'], '/settings/companion', [CompanionSettingsController::class, 'update'])->name('settings.companion.update');
    Route::post('/settings/companion/logo', [CompanionSettingsController::class, 'uploadLogo'])->name('settings.companion.logo');
    Route::post('/settings/companion/sync', [CompanionSettingsController::class, 'sync'])->name('settings.companion.sync');
    Route::post('/settings/companion/reset', [CompanionSettingsController::class, 'reset'])->name('settings.companion.reset');
    Route::post('/settings/companion/reset-reports', [CompanionSettingsController::class, 'resetReports'])->name('settings.companion.reset-reports');
    Route::post('/settings/companion/reset-email', [CompanionSettingsController::class, 'resetEmail'])->name('settings.companion.reset-email');
    Route::post('/settings/companion/test-email', [CompanionSettingsController::class, 'sendTestEmail'])->name('settings.companion.test-email');

    // Security scans (Sucuri SiteCheck + wp core verify-checksums) — toggles + run-now.
    Route::get('/settings/security-scans', [SecurityScansSettingsController::class, 'index'])->name('settings.security-scans.index');
    Route::patch('/settings/security-scans', [SecurityScansSettingsController::class, 'update'])->name('settings.security-scans.update');
    Route::post('/settings/security-scans/run-now', [SecurityScansSettingsController::class, 'runNow'])->name('settings.security-scans.runNow');

    // Backup Relay (S3 Glacier IR) settings & on-demand execution
    Route::get('/settings/backup-relay', [BackupRelaySettingsController::class, 'index'])->name('settings.backup-relay.index');
    Route::patch('/settings/backup-relay', [BackupRelaySettingsController::class, 'update'])->name('settings.backup-relay.update');
    Route::post('/settings/backup-relay/run-now', [BackupRelaySettingsController::class, 'runNow'])->name('settings.backup-relay.runNow');
    Route::get('/settings/backup-relay/sites/{site}/archives', [BackupRelaySettingsController::class, 'archives'])->name('settings.backup-relay.archives');
    Route::get('/settings/backup-relay/sites/{site}/download', [BackupRelaySettingsController::class, 'download'])->name('settings.backup-relay.download');

    // Care Plans fleet policy settings
    Route::get('/settings/care-plans', [CarePlanSettingsController::class, 'index'])->name('settings.care-plans.index');
    Route::patch('/settings/care-plans', [CarePlanSettingsController::class, 'update'])->name('settings.care-plans.update');

    // Mattermost and Slack per-event opt-out routes moved to
    // modules/Mattermost/routes/web.php and modules/Slack/routes/web.php.
    // Bill.com routes moved to Modules\BillCom\BillComServiceProvider.

    // API credentials for every provider client — DB-backed (encrypted),
    // .env stays the permanent fallback.
    Route::get('/settings/integrations', [IntegrationCredentialsController::class, 'index'])->name('settings.integrations.index');
    Route::patch('/settings/integrations', [IntegrationCredentialsController::class, 'update'])->name('settings.integrations.update');
    Route::post('/settings/integrations/{integration}/test', [IntegrationCredentialsController::class, 'test'])->name('settings.integrations.test');

    // API limits, rate limiting documentation, and connection tuning per integration.
    Route::get('/settings/integrations/{service}/limits', [ServiceApiLimitsController::class, 'show'])->name('settings.integrations.limits');
    Route::patch('/settings/integrations/{service}/limits', [ServiceApiLimitsController::class, 'update'])->name('settings.integrations.limits.update');
    Route::post('/settings/integrations/{service}/limits/reset', [ServiceApiLimitsController::class, 'reset'])->name('settings.integrations.limits.reset');
    Route::post('/settings/integrations/{service}/credentials/{field}/remove', [ServiceApiLimitsController::class, 'removeCredential'])->name('settings.integrations.credentials.remove');
    Route::post('/settings/integrations/{service}/reconcile', [ServiceApiLimitsController::class, 'reconcile'])->name('settings.integrations.reconcile');
    Route::post('/settings/integrations/{service}/import-instance', [ServiceApiLimitsController::class, 'importInstance'])->name('settings.integrations.importInstance');

    // Module Directory (official feed & community ecosystem browser)
    Route::get('/settings/modules', [ModuleDirectoryController::class, 'index'])->name('settings.modules.index');
    Route::post('/settings/modules/refresh', [ModuleDirectoryController::class, 'refresh'])->name('settings.modules.refresh');

    Route::get('/settings/weird-stats', [WeirdStatsController::class, 'index'])->name('settings.weird-stats.index');

    // Operator-triggered system updates hub (WordPress-style Core + Companion + Modules).
    Route::get('/settings/updates', [SystemUpdatesController::class, 'index'])->name('settings.updates.index');
    Route::post('/settings/updates/check', [SystemUpdatesController::class, 'check'])->name('settings.updates.check');
    Route::post('/settings/updates/apply', [SystemUpdatesController::class, 'apply'])
        ->middleware('admin')
        ->name('settings.updates.apply');

    // Maintenance utilities. Today: on-demand DB backup (admin-only, gzipped mysqldump
    // streamed to browser, never written server-side) and telemetry settings.
    Route::get('/settings/maintenance', [MaintenanceController::class, 'index'])->name('settings.maintenance.index');
    Route::get('/settings/maintenance/backup', [MaintenanceController::class, 'downloadBackup'])
        ->middleware('admin')
        ->name('settings.maintenance.backup');
    Route::patch('/settings/maintenance/telemetry', [MaintenanceController::class, 'updateTelemetry'])->name('settings.maintenance.telemetry.update');
    Route::post('/settings/maintenance/telemetry/send-now', [MaintenanceController::class, 'sendTelemetryNow'])->name('settings.maintenance.telemetry.sendNow');

    // Diagnostics — connectivity check across every external integration the app
    // uses. Read-only on every check; mutation actions (e.g. send-test-email)
    // would live behind their own explicit endpoints.
    Route::get('/settings/diagnostics', [DiagnosticsController::class, 'index'])->name('settings.diagnostics.index');

    // SMS notifications — recipient + off-window CRUD; site-down/up alerts via Twilio
    Route::get('/settings/notifications', [NotificationSettingsController::class, 'index'])->name('settings.notifications.index');
    Route::post('/settings/notifications/recipients', [NotificationSettingsController::class, 'storeRecipient'])->name('settings.notifications.recipients.store');
    Route::patch('/settings/notifications/recipients/{recipient}', [NotificationSettingsController::class, 'updateRecipient'])->name('settings.notifications.recipients.update');
    Route::delete('/settings/notifications/recipients/{recipient}', [NotificationSettingsController::class, 'destroyRecipient'])->name('settings.notifications.recipients.destroy');
    Route::post('/settings/notifications/recipients/{recipient}/test', [NotificationSettingsController::class, 'testRecipient'])->name('settings.notifications.recipients.test');
    Route::post('/settings/notifications/recipients/{recipient}/windows', [NotificationSettingsController::class, 'storeOffWindow'])->name('settings.notifications.windows.store');
    Route::patch('/settings/notifications/windows/{window}', [NotificationSettingsController::class, 'updateOffWindow'])->name('settings.notifications.windows.update');
    Route::delete('/settings/notifications/windows/{window}', [NotificationSettingsController::class, 'destroyOffWindow'])->name('settings.notifications.windows.destroy');

    // Settings — server tags
    Route::get('/settings/tags', [TagsController::class, 'index'])->name('settings.tags.index');
    Route::post('/settings/tags', [TagsController::class, 'store'])->name('settings.tags.store');
    Route::patch('/settings/tags/{tag}', [TagsController::class, 'update'])->name('settings.tags.update');
    Route::delete('/settings/tags/{tag}', [TagsController::class, 'destroy'])->name('settings.tags.destroy');

    // Per-server tag assignment
    Route::patch('/servers/{server}/tags', [TagsController::class, 'syncServer'])->name('servers.tags.sync');

    // blocked-ips.index GET is now a 301 redirect declared next to the /bans routes above.
    // Mutation endpoints stay where they are — forms in dashboard.bans._tab-active post here.
    Route::post('/blocked-ips/{blockedIp}/unban', [BlockedIpsController::class, 'unban'])
        ->name('blocked-ips.unban');

}); // end auth-gated group
