<?php

namespace App\Http\Controllers;

use App\Models\ContactFormTest;
use App\Models\Server;
use App\Models\Site;
use App\Models\SiteSecurityScan;
use App\Support\CredentialResolver;
use App\Support\EnvCredentialManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Modules\Core\InstalledModule;
use Modules\Core\ModuleCatalog;
use Modules\Core\ModuleManifest;
use Modules\Core\ModuleStateResolver;

class SetupController extends Controller
{
    /**
     * @var array<string, array{url: string, guide: string}>
     */
    protected const SERVICE_GUIDES = [
        'digitalocean' => [
            'url' => 'https://cloud.digitalocean.com/account/api/tokens',
            'guide' => 'Personal Access Token with Read & Write scope. Used to query droplet health and network bandwidth.',
        ],
        'hetzner' => [
            'url' => 'https://console.hetzner.cloud/projects',
            'guide' => 'Hetzner Cloud API Token with Read & Write permissions for server metrics and power state.',
        ],
        'vultr' => [
            'url' => 'https://my.vultr.com/settings/#settingsapi',
            'guide' => 'Personal API Key from your Vultr account settings. Enable IPv4/IPv6 access.',
        ],
        'linode' => [
            'url' => 'https://cloud.linode.com/profile/tokens',
            'guide' => 'Personal Access Token with Linodes (Read-only or Read/Write) scope to monitor CPU telemetry.',
        ],
        'azure' => [
            'url' => 'https://portal.azure.com/#view/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/~/RegisteredApps',
            'guide' => 'App Registration credentials: Tenant ID, Client ID, Client Secret, and Azure Subscription ID.',
        ],
        'spinupwp' => [
            'url' => 'https://app.spinupwp.com/settings/api-tokens',
            'guide' => 'SpinupWP API Token to sync server inventory, WordPress sites, and database users.',
        ],
        'pressable' => [
            'url' => 'https://my.pressable.com/api',
            'guide' => 'OAuth Client ID and Client Secret generated in the Pressable account portal.',
        ],
        'wpengine' => [
            'url' => 'https://my.wpengine.com/api_access',
            'guide' => 'API Access Token from your WP Engine User Portal for install sync.',
        ],
        'kinsta' => [
            'url' => 'https://my.kinsta.com/company/api-keys',
            'guide' => 'Company API Key from MyKinsta API Settings to read sites and environments.',
        ],
        'cloudways' => [
            'url' => 'https://platform.cloudways.com/api',
            'guide' => 'Cloudways API Key and account email address for server and site management.',
        ],
        'slack' => [
            'url' => 'https://api.slack.com/apps',
            'guide' => 'Incoming Webhook URL configured in your Slack app or workflow builder.',
        ],
        'mattermost' => [
            'url' => 'https://mattermost.com/docs/guides/administration/integrations/incoming-webhooks/',
            'guide' => 'Incoming Webhook URL from your Mattermost team integrations.',
        ],
        'twilio' => [
            'url' => 'https://console.twilio.com/',
            'guide' => 'Twilio Account SID, Auth Token, and an SMS-capable Twilio phone number for on-call alerts.',
        ],
        'gtmetrix' => [
            'url' => 'https://gtmetrix.com/api/',
            'guide' => 'GTmetrix API Key and Account Email to trigger automated and manual performance tests.',
        ],
        'auth_google' => [
            'url' => 'https://console.cloud.google.com/apis/credentials',
            'guide' => 'Google OAuth 2.0 Web Client ID and Client Secret.',
        ],
        'auth_github' => [
            'url' => 'https://github.com/settings/developers',
            'guide' => 'GitHub OAuth App Client ID and Client Secret.',
        ],
        'auth_microsoft' => [
            'url' => 'https://portal.azure.com/#view/Microsoft_AAD_IAM/ActiveDirectoryMenuBlade/~/RegisteredApps',
            'guide' => 'Microsoft Entra ID (Azure AD) App Registration Client ID, Client Secret, and Tenant ID.',
        ],
        'psi' => [
            'url' => 'https://console.cloud.google.com/apis/credentials',
            'guide' => 'Google PageSpeed Insights API Key from Google Cloud Console (free tier 25k req/day).',
        ],
        'sucuri' => [
            'url' => 'https://sitecheck.sucuri.net/',
            'guide' => 'Sucuri SiteCheck public malware & blacklist scanner (ManageWP replacement suite, zero configuration required).',
        ],
    ];

    /**
     * Backward-compatible alias for route('setup.index')
     */
    public function index(): RedirectResponse|View
    {
        return $this->step1();
    }

    /**
     * Step 1: "Which services are you using?" selection grid
     */
    public function step1(): View
    {
        $bundled = ModuleCatalog::bundled();
        $installed = InstalledModule::all()->keyBy('module_id');

        $categories = [
            'fleet_sources' => [
                'title' => 'Managed WordPress Hosts & Server Management Panels',
                'badge' => 'Fleet Source · Required',
                'badge_color' => 'emerald',
                'description' => 'These integrations discover and synchronize your fleet. Clockwork Control connects directly to your hosting provider APIs to automatically import WordPress sites, staging environments, PHP runtime versions, and server records without manual data entry. You must enable at least one host or control panel for Clockwork Control to have sites to monitor.',
                'is_fleet_source' => true,
                'services' => [],
            ],
            'cloud_vps' => [
                'title' => 'Cloud Infrastructure & VPS',
                'badge' => 'Hardware Telemetry',
                'badge_color' => 'blue',
                'description' => 'The underlying cloud compute providers where your servers and virtual machines run. While managed WordPress hosts handle their own hardware, servers provisioned via SpinupWP or Cloudways require connecting your cloud provider (DigitalOcean, Hetzner, Vultr, Linode, Azure) to poll 5-minute CPU, RAM, disk usage, and hypervisor health telemetry.',
                'is_cloud_vps' => true,
                'services' => [],
            ],
            'authentication' => [
                'title' => 'Authentication & Sign-In',
                'badge' => 'OAuth 2.0 / SSO',
                'badge_color' => 'indigo',
                'description' => 'Single Sign-On (SSO) and OAuth 2.0 identity providers for your team. Enable your organization\'s preferred developer or enterprise identity platform (Google Workspace, Microsoft Entra ID / 365, GitHub) for passwordless operator login, secure role-based access, and two-factor authentication.',
                'services' => [],
            ],
            'performance' => [
                'title' => 'Performance & Speed',
                'badge' => 'ManageWP Suite',
                'badge_color' => 'cyan',
                'description' => 'Automated website speed, Core Web Vitals, and performance testing engine (part of Clockwork Control\'s ManageWP replacement suite). Run scheduled audits and on-demand diagnostic scans using GTmetrix or Google PageSpeed Insights to track TTFB, LCP, CLS, and page weight across your fleet.',
                'services' => [],
            ],
            'security' => [
                'title' => 'Security & Malware Scans',
                'badge' => 'ManageWP Suite',
                'badge_color' => 'rose',
                'description' => 'Fleet-wide website threat intelligence and reputation auditing (part of Clockwork Control\'s ManageWP replacement suite). Performs Sucuri SiteCheck remote malware scans, Google Safe Browsing reputation checks, domain blacklist verification, and core file integrity checks with zero site performance overhead.',
                'services' => [],
            ],
            'maintenance' => [
                'title' => 'Maintenance & QA',
                'badge' => 'Care Plans',
                'badge_color' => 'purple',
                'description' => 'Automated synthetic testing and deliverability QA for client care plans. Probes and exercises live contact forms (Gravity Forms, WPForms, Contact Form 7, Fluent Forms) on your WordPress sites with end-to-end SMTP deliverability verification, streak tracking, and instant failure alerting.',
                'services' => [],
            ],
            'notifications' => [
                'title' => 'Notifications & Dispatch',
                'badge' => 'Team Alerts',
                'badge_color' => 'amber',
                'description' => 'Multi-channel incident routing, dispatch, and on-call alerting. Broadcast high-priority events (site down, CPU spikes, failing contact forms, malware detection, plugin security updates) to Slack, Mattermost, or emergency on-call SMS via Twilio.',
                'services' => [],
            ],
            'misc' => [
                'title' => 'Miscellaneous & Agency Add-ons',
                'badge' => 'Optional',
                'badge_color' => 'slate',
                'description' => 'Billing and agency integration overlays to streamline client reporting, invoicing, and external tool synchronization.',
                'services' => [],
            ],
        ];

        foreach ($bundled as $id => $item) {
            $cat = $item['category'];
            if (in_array($cat, ['managed_hosts', 'control_panels', 'fleet_sources'], true)) {
                $targetCategory = 'fleet_sources';
            } elseif (isset($categories[$cat])) {
                $targetCategory = $cat;
            } else {
                $targetCategory = 'misc';
            }

            $detection = $this->detectInUse($id, $item['manifest']);

            // If explicitly configured in installed_modules, respect the saved toggle.
            // Otherwise, automatically enable services that are currently detected in use.
            $enabled = $installed->has($id)
                ? (bool) $installed->get($id)->enabled
                : $detection['in_use'];

            $categories[$targetCategory]['services'][] = [
                'id' => $id,
                'name' => $item['manifest']->name,
                'description' => $item['manifest']->description,
                'enabled' => $enabled,
                'in_use' => $detection['in_use'],
                'in_use_reason' => $detection['reason'],
                'is_configured' => $detection['is_configured'],
                'field_count' => count($item['manifest']->credentialFields),
                'status' => $item['manifest']->status,
                'status_note' => $item['manifest']->statusNote,
            ];
        }

        // Sort integrations in alphabetical order by name within each category
        foreach ($categories as &$category) {
            usort($category['services'], fn ($a, $b) => strcasecmp($a['name'], $b['name']));
        }
        unset($category);

        return view('setup.step1', [
            'categories' => $categories,
        ]);
    }

    /**
     * Detect whether a service is actively present in the fleet and whether it is configured properly.
     *
     * @return array{in_use: bool, is_configured: bool, reason: ?string}
     */
    protected function detectInUse(string $id, ModuleManifest $manifest): array
    {
        $reasons = [];

        // Check if servers exist for this provider
        $serverCount = Server::where('provider', $id)->count();
        if ($serverCount > 0) {
            $reasons[] = "{$serverCount} ".($serverCount === 1 ? 'server' : 'servers');
        }

        // Check if sites exist for this hosting provider
        $siteCount = Site::where('hosting_provider', $id)->count();
        if ($siteCount > 0) {
            $reasons[] = "{$siteCount} ".($siteCount === 1 ? 'site' : 'sites');
        }

        // Check credentials via EnvCredentialManager (.env)
        $envManager = app(EnvCredentialManager::class);
        $envFields = $envManager->getFieldsForService($id);
        $hasEnvCreds = false;
        foreach ($envFields as $field) {
            if ($field['configured']) {
                $hasEnvCreds = true;
                break;
            }
        }

        // Check credentials via legacy / database CredentialResolver
        $resolver = app(CredentialResolver::class);
        $hasResolverCreds = false;
        foreach (array_keys($manifest->credentialFields) as $key) {
            if (in_array($key, ['base_url', 'view_only'], true)) {
                continue;
            }
            if ($resolver->source("{$id}.{$key}") !== 'unset') {
                $hasResolverCreds = true;
                break;
            }
        }

        // Special checks for webhooks, OAuth, and monitoring services
        if ($id === 'slack' && ((bool) config('clockwork.slack.enabled', false) || ! empty($envManager->getEnvValue('CLOCKWORK_SLACK_WEBHOOK_URL')))) {
            $hasEnvCreds = true;
        }
        if ($id === 'mattermost' && ((bool) config('clockwork.mattermost.enabled', false) || ! empty($envManager->getEnvValue('CLOCKWORK_MATTERMOST_WEBHOOK_URL')))) {
            $hasEnvCreds = true;
        }
        if ($id === 'auth_google' && ((bool) config('services.google.client_id') || ! empty($envManager->getEnvValue('GOOGLE_CLIENT_ID')))) {
            $hasEnvCreds = true;
        }
        if ($id === 'auth_github' && ((bool) config('services.github.client_id') || ! empty($envManager->getEnvValue('GITHUB_CLIENT_ID')))) {
            $hasEnvCreds = true;
        }
        if ($id === 'auth_microsoft' && ((bool) config('services.microsoft.client_id') || (bool) config('services.azure.client_id') || ! empty($envManager->getEnvValue('MICROSOFT_CLIENT_ID')))) {
            $hasEnvCreds = true;
        }
        if ($id === 'psi' && ((bool) config('clockwork.psi.api_key') || ! empty($envManager->getEnvValue('CLOCKWORK_PSI_API_KEY')))) {
            $hasEnvCreds = true;
        }
        $hasActivity = false;
        if ($id === 'sucuri') {
            if (SiteSecurityScan::where('scan_type', 'sitecheck')->exists() || Site::where('care_plan_enabled', true)->exists()) {
                $hasActivity = true;
            }
        }
        if ($id === 'contact-forms') {
            if (ContactFormTest::count() > 0 || Site::where('care_plan_enabled', true)->exists()) {
                $hasActivity = true;
            }
        }
        if ($id === 'llar') {
            if (Site::where('llar_enabled', true)->exists() || Server::where('auto_ban_llar', true)->exists()) {
                $hasActivity = true;
            }
        }

        $requiresCredentials = (count($envFields) > 0) || (count($manifest->credentialFields) > 0);
        $hasCredentials = $hasEnvCreds || $hasResolverCreds;

        // An integration is properly configured if:
        // 1. It requires no credentials at all (e.g. Sucuri public scan, contact form runner)
        // 2. Or it has credentials configured in .env / database
        // 3. Or it already has active servers or sites recorded in the fleet
        // 4. Or it has recorded activity in the fleet
        $isConfigured = ! $requiresCredentials || $hasCredentials || ($serverCount > 0) || ($siteCount > 0) || $hasActivity;

        $inUse = count($reasons) > 0 || $hasCredentials || $hasActivity;

        return [
            'in_use' => $inUse,
            'is_configured' => $isConfigured,
            'reason' => count($reasons) > 0 ? implode(' &middot; ', $reasons) : null,
        ];
    }

    /**
     * Save Step 1 service selections and proceed to Step 2
     */
    public function step1Save(Request $request, ModuleStateResolver $resolver): RedirectResponse
    {
        $bundled = ModuleCatalog::bundled();
        $selected = (array) $request->input('services', []);

        foreach ($bundled as $id => $item) {
            $enabled = in_array($id, $selected, true);

            InstalledModule::updateOrCreate(
                ['module_id' => $id],
                [
                    'name' => $item['manifest']->name,
                    'source' => 'bundled',
                    'enabled' => $enabled,
                    'status' => 'active',
                ]
            );
        }

        $resolver->flush();

        if ($request->input('action') === 'configure' || $request->boolean('step2')) {
            return redirect()->route('setup.step2');
        }

        return redirect()->route('dashboard')->with('status', 'Setup completed! Your active fleet integrations are ready.');
    }

    /**
     * Immediately toggle an integration ON or OFF and persist to installed_modules.
     */
    public function toggleService(Request $request, ModuleStateResolver $resolver): JsonResponse
    {
        $serviceId = (string) $request->input('service');
        $enabled = $request->boolean('enabled');

        $bundled = ModuleCatalog::bundled();
        if (! isset($bundled[$serviceId])) {
            return response()->json([
                'success' => false,
                'message' => "Unknown service: {$serviceId}",
            ], 404);
        }

        $manifest = $bundled[$serviceId]['manifest'];

        InstalledModule::updateOrCreate(
            ['module_id' => $serviceId],
            [
                'name' => $manifest->name,
                'source' => 'bundled',
                'enabled' => $enabled,
                'status' => 'active',
            ]
        );

        $resolver->flush();

        return response()->json([
            'success' => true,
            'service' => $serviceId,
            'enabled' => $enabled,
            'message' => "{$manifest->name} ".($enabled ? 'enabled' : 'disabled').'.',
        ]);
    }

    /**
     * Step 2: Configure API keys and credentials for selected services
     */
    public function step2(): View
    {
        $bundled = ModuleCatalog::bundled();
        $resolver = app(ModuleStateResolver::class);
        $credResolver = app(CredentialResolver::class);

        $configuredServices = [];

        foreach ($bundled as $id => $item) {
            if (! $resolver->isEnabled($id)) {
                continue;
            }

            $manifest = $item['manifest'];
            $fields = [];

            foreach ($manifest->credentialFields as $key => $meta) {
                $path = "{$id}.{$key}";
                $fields[$key] = [
                    'label' => $meta['label'],
                    'secret' => $meta['secret'],
                    'source' => $credResolver->source($path),
                ];
            }

            $configuredServices[$id] = [
                'id' => $id,
                'name' => $manifest->name,
                'description' => $manifest->description,
                'fields' => $fields,
                'guide' => self::SERVICE_GUIDES[$id]['guide'] ?? null,
                'url' => self::SERVICE_GUIDES[$id]['url'] ?? null,
            ];
        }

        return view('setup.step2', [
            'services' => $configuredServices,
        ]);
    }

    /**
     * Save Step 2 API credentials
     */
    public function step2Save(Request $request): RedirectResponse
    {
        $credResolver = app(CredentialResolver::class);
        $bundled = ModuleCatalog::bundled();

        foreach ($bundled as $id => $item) {
            foreach (array_keys($item['manifest']->credentialFields) as $key) {
                $inputName = "value_{$id}_{$key}";
                $clearName = "clear_{$id}_{$key}";
                $path = "{$id}.{$key}";

                if ($request->boolean($clearName)) {
                    $credResolver->forget($path);
                } elseif ($request->filled($inputName)) {
                    $val = (string) $request->input($inputName);
                    $credResolver->put($path, trim($val));
                }
            }
        }

        return redirect()->route('dashboard')->with('status', 'Setup completed! Your active fleet integrations are ready.');
    }
}
