<?php

use App\Models\ActionLog;
use App\Models\AllowedBot;
use App\Models\AppSetting;
use App\Models\BackupRelayRun;
use App\Models\BlockedIp;
use App\Models\CisaKevEntry;
use App\Models\ContactFormTest;
use App\Models\ContactFormTestRun;
use App\Models\IntegrationCredential;
use App\Models\NginxLogCursor;
use App\Models\NotificationLog;
use App\Models\NotificationOffWindow;
use App\Models\NotificationRecipient;
use App\Models\PluginDirectoryStatus;
use App\Models\PluginUpdateIgnore;
use App\Models\PluginUpdateJob;
use App\Models\PluginVulnerability;
use App\Models\ReviewQueueEntry;
use App\Models\Server;
use App\Models\ServerMetric;
use App\Models\ServerUpdateSnapshot;
use App\Models\Site;
use App\Models\SiteCoreChecksumAllowlist;
use App\Models\SitePerformanceScan;
use App\Models\SiteSecurityScan;
use App\Models\SiteTrafficDaily;
use App\Models\SiteUptimeEvent;
use App\Models\Tag;
use App\Models\ThreatLog;
use App\Models\User;

/**
 * Every model in app/Models/ must have a working factory — this is the
 * foundation every later behavioral test builds on. One assertion per
 * model, not a loop, so a single broken factory fails with a readable
 * model name instead of a bare index.
 */
describe('every model factory round-trips a persisted row', function () {
    it('User', fn () => expect(User::factory()->create())->toBeInstanceOf(User::class)->id->not->toBeNull());
    it('Server', fn () => expect(Server::factory()->create())->id->not->toBeNull());
    it('Site (spinupwp)', fn () => expect(Site::factory()->spinupwp()->create())->id->not->toBeNull());
    it('Site (pressable)', fn () => expect(Site::factory()->pressable()->create())->id->not->toBeNull());
    it('ActionLog', fn () => expect(ActionLog::factory()->create())->id->not->toBeNull());
    it('AllowedBot', fn () => expect(AllowedBot::factory()->create())->id->not->toBeNull());
    it('AppSetting', fn () => expect(AppSetting::factory()->create())->id->not->toBeNull());
    it('BackupRelayRun', fn () => expect(BackupRelayRun::factory()->create())->id->not->toBeNull());
    it('BlockedIp', fn () => expect(BlockedIp::factory()->create())->id->not->toBeNull());
    it('CisaKevEntry', fn () => expect(CisaKevEntry::factory()->create())->id->not->toBeNull());
    it('ContactFormTest', fn () => expect(ContactFormTest::factory()->create())->id->not->toBeNull());
    it('ContactFormTestRun', fn () => expect(ContactFormTestRun::factory()->create())->id->not->toBeNull());
    it('IntegrationCredential', fn () => expect(IntegrationCredential::factory()->create())->id->not->toBeNull());
    it('NginxLogCursor', fn () => expect(NginxLogCursor::factory()->create())->id->not->toBeNull());
    it('NotificationLog', fn () => expect(NotificationLog::factory()->create())->id->not->toBeNull());
    it('NotificationRecipient', fn () => expect(NotificationRecipient::factory()->create())->id->not->toBeNull());
    it('NotificationOffWindow', fn () => expect(NotificationOffWindow::factory()->create())->id->not->toBeNull());
    it('PluginDirectoryStatus', fn () => expect(PluginDirectoryStatus::factory()->create())->id->not->toBeNull());
    it('PluginUpdateIgnore', fn () => expect(PluginUpdateIgnore::factory()->create())->id->not->toBeNull());
    it('PluginUpdateJob', fn () => expect(PluginUpdateJob::factory()->create())->id->not->toBeNull());
    it('PluginVulnerability', fn () => expect(PluginVulnerability::factory()->create())->id->not->toBeNull());
    it('ReviewQueueEntry', fn () => expect(ReviewQueueEntry::factory()->create())->id->not->toBeNull());
    it('ServerMetric', fn () => expect(ServerMetric::factory()->create())->id->not->toBeNull());
    it('ServerUpdateSnapshot', fn () => expect(ServerUpdateSnapshot::factory()->create())->id->not->toBeNull());
    it('SiteCoreChecksumAllowlist', fn () => expect(SiteCoreChecksumAllowlist::factory()->create())->id->not->toBeNull());
    it('SitePerformanceScan', fn () => expect(SitePerformanceScan::factory()->create())->id->not->toBeNull());
    it('SiteSecurityScan', fn () => expect(SiteSecurityScan::factory()->create())->id->not->toBeNull());
    it('SiteTrafficDaily', fn () => expect(SiteTrafficDaily::factory()->create())->id->not->toBeNull());
    it('SiteUptimeEvent', fn () => expect(SiteUptimeEvent::factory()->create())->id->not->toBeNull());
    it('Tag', fn () => expect(Tag::factory()->create())->id->not->toBeNull());
    it('ThreatLog', fn () => expect(ThreatLog::factory()->create())->id->not->toBeNull());
});
