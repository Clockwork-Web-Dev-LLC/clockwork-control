<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Support\EnvCredentialManager;
use App\Support\ServiceRateLimitRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Core\ModuleRegistry;
use Modules\Core\ModuleStateResolver;
use Modules\DigitalOcean\DigitalOceanClient;
use Modules\Hetzner\HetznerClient;
use Modules\Vultr\VultrClient;

class ServiceApiLimitsController extends Controller
{
    /**
     * Show API rate limits, official documentation, operator tunables, and .env credentials for a service.
     */
    public function show(
        Request $request,
        string $service,
        ServiceRateLimitRegistry $registry,
        EnvCredentialManager $envManager
    ): View|JsonResponse {
        $serviceMeta = $registry->get($service);
        if (! $serviceMeta) {
            abort(404, "Unknown service: {$service}");
        }

        $tunables = $registry->getTunables($service);
        $credentials = $envManager->getFieldsForService($service);

        $canonicalId = match ($service) {
            'billcom' => 'bill_com',
            'bill-com' => 'bill_com',
            'pagespeed' => 'psi',
            'google' => 'auth_google',
            'github' => 'auth_github',
            'microsoft' => 'auth_microsoft',
            default => $service,
        };
        $moduleRegistry = app(ModuleRegistry::class);
        $testable = $moduleRegistry->diagnosticCheckFor($canonicalId) !== null
            || $moduleRegistry->diagnosticCheckFor($service) !== null
            || isset(IntegrationCredentialsController::INTEGRATIONS[$service]['check']);

        $isCloudProvider = in_array($service, ['vultr', 'digitalocean', 'hetzner', 'linode', 'azure'], true);
        $detectedInstances = $isCloudProvider ? $this->getDetectedInstances($service) : [];
        $moduleResolver = app(ModuleStateResolver::class);
        $hostingPanels = [
            'spinupwp' => [
                'enabled' => $moduleResolver->isEnabled('spinupwp'),
                'label' => 'SpinupWP',
                'refresh_url' => route('servers.refreshFromSpinupWp'),
            ],
            'gridpane' => [
                'enabled' => $moduleResolver->isEnabled('gridpane'),
                'label' => 'GridPane',
                'refresh_url' => route('servers.refreshFromGridPane'),
            ],
        ];

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'service' => $serviceMeta,
                'tunables' => $tunables,
                'credentials' => $credentials,
                'testable' => $testable,
                'is_cloud_provider' => $isCloudProvider,
                'detected_instances' => $detectedInstances,
                'hosting_panels' => $hostingPanels,
            ]);
        }

        return view('settings.integrations.limits', [
            'service' => $serviceMeta,
            'tunables' => $tunables,
            'credentials' => $credentials,
            'testable' => $testable,
            'allServices' => $registry->all(),
            'isCloudProvider' => $isCloudProvider,
            'detectedInstances' => $detectedInstances,
            'hostingPanels' => $hostingPanels,
        ]);
    }

    /**
     * Save operator custom rate limits, connection tunables, and .env credentials.
     */
    public function update(
        Request $request,
        string $service,
        ServiceRateLimitRegistry $registry,
        EnvCredentialManager $envManager
    ): RedirectResponse|JsonResponse {
        $serviceMeta = $registry->get($service);
        if (! $serviceMeta) {
            abort(404, "Unknown service: {$service}");
        }

        $validated = $request->validate([
            'timeout' => 'nullable|integer|min:1|max:300',
            'rate_limit' => 'nullable|integer|min:1|max:1000000',
            'concurrency' => 'nullable|integer|min:1|max:10',
            'delay_ms' => 'nullable|integer|min:0|max:5000',
            'retry_attempts' => 'nullable|integer|min:0|max:5',
        ]);

        $registry->saveTunables($service, $validated);

        // Process credentials saved directly to .env
        $credentialsChanged = false;
        $credInputs = $request->input('credentials', []);
        if (is_array($credInputs)) {
            foreach ($credInputs as $field => $val) {
                if (is_string($val) && trim($val) !== '') {
                    $credentialsChanged = $envManager->save($service, (string) $field, $val) || $credentialsChanged;
                }
            }
        }

        // Process standard form inputs (cred_{field} and clear_cred_{field})
        foreach ($envManager->getDefinitions($service) as $field => $meta) {
            $inputVal = $request->input("cred_{$field}");
            $clearVal = $request->boolean("clear_cred_{$field}");

            if ($clearVal) {
                $credentialsChanged = $envManager->remove($service, $field) || $credentialsChanged;
            } elseif (is_string($inputVal) && trim($inputVal) !== '') {
                $credentialsChanged = $envManager->save($service, $field, $inputVal) || $credentialsChanged;
            }
        }

        // putenv()/$_ENV/config() inside EnvCredentialManager only update THIS
        // request's own process memory. The long-running queue:work daemon
        // (com.clockwork.queue) is a separate process that loaded .env once at
        // boot — restarting it here is what actually makes a saved/removed
        // credential take effect for queued jobs, not just this request.
        // Scheduled/cron-driven polling doesn't need this: schedule:run spawns
        // a fresh process every minute and picks up .env changes on its own.
        if ($credentialsChanged) {
            Artisan::call('queue:restart');
        }

        $updatedTunables = $registry->getTunables($service);
        $updatedCredentials = $envManager->getFieldsForService($service);

        $message = "Settings and credentials updated for {$serviceMeta['name']}. API keys saved directly to .env.";

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'tunables' => $updatedTunables,
                'credentials' => $updatedCredentials,
            ]);
        }

        return back()->with('status', $message);
    }

    /**
     * Remove a credential from the root .env file.
     */
    public function removeCredential(
        Request $request,
        string $service,
        string $field,
        EnvCredentialManager $envManager
    ): RedirectResponse|JsonResponse {
        $defs = $envManager->getDefinitions($service);
        $meta = $defs[$field] ?? null;

        $removed = $envManager->remove($service, $field);
        $label = $meta['label'] ?? $field;
        $envVar = $meta['env_var'] ?? $field;

        if ($removed) {
            Artisan::call('queue:restart');
        }

        $message = "Removed {$label} ({$envVar}) from your .env file.";

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => $removed,
                'message' => $message,
                'credentials' => $envManager->getFieldsForService($service),
            ]);
        }

        return back()->with('status', $message);
    }

    /**
     * Reset service tunables back to recommended vendor defaults.
     */
    public function reset(
        Request $request,
        string $service,
        ServiceRateLimitRegistry $registry,
        EnvCredentialManager $envManager
    ): RedirectResponse|JsonResponse {
        $serviceMeta = $registry->get($service);
        if (! $serviceMeta) {
            abort(404, "Unknown service: {$service}");
        }

        $registry->resetToDefaults($service);
        $resetTunables = $registry->getTunables($service);

        $message = "Reset API limits to vendor recommended defaults for {$serviceMeta['name']}.";

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $message,
                'tunables' => $resetTunables,
                'credentials' => $envManager->getFieldsForService($service),
            ]);
        }

        return back()->with('status', $message);
    }

    /**
     * Run cloud provider reconciliation to link existing servers by IP.
     */
    public function reconcile(Request $request, string $service): RedirectResponse|JsonResponse
    {
        try {
            Artisan::call('clockwork:reconcile-provider');
            $output = trim(Artisan::output());

            $message = 'Provider reconciliation finished. '.$output;

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'output' => $output,
                ]);
            }

            return back()->with('status', $message);
        } catch (\Throwable $e) {
            $error = 'Reconciliation failed: '.$e->getMessage();
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $error,
                ], 500);
            }

            return back()->with('status_error', $error);
        }
    }

    /**
     * Import an unlinked cloud instance directly into the Server Fleet.
     */
    public function importInstance(Request $request, string $service): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'instance_id' => 'required|string',
        ]);

        $instanceId = $validated['instance_id'];

        try {
            $server = $this->createOrLinkInstance($service, $instanceId);

            $message = "Instance \"{$server->name}\" ({$server->hostname}) imported successfully into Server Fleet.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $message,
                    'server' => [
                        'id' => $server->id,
                        'name' => $server->name,
                        'hostname' => $server->hostname,
                        'url' => route('servers.show', ['server' => $server->id]),
                    ],
                ]);
            }

            return back()->with('status', $message);
        } catch (\Throwable $e) {
            $error = 'Failed to import instance: '.$e->getMessage();
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $error,
                ], 422);
            }

            return back()->with('status_error', $error);
        }
    }

    /**
     * Discover live instances from the cloud provider and cross-reference with local servers.
     *
     * @return list<array<string, mixed>>
     */
    protected function getDetectedInstances(string $service): array
    {
        $rawInstances = [];

        if ($service === 'vultr') {
            try {
                $client = app(VultrClient::class);
                if ($client->isConfigured()) {
                    foreach ($client->instances() as $inst) {
                        $rawInstances[] = [
                            'id' => (string) ($inst['id'] ?? ''),
                            'name' => (string) ($inst['label'] ?? $inst['hostname'] ?? ''),
                            'ip' => (string) ($inst['main_ip'] ?? ''),
                            'plan' => (string) ($inst['plan'] ?? ''),
                            'vcpus' => isset($inst['vcpu_count']) ? (int) $inst['vcpu_count'] : (isset($inst['vcpus']) ? (int) $inst['vcpus'] : null),
                            'memory_mb' => isset($inst['ram']) ? (int) $inst['ram'] : null,
                            'disk_gb' => isset($inst['disk']) ? (int) $inst['disk'] : null,
                            'status' => (string) ($inst['status'] ?? $inst['server_status'] ?? 'unknown'),
                            'region' => (string) ($inst['region'] ?? ''),
                            'tags' => (array) ($inst['tags'] ?? (! empty($inst['tag']) ? [$inst['tag']] : [])),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('service_limits.vultr_instance_discovery_failed', ['error' => $e->getMessage()]);
            }
        } elseif ($service === 'digitalocean') {
            try {
                $client = app(DigitalOceanClient::class);
                if ($client->isConfigured()) {
                    foreach ($client->droplets() as $d) {
                        $publicIp = null;
                        foreach (($d['networks']['v4'] ?? []) as $iface) {
                            if (($iface['type'] ?? null) === 'public' && ! empty($iface['ip_address'])) {
                                $publicIp = $iface['ip_address'];
                                break;
                            }
                        }
                        $rawInstances[] = [
                            'id' => (string) ($d['id'] ?? ''),
                            'name' => (string) ($d['name'] ?? ''),
                            'ip' => (string) ($publicIp ?? ''),
                            'plan' => (string) ($d['size_slug'] ?? ''),
                            'vcpus' => isset($d['vcpus']) ? (int) $d['vcpus'] : null,
                            'memory_mb' => isset($d['memory']) ? (int) $d['memory'] : null,
                            'disk_gb' => isset($d['disk']) ? (int) $d['disk'] : null,
                            'status' => (string) ($d['status'] ?? 'unknown'),
                            'region' => (string) ($d['region']['slug'] ?? ''),
                            'tags' => (array) ($d['tags'] ?? []),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('service_limits.do_instance_discovery_failed', ['error' => $e->getMessage()]);
            }
        } elseif ($service === 'hetzner') {
            try {
                $client = app(HetznerClient::class);
                if ($client->isConfigured()) {
                    foreach ($client->servers() as $s) {
                        $type = $s['server_type'] ?? [];
                        $memoryGb = isset($type['memory']) ? (float) $type['memory'] : null;
                        $rawInstances[] = [
                            'id' => (string) ($s['id'] ?? ''),
                            'name' => (string) ($s['name'] ?? ''),
                            'ip' => (string) ($s['public_net']['ipv4']['ip'] ?? ''),
                            'plan' => (string) ($type['name'] ?? ''),
                            'vcpus' => isset($type['cores']) ? (int) $type['cores'] : null,
                            'memory_mb' => $memoryGb !== null ? (int) round($memoryGb * 1024) : null,
                            'disk_gb' => isset($type['disk']) ? (int) $type['disk'] : null,
                            'status' => (string) ($s['status'] ?? 'unknown'),
                            'region' => (string) ($s['datacenter']['location']['name'] ?? ''),
                            'tags' => array_keys((array) ($s['labels'] ?? [])),
                        ];
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('service_limits.hetzner_instance_discovery_failed', ['error' => $e->getMessage()]);
            }
        }

        $instances = [];
        foreach ($rawInstances as $raw) {
            $id = $raw['id'];
            $ip = $raw['ip'];
            $name = $raw['name'];

            $matchedServer = null;
            if ($id !== '') {
                $matchedServer = Server::where('provider', $service)
                    ->where('provider_id', $id)
                    ->first();
            }
            if (! $matchedServer && $ip !== '') {
                $matchedServer = Server::where('hostname', $ip)->first();
            }
            if (! $matchedServer && $name !== '') {
                $matchedServer = Server::where('name', $name)->first();
            }

            $isFullyLinked = false;
            $serverData = null;
            if ($matchedServer) {
                $isFullyLinked = ($matchedServer->provider === $service && (string) $matchedServer->provider_id === $id);
                $serverData = [
                    'id' => $matchedServer->id,
                    'name' => $matchedServer->name,
                    'hostname' => $matchedServer->hostname,
                    'provider' => $matchedServer->provider,
                    'provider_id' => $matchedServer->provider_id,
                    'spinupwp_id' => $matchedServer->spinupwp_id,
                    'is_fully_linked' => $isFullyLinked,
                    'url' => route('servers.show', ['server' => $matchedServer->id]),
                ];
            }

            $suggestedPanel = null;
            $tagsLower = array_map('strtolower', $raw['tags']);
            $nameLower = strtolower($name);
            if (in_array('spinupwp', $tagsLower, true) || str_contains($nameLower, 'spinup')) {
                $suggestedPanel = 'spinupwp';
            } elseif (in_array('gridpane', $tagsLower, true) || str_contains($nameLower, 'gridpane')) {
                $suggestedPanel = 'gridpane';
            }

            $instances[] = [
                ...$raw,
                'is_linked' => $matchedServer !== null,
                'is_fully_linked' => $isFullyLinked,
                'linked_server' => $serverData,
                'suggested_panel' => $suggestedPanel,
            ];
        }

        return $instances;
    }

    /**
     * Create or link a Server record from cloud instance specs.
     */
    protected function createOrLinkInstance(string $service, string $instanceId): Server
    {
        $name = null;
        $ip = null;
        $plan = null;
        $vcpus = null;
        $ram = null;
        $disk = null;
        $os = null;

        if ($service === 'vultr') {
            $client = app(VultrClient::class);
            $inst = $client->instance($instanceId);
            if (empty($inst)) {
                throw new \RuntimeException("Instance {$instanceId} not found on Vultr.");
            }
            $name = $inst['label'] ?: ($inst['hostname'] ?: 'vultr-'.$instanceId);
            $ip = $inst['main_ip'] ?? '';
            $plan = $inst['plan'] ?? null;
            $vcpus = isset($inst['vcpu_count']) ? (int) $inst['vcpu_count'] : (isset($inst['vcpus']) ? (int) $inst['vcpus'] : null);
            $ram = isset($inst['ram']) ? (int) $inst['ram'] : null;
            $disk = isset($inst['disk']) ? (int) $inst['disk'] : null;
            $os = $inst['os'] ?? null;
        } elseif ($service === 'digitalocean') {
            $client = app(DigitalOceanClient::class);
            $droplets = $client->droplets();
            $d = collect($droplets)->firstWhere('id', (int) $instanceId) ?? collect($droplets)->firstWhere('id', $instanceId);
            if (! $d) {
                throw new \RuntimeException("Droplet {$instanceId} not found on DigitalOcean.");
            }
            $name = $d['name'] ?? "do-{$instanceId}";
            foreach (($d['networks']['v4'] ?? []) as $iface) {
                if (($iface['type'] ?? null) === 'public' && ! empty($iface['ip_address'])) {
                    $ip = $iface['ip_address'];
                    break;
                }
            }
            $plan = $d['size_slug'] ?? null;
            $vcpus = isset($d['vcpus']) ? (int) $d['vcpus'] : null;
            $ram = isset($d['memory']) ? (int) $d['memory'] : null;
            $disk = isset($d['disk']) ? (int) $d['disk'] : null;
        } elseif ($service === 'hetzner') {
            $client = app(HetznerClient::class);
            $servers = $client->servers();
            $s = collect($servers)->firstWhere('id', (int) $instanceId) ?? collect($servers)->firstWhere('id', $instanceId);
            if (! $s) {
                throw new \RuntimeException("Server {$instanceId} not found on Hetzner.");
            }
            $name = $s['name'] ?? "hetzner-{$instanceId}";
            $ip = $s['public_net']['ipv4']['ip'] ?? '';
            $type = $s['server_type'] ?? [];
            $plan = $type['name'] ?? null;
            $vcpus = isset($type['cores']) ? (int) $type['cores'] : null;
            $ram = isset($type['memory']) ? (int) round((float) $type['memory'] * 1024) : null;
            $disk = isset($type['disk']) ? (int) $type['disk'] : null;
        } else {
            throw new \RuntimeException("Direct instance import not supported for service: {$service}");
        }

        $server = Server::where('provider', $service)
            ->where('provider_id', $instanceId)
            ->first();

        if (! $server && ! empty($ip)) {
            $server = Server::where('hostname', $ip)->first();
        }

        if (! $server && ! empty($name)) {
            $server = Server::where('name', $name)->first();
        }

        if (! $server) {
            $server = new Server;
            $server->name = $name ?: ($ip ?: "{$service}-{$instanceId}");
            $server->hostname = $ip ?: $server->name;
            $server->ssh_port = 22;
            $server->ssh_user = 'root';
            $server->status = Server::STATUS_UNKNOWN;
        }

        $server->provider = $service;
        $server->provider_id = (string) $instanceId;
        if ($plan) {
            $server->size_slug = $plan;
        }
        if ($vcpus !== null) {
            $server->vcpus = $vcpus;
        }
        if ($ram !== null) {
            $server->memory_mb = $ram;
        }
        if ($disk !== null) {
            $server->disk_gb = $disk;
        }
        if ($os) {
            $server->ubuntu_version = $os;
        }
        $server->save();

        return $server;
    }
}
