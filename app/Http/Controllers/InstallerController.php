<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnforceInstallerGate;
use App\Installer\InstallerEnvWriter;
use App\Models\IntegrationCredential;
use App\Models\User;
use App\Support\Settings;
use App\Support\UserProvisioner;
use Exception;
use Illuminate\Encryption\Encrypter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use PDO;

class InstallerController extends Controller
{
    public const HOSTING_CATEGORIES = [
        'control_panels' => [
            'name' => 'Server Management Panels',
            'badge' => 'Fleet Source',
            'description' => 'Server control panels for provisioning and managing WordPress on cloud VPS infrastructure.',
            'icon' => 'fa-solid fa-server',
            'providers' => [
                'spinupwp' => [
                    'name' => 'SpinupWP',
                    'icon' => 'fa-solid fa-server',
                    'description' => 'Modern cloud server control panel built specifically for WordPress agencies.',
                ],
                'cloudways' => [
                    'name' => 'Cloudways',
                    'icon' => 'fa-solid fa-cloud',
                    'description' => 'Managed cloud hosting panel across DigitalOcean, Vultr, Linode, AWS, and Google Cloud.',
                ],
                'gridpane' => [
                    'name' => 'GridPane',
                    'icon' => 'fa-solid fa-layer-group',
                    'description' => 'High-performance WordPress hosting control panel on Linux VPS servers.',
                ],
            ],
        ],
        'managed_hosts' => [
            'name' => 'Managed WordPress Hosts',
            'badge' => 'Fleet Source',
            'description' => 'Specialized WordPress hosting platforms with dedicated container and site APIs.',
            'icon' => 'fa-solid fa-cloud',
            'providers' => [
                'pressable' => [
                    'name' => 'Pressable',
                    'icon' => 'fa-solid fa-bolt',
                    'description' => 'Managed WordPress hosting on Automattic WP Cloud enterprise infrastructure.',
                ],
                'wpengine' => [
                    'name' => 'WP Engine',
                    'icon' => 'fa-solid fa-shield-halved',
                    'description' => 'Enterprise WordPress hosting, EverCache caching, and security platform.',
                ],
                'kinsta' => [
                    'name' => 'Kinsta',
                    'icon' => 'fa-solid fa-layer-group',
                    'description' => 'Premium Google Cloud-backed managed WordPress hosting with Edge Caching.',
                ],
            ],
        ],
    ];

    public const HOSTING_PROVIDERS = [
        'spinupwp' => [
            'name' => 'SpinupWP',
            'icon' => 'fa-solid fa-server',
            'description' => 'Modern cloud server control panel built specifically for WordPress agencies.',
            'category' => 'control_panels',
        ],
        'cloudways' => [
            'name' => 'Cloudways',
            'icon' => 'fa-solid fa-cloud',
            'description' => 'Managed cloud hosting on DigitalOcean, Vultr, Linode, AWS, and Google Cloud.',
            'category' => 'control_panels',
        ],
        'gridpane' => [
            'name' => 'GridPane',
            'icon' => 'fa-solid fa-layer-group',
            'description' => 'High-performance WordPress hosting control panel on Linux VPS servers.',
            'category' => 'control_panels',
        ],
        'pressable' => [
            'name' => 'Pressable',
            'icon' => 'fa-solid fa-bolt',
            'description' => 'Managed WordPress hosting on Automattic WP Cloud enterprise infrastructure.',
            'category' => 'managed_hosts',
        ],
        'wpengine' => [
            'name' => 'WP Engine',
            'icon' => 'fa-solid fa-shield-halved',
            'description' => 'Enterprise WordPress hosting, EverCache caching, and security platform.',
            'category' => 'managed_hosts',
        ],
        'kinsta' => [
            'name' => 'Kinsta',
            'icon' => 'fa-solid fa-layer-group',
            'description' => 'Premium Google Cloud-backed managed WordPress hosting with Edge Caching.',
            'category' => 'managed_hosts',
        ],
    ];

    public const CLOUD_PROVIDERS = [
        'digitalocean' => [
            'name' => 'DigitalOcean',
            'desc' => '5-minute CPU, RAM, disk, and bandwidth telemetry via Droplet metrics.',
        ],
        'hetzner' => [
            'name' => 'Hetzner',
            'desc' => 'Hardware telemetry and server power state via Hetzner Cloud API.',
        ],
        'vultr' => [
            'name' => 'Vultr',
            'desc' => 'Vultr Cloud instance hardware metrics and bandwidth monitoring.',
        ],
        'linode' => [
            'name' => 'Linode',
            'desc' => 'Akamai / Linode compute instance CPU and network telemetry.',
        ],
        'azure' => [
            'name' => 'Azure',
            'desc' => 'Microsoft Azure virtual machine hypervisor metrics.',
        ],
    ];

    public function __construct(
        protected UserProvisioner $userProvisioner,
        protected InstallerEnvWriter $envWriter,
    ) {}

    /**
     * Step 1: Welcome & Requirements Check.
     */
    public function welcome(): View|RedirectResponse
    {
        if (EnforceInstallerGate::isInstalled()) {
            return redirect()->route('install.done');
        }

        $phpVersion = PHP_VERSION;
        $phpOk = version_compare($phpVersion, '8.2.0', '>=');

        $extensions = [
            'pdo' => extension_loaded('pdo'),
            'pdo_mysql' => extension_loaded('pdo_mysql') || extension_loaded('pdo_sqlite'),
            'openssl' => extension_loaded('openssl'),
            'mbstring' => extension_loaded('mbstring'),
            'fileinfo' => extension_loaded('fileinfo'),
            'curl' => extension_loaded('curl'),
        ];

        $storagePath = storage_path();
        $cachePath = base_path('bootstrap/cache');

        $permissions = [
            'storage' => is_writable($storagePath),
            'bootstrap/cache' => is_writable($cachePath),
        ];

        $canProceed = $phpOk && ! in_array(false, $extensions, true) && ! in_array(false, $permissions, true);

        return view('install.welcome', [
            'phpVersion' => $phpVersion,
            'phpOk' => $phpOk,
            'extensions' => $extensions,
            'permissions' => $permissions,
            'canProceed' => $canProceed,
            'step' => 1,
        ]);
    }

    /**
     * Step 2: Database Connection Form.
     */
    public function database(Request $request): View
    {
        $saved = (array) $request->session()->get('install.wizard.database', []);

        $data = [
            'host' => ! empty($saved['host']) ? $saved['host'] : (string) config('database.connections.mysql.host', '127.0.0.1'),
            'port' => ! empty($saved['port']) ? (string) $saved['port'] : (string) config('database.connections.mysql.port', 3306),
            'database' => ! empty($saved['database']) ? $saved['database'] : (string) config('database.connections.mysql.database', 'clockwork'),
            'username' => ! empty($saved['username']) ? $saved['username'] : (string) config('database.connections.mysql.username', 'root'),
            'password' => array_key_exists('password', $saved) && $saved['password'] !== null ? (string) $saved['password'] : (string) config('database.connections.mysql.password', ''),
        ];

        return view('install.database', [
            'data' => $data,
            'step' => 2,
        ]);
    }

    /**
     * AJAX Database Connection Test.
     */
    public function testDatabase(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string'],
            'port' => ['required', 'numeric'],
            'database' => ['required', 'string'],
            'username' => ['required', 'string'],
            'password' => ['nullable', 'string'],
        ]);

        $host = $validated['host'];
        $port = (int) $validated['port'];
        $database = $validated['database'];
        $username = $validated['username'];
        $password = $validated['password'] ?? '';

        try {
            $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 3,
            ];

            new PDO($dsn, $username, $password, $options);

            return response()->json([
                'ok' => true,
                'message' => "Successfully connected to MySQL database [{$database}] on [{$host}:{$port}].",
            ]);
        } catch (Exception $e) {
            Log::warning('installer.database_test_failed', ['message' => $e->getMessage()]);

            return response()->json([
                'ok' => false,
                'message' => 'Connection failed. Check the host, port, database name, and credentials, then try again.',
            ], 422);
        }
    }

    /**
     * Save Step 2 Database configuration into session.
     */
    public function saveDatabase(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string'],
            'port' => ['required', 'numeric'],
            'database' => ['required', 'string'],
            'username' => ['required', 'string'],
            'password' => ['nullable', 'string'],
        ]);

        $request->session()->put('install.wizard.database', $validated);

        return redirect()->route('install.app');
    }

    /**
     * Step 3: App Identity.
     */
    public function app(Request $request): View
    {
        $saved = (array) $request->session()->get('install.wizard.app', []);

        $data = [
            'name' => ! empty($saved['name']) ? $saved['name'] : (string) config('app.name', 'Clockwork Control'),
            'url' => ! empty($saved['url']) ? $saved['url'] : (string) config('app.url', $request->getSchemeAndHttpHost()),
            'timezone' => ! empty($saved['timezone']) ? $saved['timezone'] : (string) config('app.timezone', 'UTC'),
        ];

        return view('install.app', [
            'data' => $data,
            'timezones' => timezone_identifiers_list(),
            'step' => 3,
        ]);
    }

    public function saveApp(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'url' => ['required', 'url'],
            'timezone' => ['required', 'timezone'],
        ]);

        $request->session()->put('install.wizard.app', $validated);

        return redirect()->route('install.mail');
    }

    /**
     * Step 4: Mail Configuration (Optional).
     */
    public function mail(Request $request): View
    {
        $saved = (array) $request->session()->get('install.wizard.mail', []);
        $envMailer = (string) config('mail.default', 'smtp');
        $mailer = ! empty($saved['mailer']) ? $saved['mailer'] : ($envMailer ?: 'smtp');
        $isSkipped = isset($saved['skipped']) ? (bool) $saved['skipped'] : ($mailer === 'log' || $mailer === 'array');

        $data = [
            'mailer' => $mailer,
            'host' => ! empty($saved['host']) ? $saved['host'] : (string) config('mail.mailers.smtp.host', '127.0.0.1'),
            'port' => ! empty($saved['port']) ? (string) $saved['port'] : (string) config('mail.mailers.smtp.port', 587),
            'username' => ! empty($saved['username']) ? $saved['username'] : (string) config('mail.mailers.smtp.username', ''),
            'password' => ! empty($saved['password']) ? $saved['password'] : (string) config('mail.mailers.smtp.password', ''),
            'from_address' => ! empty($saved['from_address']) ? $saved['from_address'] : (string) config('mail.from.address', 'hello@example.com'),
            'from_name' => ! empty($saved['from_name']) ? $saved['from_name'] : (string) config('mail.from.name', 'Clockwork Control'),
            'skipped' => $isSkipped,
        ];

        return view('install.mail', [
            'data' => $data,
            'step' => 4,
        ]);
    }

    public function testMail(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'host' => ['required', 'string'],
            'port' => ['required', 'numeric'],
        ]);

        $host = $validated['host'];
        $port = (int) $validated['port'];

        $fp = @fsockopen($host, $port, $errno, $errstr, 4);

        if ($fp) {
            fclose($fp);

            return response()->json([
                'ok' => true,
                'message' => "Successfully reached SMTP server on [{$host}:{$port}].",
            ]);
        }

        return response()->json([
            'ok' => false,
            'message' => "Could not connect to SMTP server: {$errstr} ({$errno})",
        ], 422);
    }

    public function saveMail(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'mailer' => ['required', 'string'],
            'host' => ['required', 'string'],
            'port' => ['required', 'numeric'],
            'username' => ['nullable', 'string'],
            'password' => ['nullable', 'string'],
            'from_address' => ['required', 'email'],
            'from_name' => ['required', 'string'],
        ]);

        $validated['skipped'] = false;
        $request->session()->put('install.wizard.mail', $validated);

        return redirect()->route('install.google');
    }

    public function skipMail(Request $request): RedirectResponse
    {
        $request->session()->put('install.wizard.mail', [
            'skipped' => true,
            'mailer' => 'log',
        ]);

        return redirect()->route('install.google');
    }

    /**
     * Step 5: Google OAuth (Required).
     */
    public function google(Request $request): View
    {
        $saved = (array) $request->session()->get('install.wizard.google', []);

        $clientId = ! empty($saved['client_id']) ? $saved['client_id'] : (string) config('services.google.client_id', '');
        $clientSecret = ! empty($saved['client_secret']) ? $saved['client_secret'] : (string) config('services.google.client_secret', '');
        $hd = ! empty($saved['hd']) ? $saved['hd'] : (string) config('services.google.hosted_domain', '');

        $data = [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'hd' => $hd,
        ];

        $appUrl = $request->session()->get('install.wizard.app.url', config('app.url', $request->getSchemeAndHttpHost()));
        $redirectUri = rtrim((string) $appUrl, '/').'/auth/google/callback';

        return view('install.google', [
            'data' => $data,
            'redirectUri' => $redirectUri,
            'step' => 5,
        ]);
    }

    public function saveGoogle(Request $request): RedirectResponse
    {
        // If credentials are completely blank, treat as skip
        if (empty($request->input('client_id')) && empty($request->input('client_secret'))) {
            return $this->skipGoogle($request);
        }

        $validated = $request->validate([
            'client_id' => ['required', 'string'],
            'client_secret' => ['required', 'string'],
            'hd' => ['nullable', 'string'],
        ]);

        $validated['skipped'] = false;
        $request->session()->put('install.wizard.google', $validated);

        return redirect()->route('install.admin');
    }

    public function skipGoogle(Request $request): RedirectResponse
    {
        $request->session()->put('install.wizard.google', [
            'skipped' => true,
            'client_id' => '',
            'client_secret' => '',
            'hd' => '',
        ]);

        return redirect()->route('install.admin');
    }

    /**
     * Step 6: First Admin User.
     */
    public function admin(Request $request): View
    {
        $existingAdmin = null;
        try {
            $existingAdmin = User::query()->whereNull('revoked_at')->first();
        } catch (\Throwable) {
        }

        $saved = (array) $request->session()->get('install.wizard.admin', []);
        $google = (array) $request->session()->get('install.wizard.google', []);
        $isGoogleConfigured = ! empty($google['client_id']) && empty($google['skipped']);

        $email = ! empty($saved['email']) ? $saved['email'] : ($existingAdmin instanceof User ? $existingAdmin->email : (string) config('mail.from.address', ''));
        $name = ! empty($saved['name']) ? $saved['name'] : ($existingAdmin instanceof User ? $existingAdmin->name : (string) config('mail.from.name', ''));

        $data = [
            'email' => $email,
            'name' => $name,
        ];

        return view('install.admin', [
            'data' => $data,
            'isGoogleConfigured' => $isGoogleConfigured,
            'step' => 6,
        ]);
    }

    public function saveAdmin(Request $request): RedirectResponse
    {
        $google = (array) $request->session()->get('install.wizard.google', []);
        $isGoogleConfigured = ! empty($google['client_id']) && empty($google['skipped']);

        $passwordRules = $isGoogleConfigured
            ? ['nullable', 'string', 'min:8', 'confirmed']
            : ['required', 'string', 'min:8', 'confirmed'];

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'name' => ['required', 'string', 'max:100'],
            'password' => $passwordRules,
        ]);

        $request->session()->put('install.wizard.admin', [
            'email' => $validated['email'],
            'name' => $validated['name'],
            'password' => ! empty($validated['password']) ? $validated['password'] : null,
        ]);

        return redirect()->route('install.hosting');
    }

    /**
     * Step 7: Hosting Provider Quick-Connect.
     */
    public function hosting(Request $request): View
    {
        $saved = (array) $request->session()->get('install.wizard.hosting', []);

        $selected = $saved['providers'] ?? null;
        if ($selected === null && isset($saved['provider'])) {
            $selected = $saved['provider'] === 'skip' ? [] : [$saved['provider']];
        }

        if ($selected === null) {
            $selected = [];
            try {
                $configuredIntegrations = IntegrationCredential::query()
                    ->pluck('integration')
                    ->toArray();

                foreach (array_keys(self::HOSTING_PROVIDERS) as $providerKey) {
                    if (in_array($providerKey, $configuredIntegrations, true)) {
                        $selected[] = $providerKey;
                    }
                }
            } catch (\Throwable) {
            }

            if (empty($selected)) {
                $selected = ['spinupwp'];
            }
        }

        return view('install.hosting', [
            'categories' => self::HOSTING_CATEGORIES,
            'providers' => self::HOSTING_PROVIDERS,
            'selected' => (array) $selected,
            'step' => 7,
        ]);
    }

    public function saveHosting(Request $request): RedirectResponse
    {
        $providers = $request->input('providers', []);
        if (is_string($providers)) {
            $providers = [$providers];
        }

        if (empty($providers) && $request->has('provider')) {
            $single = (string) $request->input('provider');
            $providers = $single === 'skip' ? [] : [$single];
        }

        $validKeys = array_keys(self::HOSTING_PROVIDERS);
        $selected = array_values(array_intersect((array) $providers, $validKeys));

        if (empty($selected)) {
            $selected = ['skip'];
        }

        $request->session()->put('install.wizard.hosting', [
            'providers' => $selected,
            'provider' => $selected[0] ?? 'skip',
        ]);

        return redirect()->route('install.vps');
    }

    /**
     * Step 7b: Skip hosting quick-connect.
     */
    public function skipHosting(Request $request): RedirectResponse
    {
        $request->session()->put('install.wizard.hosting', [
            'providers' => ['skip'],
            'provider' => 'skip',
        ]);

        return redirect()->route('install.vps');
    }

    /**
     * Step 8: Cloud Infrastructure & VPS (Optional).
     */
    public function vps(Request $request): View
    {
        $saved = (array) $request->session()->get('install.wizard.vps', []);

        $selected = $saved['providers'] ?? null;
        if ($selected === null && isset($saved['provider'])) {
            $selected = $saved['provider'] === 'skip' ? [] : [$saved['provider']];
        }

        if ($selected === null) {
            $selected = [];
            try {
                $configuredIntegrations = IntegrationCredential::query()
                    ->pluck('integration')
                    ->toArray();

                foreach (array_keys(self::CLOUD_PROVIDERS) as $providerKey) {
                    if (in_array($providerKey, $configuredIntegrations, true)) {
                        $selected[] = $providerKey;
                    }
                }
            } catch (\Throwable) {
            }
        }

        return view('install.vps', [
            'providers' => self::CLOUD_PROVIDERS,
            'selected' => (array) $selected,
            'step' => 8,
        ]);
    }

    public function saveVps(Request $request): RedirectResponse
    {
        $providers = $request->input('providers', []);
        if (is_string($providers)) {
            $providers = [$providers];
        }

        if (empty($providers) && $request->has('provider')) {
            $single = (string) $request->input('provider');
            $providers = $single === 'skip' ? [] : [$single];
        }

        $validKeys = array_keys(self::CLOUD_PROVIDERS);
        $selected = array_values(array_intersect((array) $providers, $validKeys));

        if (empty($selected)) {
            $selected = ['skip'];
        }

        $request->session()->put('install.wizard.vps', [
            'providers' => $selected,
            'provider' => $selected[0] ?? 'skip',
        ]);

        return redirect()->route('install.review');
    }

    /**
     * Step 8b: Skip cloud infrastructure & VPS.
     */
    public function skipVps(Request $request): RedirectResponse
    {
        $request->session()->put('install.wizard.vps', [
            'providers' => ['skip'],
            'provider' => 'skip',
        ]);

        return redirect()->route('install.review');
    }

    /**
     * Unlock an existing installation immediately and redirect to login.
     */
    public function unlockExisting(Request $request): RedirectResponse
    {
        // Re-verify server-side rather than trusting the client-side button visibility check —
        // without this, any POST here (CSRF token trivially obtainable from any /install/* page
        // load) permanently seals the installer even against a completely unconfigured app, with
        // no DB/admin ever provisioned and no way back in short of the CLI reopen command.
        if (! EnforceInstallerGate::hasExistingDatabase()) {
            return redirect()->route('install.welcome')->with(
                'error',
                'No existing installation was found to unlock. Continue the setup wizard below.'
            );
        }

        $admin = null;
        try {
            $admin = User::query()->whereNull('revoked_at')->first();
        } catch (\Throwable) {
        }

        $sentinelData = [
            'installed_at' => now()->toIso8601String(),
            'version' => config('clockwork.version', '1.1.0'),
            'admin_email' => $admin instanceof User ? $admin->email : (string) config('mail.from.address', 'admin@example.com'),
            'hosting_intent' => 'skip',
            'unlocked_from_existing' => true,
        ];

        file_put_contents(
            EnforceInstallerGate::sentinelPath(),
            json_encode($sentinelData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        @unlink(storage_path('installer_reopened'));

        return redirect()->route('login');
    }

    /**
     * Step 8: Review & Run Installation.
     */
    public function review(Request $request): View|RedirectResponse
    {
        $wizard = (array) $request->session()->get('install.wizard', []);

        if (empty($wizard['database'])) {
            $wizard['database'] = [
                'host' => (string) config('database.connections.mysql.host', '127.0.0.1'),
                'port' => (string) config('database.connections.mysql.port', 3306),
                'database' => (string) config('database.connections.mysql.database', 'clockwork'),
                'username' => (string) config('database.connections.mysql.username', 'root'),
                'password' => (string) config('database.connections.mysql.password', ''),
            ];
            $request->session()->put('install.wizard.database', $wizard['database']);
        }

        if (empty($wizard['app'])) {
            $wizard['app'] = [
                'name' => (string) config('app.name', 'Clockwork Control'),
                'url' => (string) config('app.url', $request->getSchemeAndHttpHost()),
                'timezone' => (string) config('app.timezone', 'UTC'),
            ];
            $request->session()->put('install.wizard.app', $wizard['app']);
        }

        if (empty($wizard['google'])) {
            $clientId = (string) config('services.google.client_id', '');
            $clientSecret = (string) config('services.google.client_secret', '');
            if (! empty($clientId) && ! empty($clientSecret)) {
                $wizard['google'] = [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'hd' => (string) config('services.google.hosted_domain', ''),
                ];
                $request->session()->put('install.wizard.google', $wizard['google']);
            } else {
                return redirect()->route('install.google');
            }
        }

        if (empty($wizard['admin'])) {
            $existingAdmin = null;
            try {
                $existingAdmin = User::query()->whereNull('revoked_at')->first();
            } catch (\Throwable) {
            }

            $adminEmail = $existingAdmin instanceof User ? $existingAdmin->email : (string) config('mail.from.address', '');
            $adminName = $existingAdmin instanceof User ? $existingAdmin->name : (string) config('mail.from.name', '');

            if (! empty($adminEmail) && ! empty($adminName)) {
                $wizard['admin'] = [
                    'email' => $adminEmail,
                    'name' => $adminName,
                ];
                $request->session()->put('install.wizard.admin', $wizard['admin']);
            } else {
                return redirect()->route('install.admin');
            }
        }

        return view('install.review', [
            'wizard' => $wizard,
            'providers' => self::HOSTING_PROVIDERS,
            'cloudProviders' => self::CLOUD_PROVIDERS,
            'step' => 9,
        ]);
    }

    /**
     * Execute installation on review confirmation.
     */
    public function install(Request $request): RedirectResponse
    {
        $request->validate([
            'disclaimer_accepted' => ['required', 'accepted'],
        ]);

        $wizard = (array) $request->session()->get('install.wizard', []);

        if (empty($wizard['database'])) {
            $wizard['database'] = [
                'host' => (string) config('database.connections.mysql.host', '127.0.0.1'),
                'port' => (string) config('database.connections.mysql.port', 3306),
                'database' => (string) config('database.connections.mysql.database', 'clockwork'),
                'username' => (string) config('database.connections.mysql.username', 'root'),
                'password' => (string) config('database.connections.mysql.password', ''),
            ];
        }

        if (empty($wizard['app'])) {
            $wizard['app'] = [
                'name' => (string) config('app.name', 'Clockwork Control'),
                'url' => (string) config('app.url', $request->getSchemeAndHttpHost()),
                'timezone' => (string) config('app.timezone', 'UTC'),
            ];
        }

        if (empty($wizard['google'])) {
            $clientId = (string) config('services.google.client_id', '');
            $clientSecret = (string) config('services.google.client_secret', '');
            if (! empty($clientId) && ! empty($clientSecret)) {
                $wizard['google'] = [
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'hd' => (string) config('services.google.hosted_domain', ''),
                ];
            }
        }

        if (empty($wizard['admin'])) {
            $existingAdmin = null;
            try {
                $existingAdmin = User::query()->whereNull('revoked_at')->first();
            } catch (\Throwable) {
            }
            $adminEmail = $existingAdmin instanceof User ? $existingAdmin->email : (string) config('mail.from.address', '');
            $adminName = $existingAdmin instanceof User ? $existingAdmin->name : (string) config('mail.from.name', '');
            if (! empty($adminEmail) && ! empty($adminName)) {
                $wizard['admin'] = [
                    'email' => $adminEmail,
                    'name' => $adminName,
                ];
            }
        }

        $db = $wizard['database'] ?? null;
        $app = $wizard['app'] ?? [];
        $mail = $wizard['mail'] ?? [];
        $google = $wizard['google'] ?? [];
        $admin = $wizard['admin'] ?? [];

        if (! $db || empty($admin['email'])) {
            return redirect()->route('install.welcome');
        }

        // 1. Preserve existing application key if already set and valid; otherwise generate a fresh one
        $existingKey = config('app.key');
        if (! empty($existingKey) && str_starts_with((string) $existingKey, 'base64:') && strlen((string) $existingKey) === 51) {
            $appKey = (string) $existingKey;
        } else {
            $appKey = 'base64:'.base64_encode(Encrypter::generateKey('AES-256-CBC'));
        }

        // 2. Prepare environment payload
        $appName = $app['name'] ?? 'Clockwork Control';
        $appUrl = $app['url'] ?? $request->getSchemeAndHttpHost();
        $timezone = $app['timezone'] ?? 'UTC';

        $envData = [
            'APP_NAME' => $appName,
            'APP_KEY' => $appKey,
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $appUrl,
            'APP_TIMEZONE' => $timezone,
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => $db['host'],
            'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => $db['database'],
            'DB_USERNAME' => $db['username'],
            'DB_PASSWORD' => $db['password'] ?? '',
            'SESSION_DRIVER' => 'database',
            'SESSION_SECURE_COOKIE' => str_starts_with((string) $appUrl, 'https') ? 'true' : 'false',
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
            'GOOGLE_CLIENT_ID' => ! empty($google['skipped']) ? '' : ($google['client_id'] ?? ''),
            'GOOGLE_CLIENT_SECRET' => ! empty($google['skipped']) ? '' : ($google['client_secret'] ?? ''),
            'GOOGLE_REDIRECT_URI' => (! empty($google['client_id']) && empty($google['skipped'])) ? rtrim($appUrl, '/').'/auth/google/callback' : '',
            'CLOCKWORK_TELEMETRY_ENABLED' => $request->boolean('telemetry_opt_in') ? 'true' : 'false',
        ];

        if (! empty($google['hd']) && empty($google['skipped'])) {
            $envData['GOOGLE_HD'] = $google['hd'];
        }

        // 2. Handle Mail configuration
        if (! empty($mail['host']) && empty($mail['skipped'])) {
            $envData['MAIL_MAILER'] = $mail['mailer'] ?? 'smtp';
            $envData['MAIL_HOST'] = $mail['host'] ?? '';
            $envData['MAIL_PORT'] = $mail['port'] ?? '587';
            $envData['MAIL_USERNAME'] = $mail['username'] ?? '';
            $envData['MAIL_PASSWORD'] = $mail['password'] ?? '';
            $envData['MAIL_FROM_ADDRESS'] = $mail['from_address'] ?? 'noreply@example.com';
            $envData['MAIL_FROM_NAME'] = $mail['from_name'] ?? $appName;
        } else {
            $envData['MAIL_MAILER'] = 'log';
        }

        // 3. Write .env atomically
        $this->envWriter->writeMany($envData);

        if (! app()->runningUnitTests()) {
            // Configure active database connection for migrations in this process
            config(['database.connections.mysql' => [
                'driver' => 'mysql',
                'host' => $db['host'],
                'port' => $db['port'],
                'database' => $db['database'],
                'username' => $db['username'],
                'password' => $db['password'] ?? '',
                'charset' => 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix' => '',
                'strict' => true,
            ]]);
            config(['database.default' => 'mysql']);
            DB::purge('mysql');

            // 4. Run migrations in-process
            try {
                Artisan::call('migrate', ['--force' => true]);
            } catch (Exception $e) {
                // A migration failure here must NOT proceed to provisioning the admin user or
                // writing storage/installed — doing so would finalize "installed" state with an
                // incomplete schema and no way back into /install short of the CLI reopen command.
                Log::error('installer.migrate_failed', ['message' => $e->getMessage()]);

                return redirect()->route('install.review')->with(
                    'error',
                    'Database migration failed. Check the application logs for details, then try again.'
                );
            }
        }

        // 5. Provision initial admin user via shared service
        $this->userProvisioner->addOrRestore(
            email: $admin['email'],
            name: $admin['name'],
            actor: 'installer',
            password: $admin['password'] ?? null,
        );

        // Record the disclaimer acknowledgment durably (not just a log line,
        // which can rotate away) — same key/value store already used for
        // other operator-facing settings.
        app(Settings::class)->putMany([
            'disclaimer.accepted_at' => now()->toIso8601String(),
            'disclaimer.accepted_version' => config('clockwork.version', '1.1.0'),
        ]);

        app(Settings::class)->put('telemetry.enabled', $request->boolean('telemetry_opt_in'));

        // 6. Write storage/installed sentinel
        $hostingIntent = $wizard['hosting']['providers'] ?? [$wizard['hosting']['provider'] ?? 'skip'];
        if (is_array($hostingIntent)) {
            $hostingIntent = implode(',', $hostingIntent);
        }

        $vpsIntent = $wizard['vps']['providers'] ?? [$wizard['vps']['provider'] ?? 'skip'];
        if (is_array($vpsIntent)) {
            $vpsIntent = implode(',', $vpsIntent);
        }

        $sentinelData = [
            'installed_at' => now()->toIso8601String(),
            'version' => config('clockwork.version', '1.1.0'),
            'admin_email' => $admin['email'],
            'hosting_intent' => (string) $hostingIntent,
            'vps_intent' => (string) $vpsIntent,
        ];

        file_put_contents(
            EnforceInstallerGate::sentinelPath(),
            json_encode($sentinelData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );

        // Clear wizard session and mark completed
        $request->session()->forget('install.wizard');
        $request->session()->put('install.just_installed', true);

        return redirect()->route('install.done');
    }

    /**
     * Step 10: Done / Success Celebration Screen.
     */
    public function done(): View
    {
        return view('install.done', [
            'step' => 10,
        ]);
    }
}
