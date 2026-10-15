<?php

namespace App\Console\Commands;

use App\Models\Site;
use App\Services\Backups\SiteBackupRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class RecordSiteBackups extends Command
{
    protected $signature = 'clockwork:record-site-backups
        {--site= : Limit to a single site (id or domain)}';

    protected $description = 'Record each site\'s backups from its real source (Pressable API, SpinupWP Spaces, our S3 archives) for client reports.';

    public function handle(SiteBackupRecorder $recorder): int
    {
        $query = Site::query()->where('is_inactive', false)->orderBy('domain');
        if ($needle = $this->option('site')) {
            $query->where(fn ($q) => ctype_digit((string) $needle)
                ? $q->where('id', (int) $needle)->orWhere('domain', $needle)
                : $q->where('domain', $needle));
        }

        $checked = 0;
        $failed = 0;
        foreach ($query->cursor() as $site) {
            // One site's failure (even a database error) mustn't stop the rest.
            try {
                $result = $recorder->record($site);
            } catch (Throwable $e) {
                $result = ['source' => $recorder->sourceFor($site) ?? 'unknown', 'ok' => false, 'seen' => 0, 'error' => $e->getMessage()];
            }
            if ($result === null) {
                continue;
            }

            $checked++;
            if ($result['ok']) {
                $this->line("  [ok]   {$site->domain} ({$result['source']}): {$result['seen']} backups");
            } else {
                $failed++;
                $this->error("  [fail] {$site->domain} ({$result['source']}): {$result['error']}");
                Log::warning('site_backups.record_failed', ['site_id' => $site->id, 'source' => $result['source'], 'error' => $result['error']]);
            }
        }

        $this->info("Recorded backups for {$checked} sites ({$failed} could not be read).");

        // A failed read leaves that site's report section as "Could not check",
        // so the run shows as failed in Scheduled Jobs rather than looking healthy.
        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
