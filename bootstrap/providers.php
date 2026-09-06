<?php

use App\Providers\AppServiceProvider;
use App\Providers\IntegrationServiceProvider;
use Modules\AuthGitHub\GitHubAuthServiceProvider;
use Modules\AuthGoogle\GoogleAuthServiceProvider;
use Modules\AuthMicrosoft\MicrosoftAuthServiceProvider;
use Modules\Azure\AzureServiceProvider;
use Modules\BackupRelay\BackupRelayServiceProvider;
use Modules\BillCom\BillComServiceProvider;
use Modules\ClientSlack\ClientSlackServiceProvider;
use Modules\Cloudways\CloudwaysServiceProvider;
use Modules\ContactForms\ContactFormsServiceProvider;
use Modules\Core\CoreServiceProvider;
use Modules\DigitalOcean\DigitalOceanServiceProvider;
use Modules\GridPane\GridPaneServiceProvider;
use Modules\GTmetrix\GTmetrixServiceProvider;
use Modules\Hetzner\HetznerServiceProvider;
use Modules\Kinsta\KinstaServiceProvider;
use Modules\Linode\LinodeServiceProvider;
use Modules\Llar\LlarServiceProvider;
use Modules\Mattermost\MattermostServiceProvider;
use Modules\PageSpeedInsights\PageSpeedInsightsServiceProvider;
use Modules\Pressable\PressableServiceProvider;
use Modules\Slack\SlackServiceProvider;
use Modules\SpinupWp\SpinupWpServiceProvider;
use Modules\Sucuri\SucuriServiceProvider;
use Modules\Twilio\TwilioServiceProvider;
use Modules\Vultr\VultrServiceProvider;
use Modules\WPEngine\WPEngineServiceProvider;

return [
    AppServiceProvider::class,
    IntegrationServiceProvider::class,
    // CoreServiceProvider must precede every module provider below — they
    // resolve ModuleRegistry out of the container during their own
    // register().
    CoreServiceProvider::class,
    AzureServiceProvider::class,
    HetznerServiceProvider::class,
    DigitalOceanServiceProvider::class,
    VultrServiceProvider::class,
    LinodeServiceProvider::class,
    PressableServiceProvider::class,
    SpinupWpServiceProvider::class,
    WPEngineServiceProvider::class,
    KinstaServiceProvider::class,
    // Cloudways implements both HostingProvider and CloudProvider — see
    // CloudwaysServiceProvider's own docblock for why one module needs both.
    CloudwaysServiceProvider::class,
    // GridPane provisions and manages real servers on cloud VPS with full SSH access.
    GridPaneServiceProvider::class,
    // Notification-channel modules — each tags itself into the
    // 'clockwork.notifiers' container tag from its own register(), same
    // mechanism ClientSlackServiceProvider (below) already used before these
    // two had company. Pick any subset; none is required.
    MattermostServiceProvider::class,
    SlackServiceProvider::class,
    TwilioServiceProvider::class,
    // Performance scanning modules (Lighthouse, Core Web Vitals)
    GTmetrixServiceProvider::class,
    PageSpeedInsightsServiceProvider::class,
    // Security scanning & protection modules (ManageWP replacement suite, brute-force defense)
    SucuriServiceProvider::class,
    LlarServiceProvider::class,
    // Maintenance & QA modules (synthetic form deliverability testing, offsite backup relay)
    ContactFormsServiceProvider::class,
    BackupRelayServiceProvider::class,
    // Authentication modules — each contributes an AuthProvider implementation
    // to ModuleRegistry for dynamic sign-in on /login and /setup.
    GoogleAuthServiceProvider::class,
    GitHubAuthServiceProvider::class,
    MicrosoftAuthServiceProvider::class,
    BillComServiceProvider::class,
    ClientSlackServiceProvider::class,
];
