<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Support\Settings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Modules\Core\Contracts\HostingProvider;
use Throwable;

/**
 * Pushes the current list of backup-relay-enabled sites across all supported
 * hosting providers to S3 as a schema-versioned JSON manifest, for an external
 * backup-relay agent to read before its run.
 */
class PushBackupRelayTargets extends Command
{
    protected $signature = 'clockwork:push-backup-relay-targets';

    protected $description = 'Push the backup-relay-enabled site list to S3 for the backup-relay agent to read.';

    public function handle(Settings $settings): int
    {
        $disk = Storage::disk('s3');
        $frequency = (string) $settings->get('backup_relay.frequency', config('clockwork.backup_relay.frequency', 'weekly'));
        $retentionDays = (int) $settings->get('backup_relay.retention_days', config('clockwork.backup_relay.retention_days', 90));

        $allSites = Site::query()
            ->backupRelayEnabled()
            ->orderBy('domain')
            ->get();

        $sites = $allSites->filter(function (Site $site) {
            try {
                return $site->host()->supports(HostingProvider::CAP_BACKUP_RELAY);
            } catch (Throwable) {
                return false;
            }
        });

        $payload = [
            'schema_version' => (int) config('clockwork.backup_relay.schema_version', 2),
            'generated_at' => now()->toIso8601String(),
            'frequency' => $frequency,
            'retention_days' => $retentionDays,
            'policy' => [
                'frequency' => $frequency,
                'retention_days' => $retentionDays,
            ],
            'sites' => $sites->map(function (Site $site) {
                $externalRef = match ($site->hosting_provider) {
                    Site::HOSTING_PROVIDER_PRESSABLE => ['pressable_site_id' => $site->pressable_site_id],
                    Site::HOSTING_PROVIDER_SPINUPWP => ['spinupwp_id' => $site->spinupwp_id],
                    default => ['id' => $site->id],
                };

                return [
                    'site_id' => $site->id,
                    'provider' => $site->hosting_provider,
                    'external_ref' => $externalRef,
                    // Backward-compat for existing Pressable-only external agent droplet running schema v1
                    'pressable_site_id' => $site->pressable_site_id,
                    'domain' => $site->domain,
                ];
            })->values(),
        ];

        $key = rtrim((string) config('clockwork.backup_relay.s3_prefix'), '/').'/targets.json';

        try {
            $disk->put($key, json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        } catch (Throwable $e) {
            $this->error("Failed to push targets manifest to s3://{$key}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Pushed {$sites->count()} site(s) to s3://{$key}.");

        return self::SUCCESS;
    }
}
