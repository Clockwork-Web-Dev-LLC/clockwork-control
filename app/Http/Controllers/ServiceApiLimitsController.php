<?php

namespace App\Http\Controllers;

use App\Support\EnvCredentialManager;
use App\Support\ServiceRateLimitRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\View\View;
use Modules\Core\ModuleRegistry;

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

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'service' => $serviceMeta,
                'tunables' => $tunables,
                'credentials' => $credentials,
                'testable' => $testable,
            ]);
        }

        return view('settings.integrations.limits', [
            'service' => $serviceMeta,
            'tunables' => $tunables,
            'credentials' => $credentials,
            'testable' => $testable,
            'allServices' => $registry->all(),
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
}
