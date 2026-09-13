<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\BackupRelayRun;
use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Process\BackgroundArtisan;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use Modules\BackupRelay\Services\BackupArchiveEnumerator;
use Modules\Core\Contracts\HostingProvider;
use Throwable;

class BackupRelaySettingsController extends Controller
{
    public function index(Settings $settings): View
    {
        $mode = (string) $settings->get('backup_relay.mode', config('clockwork.backup_relay.mode', 'in_repo'));
        $frequency = (string) $settings->get('backup_relay.frequency', config('clockwork.backup_relay.frequency', 'weekly'));
        $retentionDays = (int) $settings->get('backup_relay.retention_days', config('clockwork.backup_relay.retention_days', 90));
        $diskName = (string) config('clockwork.backup_relay.disk', 's3-backup-relay');
        $s3Prefix = (string) config('clockwork.backup_relay.s3_prefix', '_control/backup-relay');
        $archivePrefix = (string) config('clockwork.backup_relay.archive_prefix', 'archives');

        $sites = Site::query()
            ->notArchived()
            ->where('is_inactive', false)
            ->orderBy('domain')
            ->get()
            ->map(function (Site $site) {
                $supportsRelay = false;
                try {
                    $supportsRelay = $site->host()->supports(HostingProvider::CAP_BACKUP_RELAY);
                } catch (Throwable) {
                    $supportsRelay = false;
                }

                return [
                    'id' => $site->id,
                    'domain' => $site->domain,
                    'provider' => $site->hosting_provider,
                    'supports_relay' => $supportsRelay,
                    'enabled' => (bool) $site->backup_relay_enabled,
                    'last_archived_at' => $site->backup_relay_last_archived_at,
                ];
            });

        $runs = BackupRelayRun::query()
            ->latest('finished_at')
            ->take(15)
            ->get();

        $lastRunAt = $settings->get('backup_relay.last_run_at');
        $lastRunStats = json_decode((string) $settings->get('backup_relay.last_run_stats', '{}'), true);

        return view('settings.backup-relay', [
            'mode' => $mode,
            'frequency' => $frequency,
            'retentionDays' => $retentionDays,
            'diskName' => $diskName,
            's3Prefix' => $s3Prefix,
            'archivePrefix' => $archivePrefix,
            'sites' => $sites,
            'runs' => $runs,
            'lastRunAt' => $lastRunAt,
            'lastRunStats' => $lastRunStats,
            'enabledCount' => $sites->where('enabled', true)->count(),
            'supportedCount' => $sites->where('supports_relay', true)->count(),
        ]);
    }

    public function update(Request $request, Settings $settings): RedirectResponse|JsonResponse
    {
        if ($request->has('policy_update')) {
            $validated = $request->validate([
                'frequency' => 'required|string|in:weekly,twice_weekly,daily',
                'retention_days' => 'required|integer|in:30,60,90,180,365',
                'mode' => 'required|string|in:external_agent,in_repo',
            ]);

            $settings->put('backup_relay.frequency', $validated['frequency']);
            $settings->put('backup_relay.retention_days', (int) $validated['retention_days']);
            $settings->put('backup_relay.mode', $validated['mode']);

            if ($validated['mode'] === 'external_agent') {
                try {
                    Artisan::call('clockwork:push-backup-relay-targets');
                } catch (Throwable) {
                    // Ignore transient push failure
                }
            }

            $freqLabel = match ($validated['frequency']) {
                'weekly' => '1 a week (weekly)',
                'twice_weekly' => '2 a week (twice weekly)',
                'daily' => 'Daily',
                default => $validated['frequency'],
            };
            $msg = "Backup relay policy updated: {$freqLabel} with {$validated['retention_days']} days retention.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'frequency' => $validated['frequency'],
                    'retention_days' => (int) $validated['retention_days'],
                    'mode' => $validated['mode'],
                ]);
            }

            return back()->with('status', $msg);
        }

        if ($request->has('site_id')) {
            $validated = $request->validate([
                'site_id' => 'required|integer|exists:sites,id',
                'enabled' => 'required|boolean',
            ]);

            $site = Site::query()->findOrFail($validated['site_id']);
            $site->update([
                'backup_relay_enabled' => (bool) $validated['enabled'],
            ]);

            // If in external_agent mode, sync S3 targets manifest
            $mode = (string) $settings->get('backup_relay.mode', config('clockwork.backup_relay.mode', 'in_repo'));
            if ($mode === 'external_agent') {
                try {
                    Artisan::call('clockwork:push-backup-relay-targets');
                } catch (Throwable) {
                    // Ignore transient push failure
                }
            }

            $msg = ($site->backup_relay_enabled ? 'Enabled' : 'Disabled')." backup relay for {$site->domain}.";

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => true,
                    'message' => $msg,
                    'enabled' => (bool) $site->backup_relay_enabled,
                ]);
            }

            return back()->with('status', $msg);
        }

        $validated = $request->validate([
            'sites' => 'nullable|array',
            'sites.*' => 'integer|exists:sites,id',
        ]);

        $enabledIds = $validated['sites'] ?? [];

        Site::query()
            ->whereIn('id', $enabledIds)
            ->update(['backup_relay_enabled' => true]);

        Site::query()
            ->whereNotIn('id', $enabledIds)
            ->update(['backup_relay_enabled' => false]);

        $mode = (string) $settings->get('backup_relay.mode', config('clockwork.backup_relay.mode', 'in_repo'));
        if ($mode === 'external_agent') {
            try {
                Artisan::call('clockwork:push-backup-relay-targets');
            } catch (Throwable) {
                // Ignore transient push failure
            }
        }

        $msg = 'Backup relay settings updated for '.count($enabledIds).' site(s).';

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
                'enabled_count' => count($enabledIds),
            ]);
        }

        return back()->with('status', $msg);
    }

    public function runNow(Request $request, Settings $settings): RedirectResponse|JsonResponse
    {
        $mode = (string) $settings->get('backup_relay.mode', config('clockwork.backup_relay.mode', 'in_repo'));

        if ($mode === 'external_agent') {
            $msg = 'Backup relay is operating in external agent mode. Backups are dispatched and processed by your external agent droplet.';

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json([
                    'success' => false,
                    'message' => $msg,
                ], 400);
            }

            return back()->with('warning', $msg);
        }

        $result = app(BackgroundArtisan::class)->start(
            'backup_relay.run',
            ['clockwork:backup-relay-run'],
            7200,
            'backup-relay-run-bg',
        );

        if ($result->alreadyRunning()) {
            $msg = 'A backup relay run is already in progress.';

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $msg], 409);
            }

            return back()->with('warning', $msg);
        }

        if ($result->failed()) {
            $msg = $result->error ?? 'Could not start the backup relay run.';

            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => $msg], 500);
            }

            return back()->with('error', $msg);
        }

        $msg = 'Backup relay started in the background. This can take a while — check this page again when the latest run appears.';

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $msg,
            ]);
        }

        return back()->with('status', $msg);
    }

    public function archives(Site $site, BackupArchiveEnumerator $enumerator): JsonResponse
    {
        $data = $enumerator->forSite($site);

        return response()->json(array_merge([
            'success' => true,
            'site_id' => $site->id,
            'domain' => $site->domain,
        ], $data));
    }

    public function download(Site $site, Request $request, BackupArchiveEnumerator $enumerator, ActionLogger $logger): mixed
    {
        $rawKey = $request->query('key');
        if (! $rawKey) {
            abort(400, 'Missing key parameter.');
        }

        $key = base64_decode((string) $rawKey, true);
        if ($key === false || $key === '') {
            abort(400, 'Invalid key encoding.');
        }

        if (! $enumerator->belongsToSite($site, $key)) {
            abort(403, 'Unauthorized access to archive object.');
        }

        $disk = $enumerator->disk();
        if (! $disk->exists($key)) {
            abort(404, 'Archive object not found in S3.');
        }

        $logger->record(
            actionType: ActionLog::TYPE_BACKUP_RELAY_DOWNLOADED,
            summary: "Downloaded backup-relay archive for {$site->domain}.",
            ok: true,
            site: $site,
            target: $key,
            actor: (string) (Auth::user()->email ?? 'unknown'),
        );

        try {
            if (method_exists($disk, 'temporaryUrl')) {
                return redirect()->away($disk->temporaryUrl($key, now()->addHours(1)));
            }
        } catch (Throwable) {
            // Stream through if temporaryUrl is unsupported
        }

        return $disk->download($key, basename($key));
    }
}
