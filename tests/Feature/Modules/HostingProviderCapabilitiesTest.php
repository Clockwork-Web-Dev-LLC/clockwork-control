<?php

namespace Tests\Feature\Modules;

use App\Models\Site;
use App\Services\HostingProvider\CustomHostingProvider;
use App\Services\HostingProvider\HostingProviderRegistry;
use Modules\BackupRelay\Services\CompanionBackupRelayAdapter;
use Modules\Cloudways\CloudwaysHostingProvider;
use Modules\Core\Contracts\DirectS3BackupRelayAdapter;
use Modules\Core\Contracts\HostingProvider;
use Modules\Core\Contracts\SiteCommandRunner;
use Modules\GridPane\GridPaneHostingProvider;
use Modules\Kinsta\KinstaHostingProvider;
use Modules\Pressable\PressableApiCommandRunner;
use Modules\Pressable\PressableHostingProvider;
use Modules\SpinupWp\SpinupWpHostingProvider;
use Modules\WPEngine\WPEngineHostingProvider;

/**
 * Pins the real HostingProvider capability matrix (HostingProvider::CAP_*)
 * for every registered adapter, resolved the same way app code resolves
 * them — via Site::host() -> HostingProviderRegistry::resolve() — rather
 * than instantiating the adapter classes directly. A regression here means
 * a supports() check somewhere in the app (SSL sync, orphan detection,
 * performance scans, Companion install, ...) would silently start gating
 * the wrong provider.
 */
describe('HostingProvider capability matrix', function () {
    it('reports SpinupWP capabilities: ssh, server linkage, cert sync, orphan detection, and companion true; performance scan false', function () {
        $host = Site::factory()->spinupwp()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeTrue();
    });

    it('reports Pressable capabilities: performance scan, orphan detection, and companion true; ssh, server linkage, cert sync false', function () {
        $host = Site::factory()->pressable()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeTrue();
    });

    it('reports WP Engine capabilities: cert sync, orphan detection, and companion true; ssh, server linkage, performance scan false', function () {
        config(['clockwork.wpengine.view_only' => false]);
        $host = Site::factory()->wpEngine()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeTrue();
    });

    it('reports WP Engine capabilities in view-only mode: cert sync and orphan detection true; companion, ssh, server linkage, performance scan false', function () {
        config(['clockwork.wpengine.view_only' => true]);
        $host = Site::factory()->wpEngine()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeFalse()
            ->and($host->companionInstaller())->toBeNull()
            ->and($host->commandRunner())->toBeNull();
    });

    it('reports Kinsta capabilities: orphan detection and companion true; ssh, server linkage, cert sync, performance scan false', function () {
        config(['clockwork.kinsta.view_only' => false]);
        // CAP_SSH is false despite Kinsta having real per-environment SSH —
        // its own contract docblock ties CAP_SSH specifically to server_id
        // being populated, which Kinsta sites never have (same shape as WP
        // Engine). See KinstaHostingProvider::supports()'s docblock.
        $host = Site::factory()->kinsta()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeTrue();
    });

    it('reports Kinsta capabilities in view-only mode: orphan detection true; companion, ssh, server linkage, cert sync, performance scan false', function () {
        config(['clockwork.kinsta.view_only' => true]);
        $host = Site::factory()->kinsta()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeFalse()
            ->and($host->companionInstaller())->toBeNull()
            ->and($host->commandRunner())->toBeNull();
    });

    it('reports Cloudways capabilities: ssh, server linkage, cert sync, orphan detection, and companion true; performance scan false', function () {
        config(['clockwork.cloudways.view_only' => false]);
        $host = Site::factory()->cloudways()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeTrue();
    });

    it('reports Cloudways capabilities in view-only mode: server linkage, cert sync, orphan detection true; ssh, companion, performance scan false', function () {
        config(['clockwork.cloudways.view_only' => true]);
        $host = Site::factory()->cloudways()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeFalse()
            ->and($host->companionInstaller())->toBeNull()
            ->and($host->commandRunner())->toBeNull();
    });

    it('reports SpinupWP capabilities in view-only mode: server linkage, cert sync, orphan detection true; ssh, companion, performance scan false', function () {
        config(['clockwork.spinupwp.view_only' => true]);
        $host = Site::factory()->spinupwp()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeFalse()
            ->and($host->companionInstaller())->toBeNull()
            ->and($host->commandRunner())->toBeNull();
    });

    it('reports Pressable capabilities in view-only mode: performance scan, orphan detection true; companion, ssh, server linkage, cert sync false', function () {
        config(['clockwork.pressable.view_only' => true]);
        $host = Site::factory()->pressable()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeFalse()
            ->and($host->companionInstaller())->toBeNull()
            ->and($host->commandRunner())->toBeNull();
    });

    it('reports GridPane capabilities: ssh, server linkage, cert sync, orphan detection, and companion true; performance scan false', function () {
        config(['clockwork.gridpane.view_only' => false]);
        $host = Site::factory()->gridpane()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeTrue();
    });

    it('reports GridPane capabilities in view-only mode: server linkage, cert sync, orphan detection true; ssh, companion, performance scan false', function () {
        config(['clockwork.gridpane.view_only' => true]);
        $host = Site::factory()->gridpane()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeFalse()
            ->and($host->companionInstaller())->toBeNull()
            ->and($host->commandRunner())->toBeNull();
    });

    it('resolves the SpinupWP adapter via the registry with the right id and label', function () {
        $host = Site::factory()->spinupwp()->create()->host();

        expect($host)->toBeInstanceOf(SpinupWpHostingProvider::class)
            ->and($host->id())->toBe(Site::HOSTING_PROVIDER_SPINUPWP)
            ->and($host->label())->toBe('SpinupWP');
    });

    it('resolves the Pressable adapter via the registry with the right id and label', function () {
        $host = Site::factory()->pressable()->create()->host();

        expect($host)->toBeInstanceOf(PressableHostingProvider::class)
            ->and($host->id())->toBe(Site::HOSTING_PROVIDER_PRESSABLE)
            ->and($host->label())->toBe('Pressable');
    });

    it('resolves the WP Engine adapter via the registry with the right id and label', function () {
        $host = Site::factory()->wpEngine()->create()->host();

        expect($host)->toBeInstanceOf(WPEngineHostingProvider::class)
            ->and($host->id())->toBe(Site::HOSTING_PROVIDER_WPENGINE)
            ->and($host->label())->toBe('WP Engine');
    });

    it('resolves the Kinsta adapter via the registry with the right id and label', function () {
        $host = Site::factory()->kinsta()->create()->host();

        expect($host)->toBeInstanceOf(KinstaHostingProvider::class)
            ->and($host->id())->toBe(Site::HOSTING_PROVIDER_KINSTA)
            ->and($host->label())->toBe('Kinsta');
    });

    it('resolves the Cloudways adapter via the registry with the right id and label', function () {
        $host = Site::factory()->cloudways()->create()->host();

        expect($host)->toBeInstanceOf(CloudwaysHostingProvider::class)
            ->and($host->id())->toBe(Site::HOSTING_PROVIDER_CLOUDWAYS)
            ->and($host->label())->toBe('Cloudways');
    });

    it('resolves the GridPane adapter via the registry with the right id and label', function () {
        $host = Site::factory()->gridpane()->create()->host();

        expect($host)->toBeInstanceOf(GridPaneHostingProvider::class)
            ->and($host->id())->toBe(Site::HOSTING_PROVIDER_GRIDPANE)
            ->and($host->label())->toBe('GridPane');
    });

    it('resolves the Custom adapter via the registry with the right id and label', function () {
        $host = Site::factory()->custom()->create()->host();

        expect($host)->toBeInstanceOf(CustomHostingProvider::class)
            ->and($host->id())->toBe(Site::HOSTING_PROVIDER_CUSTOM)
            ->and($host->label())->toBe('Custom / Standalone');
    });

    it('reports the documented capability matrix for CustomHostingProvider', function () {
        $host = Site::factory()->custom()->create()->host();

        expect($host->supports(HostingProvider::CAP_SSH))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_SERVER_LINKAGE))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_CERT_SYNC))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_PERFORMANCE_SCAN))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_ORPHAN_DETECTION))->toBeFalse()
            ->and($host->supports(HostingProvider::CAP_COMPANION))->toBeTrue()
            ->and($host->supports(HostingProvider::CAP_BACKUP_RELAY))->toBeTrue()
            ->and($host->companionInstaller())->toBeNull()
            ->and($host->commandRunner())->toBeNull()
            ->and($host->backupRelayAdapter())->toBeInstanceOf(CompanionBackupRelayAdapter::class)
            ->and($host->backupRelayAdapter())->toBeInstanceOf(DirectS3BackupRelayAdapter::class);
    });

    it('gives SpinupWP a non-null companionInstaller() and commandRunner() (SSH transport)', function () {
        $host = Site::factory()->spinupwp()->create()->host();

        expect($host->companionInstaller())->not->toBeNull()
            ->and($host->commandRunner())->not->toBeNull()
            ->and($host->commandRunner())->toBeInstanceOf(SiteCommandRunner::class);
    });

    it('gives GridPane a non-null companionInstaller() and commandRunner() (SSH transport) when not view-only', function () {
        config(['clockwork.gridpane.view_only' => false]);
        $host = Site::factory()->gridpane()->create()->host();

        expect($host->companionInstaller())->not->toBeNull()
            ->and($host->commandRunner())->not->toBeNull()
            ->and($host->commandRunner())->toBeInstanceOf(SiteCommandRunner::class);
    });

    it('gives GridPane a null companionInstaller() and commandRunner() when in view-only mode', function () {
        config(['clockwork.gridpane.view_only' => true]);
        $host = Site::factory()->gridpane()->create()->host();

        expect($host->companionInstaller())->toBeNull()
            ->and($host->commandRunner())->toBeNull();
    });

    it('gives Pressable a non-null companionInstaller() and a non-null commandRunner() despite CAP_SSH being false', function () {
        $host = Site::factory()->pressable()->create()->host();

        // Pressable has no SSH (CAP_SSH is false), but it still exposes a
        // command-execution transport over its own API rather than SSH — so
        // commandRunner() must NOT be null here. Verified directly against
        // PressableHostingProvider::commandRunner(), which returns the
        // PressableApiCommandRunner injected in its constructor.
        expect($host->companionInstaller())->not->toBeNull()
            ->and($host->commandRunner())->not->toBeNull()
            ->and($host->commandRunner())->toBeInstanceOf(PressableApiCommandRunner::class);
    });

    it('gives WP Engine and Kinsta non-null companionInstaller()/commandRunner() over their own per-site SSH transports when not view-only', function () {
        config([
            'clockwork.wpengine.view_only' => false,
            'clockwork.kinsta.view_only' => false,
        ]);
        // Both have real per-site SSH (unlike Pressable) but no Server row
        // (unlike SpinupWP) — a third shape, each with its own dedicated
        // SiteCommandRunner/CompanionInstaller pair rather than reusing
        // either existing one.
        $wpEngine = Site::factory()->wpEngine()->create()->host();
        expect($wpEngine->companionInstaller())->not->toBeNull()
            ->and($wpEngine->commandRunner())->not->toBeNull()
            ->and($wpEngine->commandRunner())->toBeInstanceOf(SiteCommandRunner::class);

        $kinsta = Site::factory()->kinsta()->create()->host();
        expect($kinsta->companionInstaller())->not->toBeNull()
            ->and($kinsta->commandRunner())->not->toBeNull()
            ->and($kinsta->commandRunner())->toBeInstanceOf(SiteCommandRunner::class);
    });

    it('gives WP Engine and Kinsta null companionInstaller()/commandRunner() in view-only mode', function () {
        config([
            'clockwork.wpengine.view_only' => true,
            'clockwork.kinsta.view_only' => true,
        ]);

        $wpEngine = Site::factory()->wpEngine()->create()->host();
        expect($wpEngine->companionInstaller())->toBeNull()
            ->and($wpEngine->commandRunner())->toBeNull();

        $kinsta = Site::factory()->kinsta()->create()->host();
        expect($kinsta->companionInstaller())->toBeNull()
            ->and($kinsta->commandRunner())->toBeNull();
    });

    it('registers exactly the 7 real hosting providers via HostingProviderRegistry::all()', function () {
        $ids = collect(app(HostingProviderRegistry::class)->all())
            ->map(fn (HostingProvider $provider) => $provider->id())
            ->sort()
            ->values()
            ->all();

        expect($ids)->toBe([
            Site::HOSTING_PROVIDER_CLOUDWAYS,
            Site::HOSTING_PROVIDER_CUSTOM,
            Site::HOSTING_PROVIDER_GRIDPANE,
            Site::HOSTING_PROVIDER_KINSTA,
            Site::HOSTING_PROVIDER_PRESSABLE,
            Site::HOSTING_PROVIDER_SPINUPWP,
            Site::HOSTING_PROVIDER_WPENGINE,
        ]);
    });
});
