<?php

namespace App\Http\Controllers;

use App\Services\Diagnostics\Checks\CloudflareCheck;
use App\Services\Diagnostics\Checks\DigitalOceanSpacesCheck;
use App\Services\Diagnostics\Checks\GoogleSafeBrowsingCheck;
use App\Support\CredentialResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Modules\Core\ModuleDirectoryClient;
use Modules\Core\ModuleRegistry;

/**
 * /settings/integrations — API credentials for every provider client, stored
 * in the DB (encrypted) with .env as the permanent fallback. We never show a
 * stored value back, only whether one is set and where it came from
 * (database vs .env) — same doctrine BillComSettingsController already
 * documents for its .env-only credentials.
 *
 * Field metadata for the core (non-module) integrations is the single
 * source of truth driving both render and save, same shape as
 * SecurityScansSettingsController::SOURCES. DigitalOcean/Hetzner/Azure/
 * Pressable/SpinupWP/BillCom are no longer listed here — their credential
 * fields come from ModuleRegistry::manifests() and are merged in at request
 * time by integrations(). do_spaces stays a hardcoded core entry: it's a
 * storage credential (Spaces backups), not part of the DigitalOcean IaaS
 * module.
 */
class IntegrationCredentialsController extends Controller
{
    public const INTEGRATIONS = [
        'do_spaces' => [
            'label' => 'DigitalOcean Spaces',
            'description' => 'Automated offsite backup storage using DigitalOcean Spaces or any S3-compatible object store.',
            'capabilities' => ['Offsite S3 Backups', 'Asset Preservation', 'Custom Storage Endpoints'],
            'fields' => [
                'key' => ['label' => 'Access Key', 'secret' => false],
                'secret' => ['label' => 'Secret Key', 'secret' => true],
            ],
            'check' => DigitalOceanSpacesCheck::class,
            'status' => 'verified',
            'status_note' => 'Verified and in active daily use for offsite backup storage.',
        ],
        'cloudflare' => [
            'label' => 'Cloudflare',
            'description' => 'DNS zone inspection, edge cache health, and WAF security analytics via Cloudflare API.',
            'capabilities' => ['DNS Zone Health', 'WAF Analytics', 'SSL Edge Telemetry'],
            'fields' => [
                'api_token' => ['label' => 'API Token (read)', 'secret' => true],
                'write_token' => ['label' => 'Write Token', 'secret' => true],
            ],
            'check' => CloudflareCheck::class,
            'status' => 'verified',
            'status_note' => 'Verified and in active daily use for DNS and WAF diagnostics.',
        ],
        'security_scans' => [
            'label' => 'Blacklist scanning',
            'description' => 'Daily domain reputation checks against Google Web Risk (or legacy Safe Browsing v4) and abuse intelligence databases.',
            'capabilities' => ['Google Web Risk', 'URLhaus Threat Intel', 'Automated Malware Checks'],
            'fields' => [
                'google_web_risk_key' => ['label' => 'Google Web Risk Key', 'secret' => true],
                'google_safe_browsing_key' => ['label' => 'Google Safe Browsing Key (legacy v4)', 'secret' => true],
                'urlhaus_auth_key' => ['label' => 'URLHaus Auth Key', 'secret' => true],
            ],
            'check' => GoogleSafeBrowsingCheck::class, // Web Risk first, then v4; URLHaus and keyless Spamhaus have no dedicated check
            'status' => 'verified',
            'status_note' => 'Verified and in active daily use for fleet threat intelligence.',
        ],
        'ssh' => [
            'label' => 'SSH (fleet default key)',
            'description' => 'Fleet-wide default SSH credentials for server provisioning and scheduled telemetry commands.',
            'capabilities' => ['Fleet Key Distribution', 'Automated Provisioning', 'Secure Server Access'],
            'fields' => [
                'default_key_path' => ['label' => 'Default Key Path', 'secret' => false],
                'default_key_passphrase' => ['label' => 'Default Key Passphrase', 'secret' => true],
            ],
            'check' => null, // per-server SSH testing already lives on /servers/credentials
            'status' => 'verified',
            'status_note' => 'Verified and in active daily use for fleet management.',
        ],
    ];

    /**
     * Core integrations plus every module's manifest, keyed by integration
     * id. Module entries always carry 'check' => null here — their
     * DiagnosticCheck (if any) is resolved by module id via
     * ModuleRegistry::diagnosticCheckFor(), not stored in this array.
     */
    private function integrations(ModuleRegistry $modules): array
    {
        $merged = self::INTEGRATIONS;

        foreach ($modules->manifests() as $manifest) {
            $merged[$manifest->id] = [
                'label' => $manifest->name,
                'description' => $manifest->description,
                'fields' => $manifest->credentialFields,
                'check' => null,
                'status' => $manifest->status,
                'status_note' => $manifest->statusNote,
            ];
        }

        return $merged;
    }

    public function index(CredentialResolver $resolver, ModuleRegistry $modules): View
    {
        $feedModules = [];
        try {
            $feed = app(ModuleDirectoryClient::class)->fetch();
            foreach ($feed['modules'] ?? [] as $m) {
                if (! empty($m['id'])) {
                    $feedModules[$m['id']] = $m;
                }
            }
        } catch (\Throwable) {
            // Keep going if directory feed is unreachable
        }

        $integrations = [];
        foreach ($this->integrations($modules) as $id => $meta) {
            $fields = [];
            foreach ($meta['fields'] as $key => $fieldMeta) {
                $path = "{$id}.{$key}";
                $fields[$key] = $fieldMeta + [
                    'source' => $resolver->source($path),
                    'configured' => $resolver->source($path) !== 'unset',
                ];
            }

            $feedItem = $feedModules[$id] ?? null;

            $integrations[$id] = [
                'label' => $meta['label'],
                'description' => $feedItem['description'] ?? ($meta['description'] ?? null),
                'capabilities' => $feedItem['capabilities'] ?? ($meta['capabilities'] ?? []),
                'fields' => $fields,
                'testable' => $meta['check'] !== null || $modules->diagnosticCheckFor($id) !== null,
                'status' => $meta['status'] ?? 'verified',
                'status_note' => $meta['status_note'] ?? null,
            ];
        }

        uasort($integrations, fn ($a, $b) => strcasecmp($a['label'], $b['label']));

        return view('settings.integrations', ['integrations' => $integrations]);
    }

    public function update(Request $request, ModuleRegistry $modules): RedirectResponse
    {
        $resolver = app(CredentialResolver::class);

        $diff = [];
        foreach ($this->integrations($modules) as $id => $meta) {
            foreach (array_keys($meta['fields']) as $key) {
                $path = "{$id}.{$key}";
                $wasSource = $resolver->source($path);

                $clear = $request->boolean("clear_{$id}_{$key}");
                $value = $request->input("value_{$id}_{$key}");

                if ($clear) {
                    $resolver->forget($path);
                } elseif (is_string($value) && $value !== '') {
                    $resolver->put($path, $value);
                }
                // Blank, non-cleared submit leaves the stored value untouched.

                $nowSource = $resolver->source($path);
                if ($wasSource !== $nowSource) {
                    $diff[$path] = ['was' => $wasSource, 'now' => $nowSource];
                }
            }
        }

        if ($diff !== []) {
            Log::info('integrations.credentials_updated', [
                'actor_id' => $request->user()?->id,
                'diff' => $diff,
            ]);
        }

        return redirect()->route('settings.integrations.index')
            ->with('status', 'Integration credentials saved.');
    }

    public function test(Request $request, string $integration, ModuleRegistry $modules): RedirectResponse|JsonResponse
    {
        $canonicalId = match ($integration) {
            'billcom' => 'bill_com',
            'bill-com' => 'bill_com',
            'pagespeed' => 'psi',
            'google' => 'auth_google',
            'github' => 'auth_github',
            'microsoft' => 'auth_microsoft',
            default => $integration,
        };

        $meta = $this->integrations($modules)[$canonicalId] ?? $this->integrations($modules)[$integration] ?? null;
        $label = $meta['label'] ?? ucfirst($integration);
        $check = ($meta && $meta['check'] !== null)
            ? app($meta['check'])
            : ($modules->diagnosticCheckFor($canonicalId) ?? $modules->diagnosticCheckFor($integration));

        if ($check === null) {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'status' => 'fail',
                    'summary' => 'No connection test available for this integration.',
                    'detail' => null,
                ], 404);
            }

            return back()->with('status_error', 'No connection test available for this integration.');
        }

        $result = $check->run();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => $result->status !== 'fail',
                'status' => $result->status,
                'summary' => $result->summary,
                'detail' => $result->detail,
                'duration_ms' => $result->durationMs,
            ]);
        }

        return back()->with(
            $result->status === 'fail' ? 'status_error' : 'status',
            "{$label}: {$result->summary}",
        );
    }
}
