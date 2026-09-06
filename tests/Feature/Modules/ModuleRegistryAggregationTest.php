<?php

use App\Services\Diagnostics\Checks\GitHubOAuthCheck;
use App\Services\Diagnostics\Checks\GoogleOAuthCheck;
use App\Services\Diagnostics\Checks\MicrosoftOAuthCheck;
use Modules\Azure\AzureCheck;
use Modules\Azure\AzureCloudProvider;
use Modules\BillCom\BillComCheck;
use Modules\Cloudways\CloudwaysCheck;
use Modules\Cloudways\CloudwaysCloudProvider;
use Modules\Cloudways\CloudwaysHostingProvider;
use Modules\Core\ModuleRegistry;
use Modules\DigitalOcean\DigitalOceanCheck;
use Modules\DigitalOcean\DigitalOceanCloudProvider;
use Modules\GridPane\GridPaneCheck;
use Modules\GridPane\GridPaneHostingProvider;
use Modules\Hetzner\HetznerCheck;
use Modules\Hetzner\HetznerCloudProvider;
use Modules\Kinsta\KinstaCheck;
use Modules\Kinsta\KinstaHostingProvider;
use Modules\Linode\LinodeCheck;
use Modules\Linode\LinodeCloudProvider;
use Modules\Mattermost\MattermostCheck;
use Modules\Pressable\PressableCheck;
use Modules\Pressable\PressableHostingProvider;
use Modules\Slack\SlackCheck;
use Modules\SpinupWp\SpinupWpCheck;
use Modules\SpinupWp\SpinupWpHostingProvider;
use Modules\Twilio\TwilioCheck;
use Modules\Vultr\VultrCheck;
use Modules\Vultr\VultrCloudProvider;
use Modules\WPEngine\WPEngineCheck;
use Modules\WPEngine\WPEngineHostingProvider;

/**
 * Exercises the REAL ModuleRegistry singleton as populated by every real
 * ModuleServiceProvider registered in bootstrap/providers.php.
 * Unlike ModuleNavItemsTest, which registers a throwaway fake module to
 * test the wiring mechanism in isolation, this asserts against the actual
 * fleet of modules and their real contributions.
 */
describe('ModuleRegistry aggregation', function () {
    beforeEach(function () {
        $this->registry = app(ModuleRegistry::class);
    });

    it('aggregates a manifest from every one of the 25 registered modules', function () {
        $manifests = $this->registry->manifests();

        expect($manifests)->toHaveCount(25);

        $ids = array_map(fn ($m) => $m->id, $manifests);

        expect($ids)->toEqualCanonicalizing([
            'azure',
            'hetzner',
            'digitalocean',
            'vultr',
            'linode',
            'pressable',
            'spinupwp',
            'wpengine',
            'kinsta',
            'cloudways',
            'gridpane',
            'mattermost',
            'slack',
            'twilio',
            'bill_com',
            'client_slack',
            'gtmetrix',
            'psi',
            'sucuri',
            'llar',
            'contact-forms',
            'backup-relay',
            'auth_google',
            'auth_github',
            'auth_microsoft',
        ]);
    });

    it('collects exactly the 6 cloud providers, each the right concrete class', function () {
        $providers = $this->registry->cloudProviders();

        expect($providers)->toHaveCount(6);

        $classes = array_map(fn ($p) => get_class($p), $providers);

        expect($classes)->toEqualCanonicalizing([
            AzureCloudProvider::class,
            HetznerCloudProvider::class,
            DigitalOceanCloudProvider::class,
            CloudwaysCloudProvider::class,
            VultrCloudProvider::class,
            LinodeCloudProvider::class,
        ]);
    });

    it('collects exactly the 6 hosting providers, each the right concrete class', function () {
        $providers = $this->registry->hostingProviders();

        expect($providers)->toHaveCount(6);

        $classes = array_map(fn ($p) => get_class($p), $providers);

        expect($classes)->toEqualCanonicalizing([
            PressableHostingProvider::class,
            SpinupWpHostingProvider::class,
            WPEngineHostingProvider::class,
            KinstaHostingProvider::class,
            CloudwaysHostingProvider::class,
            GridPaneHostingProvider::class,
        ]);
    });

    it('collects exactly the 3 auth providers, each the right provider name', function () {
        $providers = $this->registry->authProviders();

        expect($providers)->toHaveCount(3);
        $ids = array_map(fn ($p) => $p->id(), $providers);
        expect($ids)->toEqualCanonicalizing(['google', 'github', 'microsoft']);
        $names = array_map(fn ($p) => $p->name(), $providers);
        expect($names)->toEqualCanonicalizing(['Google', 'GitHub', 'Microsoft']);
    });

    it('includes all 18 module-contributed diagnostic checks', function () {
        $checks = $this->registry->diagnosticChecks();

        $classes = array_map(fn ($c) => get_class($c), $checks);

        expect($classes)->toEqualCanonicalizing([
            AzureCheck::class,
            HetznerCheck::class,
            DigitalOceanCheck::class,
            PressableCheck::class,
            SpinupWpCheck::class,
            WPEngineCheck::class,
            KinstaCheck::class,
            CloudwaysCheck::class,
            GridPaneCheck::class,
            MattermostCheck::class,
            SlackCheck::class,
            TwilioCheck::class,
            BillComCheck::class,
            VultrCheck::class,
            LinodeCheck::class,
            GoogleOAuthCheck::class,
            GitHubOAuthCheck::class,
            MicrosoftOAuthCheck::class,
        ]);
    });

    it('looks up a diagnostic check by module id', function () {
        $check = $this->registry->diagnosticCheckFor('bill_com');

        expect($check)->toBeInstanceOf(BillComCheck::class);
    });

    it('returns null for a module id nothing contributes', function () {
        expect($this->registry->diagnosticCheckFor('nonexistent-module-id'))->toBeNull();
    });

    it('includes BillCom\'s real "Bill.com sync" nav item when enabled', function () {
        config(['clockwork.bill_com.enabled' => true]);

        $navItems = $this->registry->navItems();

        $billComItem = collect($navItems)->first(
            fn ($item) => $item->route === 'settings.bill-com.index',
        );

        expect($billComItem)->not->toBeNull();
        expect($billComItem->label)->toBe('Bill.com sync');
        expect($billComItem->icon)->toBe('fa-solid fa-file-invoice-dollar');
    });

    it('omits BillCom\'s nav item when disabled', function () {
        config(['clockwork.bill_com.enabled' => false]);

        $navItems = $this->registry->navItems();

        $billComItem = collect($navItems)->first(
            fn ($item) => $item->route === 'settings.bill-com.index',
        );

        expect($billComItem)->toBeNull();
    });
});
