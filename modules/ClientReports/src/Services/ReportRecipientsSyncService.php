<?php

namespace Modules\ClientReports\Services;

use App\Models\Site;
use App\Services\ActionLog\ActionLogger;
use App\Services\Companion\ClockworkCompanionClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Models\ClientReportTemplate;
use Throwable;

/**
 * Synchronizes per-site Care Plan report recipients, frequency, and enabled
 * status bidirectionally between Clockwork Control (`ClientReportSchedule`)
 * and WordPress (`ClientNotifications` in Companion / Renegade).
 */
class ReportRecipientsSyncService
{
    /**
     * Upper bound on recipients accepted from a site. WordPress is an untrusted
     * source, so a compromised site can't turn report delivery into a mass mailer.
     */
    public const MAX_WP_RECIPIENTS = 10;

    /**
     * Build the payload to push to WordPress `/client-notifications`.
     *
     * @return array{
     *     report_recipients: list<string>,
     *     report_frequency: string,
     *     report_enabled: bool,
     *     report_last_sent_at: ?string,
     *     report_recipients_updated_at: string
     * }
     */
    public function resolvePayloadForSite(Site $site, ?ClientReportSchedule $schedule = null): array
    {
        $schedule ??= ClientReportSchedule::where('site_id', $site->id)->first();

        if ($schedule !== null) {
            return [
                'report_recipients' => $this->sanitizeEmails($schedule->recipients ?? []),
                'report_frequency' => in_array($schedule->frequency, ['weekly', 'monthly'], true)
                    ? $schedule->frequency
                    : ClientReportSchedule::FREQUENCY_MONTHLY,
                'report_enabled' => (bool) $schedule->is_enabled,
                'report_last_sent_at' => $schedule->last_sent_at?->toIso8601String(),
                'report_recipients_updated_at' => ($schedule->updated_at ?? now())->toIso8601String(),
            ];
        }

        $fallbackRecipients = [];
        if ($site->client) {
            $fallbackRecipients = $this->sanitizeEmails($site->client->allRecipients());
        } elseif (! empty($site->client_email)) {
            $fallbackRecipients = $this->sanitizeEmails([$site->client_email]);
        }

        return [
            'report_recipients' => $fallbackRecipients,
            'report_frequency' => ClientReportSchedule::FREQUENCY_MONTHLY,
            'report_enabled' => (bool) $site->isCarePlanActive(),
            'report_last_sent_at' => null,
            'report_recipients_updated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Push the site's current report schedule settings to WordPress.
     * Best-effort: logs warnings on transport failure without throwing.
     */
    public function pushToSite(Site $site, ?ClientReportSchedule $schedule = null, bool $scheduleRemoved = false): bool
    {
        if (! $site->companion_installed || empty($site->companion_secret)) {
            return false;
        }

        $payload = $this->resolvePayloadForSite($site, $schedule);

        // A deleted schedule means no reports go out, so WordPress must not show "Active".
        if ($scheduleRemoved) {
            $payload['report_enabled'] = false;
        }

        try {
            $client = new ClockworkCompanionClient($site);
            $response = $client->pushClientNotifications($payload);

            if (is_array($site->companion_snapshot)) {
                $snapshot = $site->companion_snapshot;
                $snapshot['client_notifications'] = array_merge(
                    is_array($snapshot['client_notifications'] ?? null) ? $snapshot['client_notifications'] : [],
                    is_array($response['client_notifications'] ?? null) ? $response['client_notifications'] : $payload,
                    ['report_recipients_updated_by' => 'control'],
                );
                $site->forceFill(['companion_snapshot' => $snapshot])->save();
            }

            return true;
        } catch (Throwable $e) {
            Log::warning('client_reports.push_recipients_failed', [
                'site' => $site->domain,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Reconcile the `client_notifications` block from a freshly pulled
     * `/snapshot` payload into `ClientReportSchedule` (or push Control's
     * schedule down to WordPress if WordPress hasn't been initialized yet).
     *
     * @param  array<string, mixed>  $snapshotPayload
     */
    public function reconcileFromSnapshot(Site $site, array $snapshotPayload): void
    {
        $cn = $snapshotPayload['client_notifications'] ?? null;
        if (! is_array($cn) || ! array_key_exists('report_recipients', $cn)) {
            return;
        }

        $wpRecipients = array_slice(
            $this->sanitizeEmails($cn['report_recipients'] ?? []),
            0,
            self::MAX_WP_RECIPIENTS,
        );
        $wpFrequency = in_array($cn['report_frequency'] ?? 'monthly', ['weekly', 'monthly'], true)
            ? (string) $cn['report_frequency']
            : ClientReportSchedule::FREQUENCY_MONTHLY;
        $wpEnabled = array_key_exists('report_enabled', $cn) ? (bool) $cn['report_enabled'] : true;
        // WordPress may pause a schedule but can't enable one for a site without an active care plan.
        $wpEnabled = $wpEnabled && $site->isCarePlanActive();
        $updatedBy = $cn['report_recipients_updated_by'] ?? null;
        $updatedAtRaw = is_string($cn['report_recipients_updated_at'] ?? null) && $cn['report_recipients_updated_at'] !== ''
            ? $cn['report_recipients_updated_at']
            : null;

        $schedule = ClientReportSchedule::where('site_id', $site->id)->first();

        // Case 1: WordPress administrator saved changes in WP Admin
        if ($updatedBy === 'wp_admin') {
            $wpUpdatedAt = null;
            if ($updatedAtRaw !== null) {
                try {
                    $wpUpdatedAt = Carbon::parse($updatedAtRaw);
                } catch (Throwable) {
                    $wpUpdatedAt = null;
                }
            }

            if ($schedule === null) {
                if ($wpRecipients === [] && $wpEnabled) {
                    return;
                }

                $defaultTemplate = ClientReportTemplate::query()
                    ->orderByDesc('is_default')
                    ->orderBy('name')
                    ->first();

                $schedule = ClientReportSchedule::create([
                    'site_id' => $site->id,
                    'template_id' => $defaultTemplate?->id,
                    'frequency' => $wpFrequency,
                    'delivery_mode' => $wpRecipients !== []
                        ? ClientReportSchedule::MODE_AUTO
                        : ClientReportSchedule::MODE_DRAFT,
                    'recipients' => $wpRecipients,
                    'is_enabled' => $wpEnabled,
                    'next_run_at' => (new ClientReportSchedule(['frequency' => $wpFrequency]))->computeNextRun(),
                ]);

                $this->logWpSync($site, [], $wpRecipients, $wpFrequency, $schedule->is_enabled);

                return;
            }

            $isNewer = $wpUpdatedAt === null
                || $schedule->updated_at === null
                || $wpUpdatedAt->gt($schedule->updated_at);

            if (! $isNewer) {
                // Control is newer. If WordPress still shows older values (e.g. an
                // earlier push failed), push Control's state down again.
                if ($this->wordpressDiffers($schedule, $cn)) {
                    $this->pushToSite($site, $schedule);
                }

                return;
            }

            $currentRecipients = $this->sanitizeEmails($schedule->recipients ?? []);
            $recipientsChanged = $currentRecipients !== $wpRecipients;
            $frequencyChanged = $schedule->frequency !== $wpFrequency;
            $enabledChanged = (bool) $schedule->is_enabled !== $wpEnabled;

            if ($recipientsChanged || $frequencyChanged || $enabledChanged) {
                $updates = [
                    'recipients' => $wpRecipients,
                    'frequency' => $wpFrequency,
                    'is_enabled' => $wpEnabled,
                ];

                if ($frequencyChanged) {
                    $updates['next_run_at'] = (new ClientReportSchedule(['frequency' => $wpFrequency]))->computeNextRun();
                }

                if ($wpRecipients === [] && $schedule->delivery_mode === ClientReportSchedule::MODE_AUTO) {
                    $updates['delivery_mode'] = ClientReportSchedule::MODE_DRAFT;
                }

                $schedule->fill($updates);
                $schedule->updated_at = now();
                $schedule->save();

                $this->logWpSync($site, $currentRecipients, $wpRecipients, $wpFrequency, $wpEnabled);

                // Echo back anything we clamped (recipient cap, care-plan gate) so WordPress shows the truth.
                if ($this->wordpressDiffers($schedule, $cn)) {
                    $this->pushToSite($site, $schedule);
                }
            } else {
                // Advance schedule updated_at so we don't re-evaluate on future snapshots
                $schedule->touch();
            }

            return;
        }

        // Case 2: WordPress has never been initialized with recipients yet,
        // and Control has a schedule (or client recipients on an active care plan).
        if ($updatedAtRaw === null && $wpRecipients === []) {
            $resolved = $this->resolvePayloadForSite($site, $schedule);
            if ($schedule !== null || ! empty($resolved['report_recipients'])) {
                $this->pushToSite($site, $schedule);
            }
        }
    }

    /**
     * Whether the WordPress-reported settings differ from the Control schedule.
     *
     * @param  array<string, mixed>  $cn
     */
    private function wordpressDiffers(ClientReportSchedule $schedule, array $cn): bool
    {
        $wpFrequency = in_array($cn['report_frequency'] ?? 'monthly', ['weekly', 'monthly'], true)
            ? (string) $cn['report_frequency']
            : ClientReportSchedule::FREQUENCY_MONTHLY;

        return $this->sanitizeEmails($cn['report_recipients'] ?? []) !== $this->sanitizeEmails($schedule->recipients ?? [])
            || $wpFrequency !== $schedule->frequency
            || (array_key_exists('report_enabled', $cn) ? (bool) $cn['report_enabled'] : true) !== (bool) $schedule->is_enabled;
    }

    /**
     * @return list<string>
     */
    public function sanitizeEmails(mixed $raw): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $clean = [];
        foreach ($raw as $item) {
            $email = strtolower(trim((string) $item));
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && ! in_array($email, $clean, true)) {
                $clean[] = $email;
            }
        }

        return $clean;
    }

    /**
     * @param  list<string>  $previous
     * @param  list<string>  $current
     */
    private function logWpSync(Site $site, array $previous, array $current, string $frequency, bool $enabled): void
    {
        try {
            app(ActionLogger::class)->record(
                actionType: 'client_report_recipients_synced',
                summary: sprintf(
                    'Synced Care Plan report settings from WordPress on %s (%s, %s: %s)',
                    $site->domain,
                    $enabled ? 'enabled' : 'paused',
                    $frequency,
                    $current !== [] ? implode(', ', $current) : 'no recipients',
                ),
                site: $site,
                target: 'client-notifications',
                details: [
                    'previous_recipients' => $previous,
                    'recipients' => $current,
                    'frequency' => $frequency,
                    'is_enabled' => $enabled,
                    'source' => 'wp_admin',
                ],
            );
        } catch (Throwable) {
            // Non-fatal
        }
    }
}
