<?php

namespace Modules\ClientReports\Support;

use App\Models\SiteBackup;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Modules\ClientReports\Services\ClientReportCompiler;
use Throwable;

/**
 * Wording shared by the HTML email, the plain-text email and the preview,
 * so a figure reads the same wherever the client sees it. Every method takes
 * a section of a stored report's sections_data and reads newer keys with
 * defaults, so reports compiled under older rules still render.
 */
final class ReportPhrases
{
    /** Where each backup source keeps a site's backups, as the end of "Your site was backed up …". */
    public const BACKUP_DESTINATIONS = [
        SiteBackup::SOURCE_PRESSABLE => 'by your host, Pressable',
        SiteBackup::SOURCE_SPINUPWP_SPACES => 'by SpinupWP to DigitalOcean Spaces',
        SiteBackup::SOURCE_S3_ARCHIVE => 'to independent off-site storage (Amazon S3)',
    ];

    /**
     * The timezone times in a report are shown in (clockwork.reports.timezone).
     * Stored times are UTC, which reads as the wrong time of day to clients.
     * An invalid setting falls back to UTC rather than breaking the email, as
     * does having no app config at all (the review flags are plain functions).
     */
    public static function timezone(): string
    {
        try {
            $zone = trim((string) config('clockwork.reports.timezone', 'America/New_York'));

            return (new DateTimeZone($zone !== '' ? $zone : 'UTC'))->getName();
        } catch (Throwable) {
            return 'UTC';
        }
    }

    /**
     * "Oct 30, 2:05 PM EDT": a stored time in the report timezone.
     */
    public static function time(string $at): string
    {
        return Carbon::parse($at)->setTimezone(self::timezone())->format('M j, g:i A T');
    }

    /**
     * "Oct 19": the day monitoring of the site began, in the report timezone,
     * from meta.coverage_start or uptime.monitored_since. Null when it
     * covered the whole period, and for reports compiled before it was kept.
     */
    public static function monitoringBeganOn(mixed $at): ?string
    {
        if (! is_string($at) || trim($at) === '') {
            return null;
        }

        try {
            return Carbon::parse($at)->setTimezone(self::timezone())->format('M j');
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whether monitoring began only after the report's period had ended, so
     * the report covers none of it (a report compiled late for a past month).
     *
     * @param  array<string, mixed>  $data  a report's sections_data
     */
    public static function monitoringBeganAfterPeriod(array $data): bool
    {
        $began = $data['meta']['coverage_start'] ?? $data['uptime']['monitored_since'] ?? null;
        $periodEnd = $data['meta']['period_end'] ?? null;
        if (self::monitoringBeganOn($began) === null || ! is_string($periodEnd) || $periodEnd === '') {
            return false;
        }

        try {
            return Carbon::parse($began)->greaterThan(Carbon::parse($periodEnd)->endOfDay());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * "Monitoring began Oct 19, so this report covers Oct 19 – Oct 31." for a
     * site whose monitoring began after the period started, so a late
     * enrolment doesn't read as a whole month of care. Null otherwise.
     *
     * @param  array<string, mixed>  $data  a report's sections_data
     */
    public static function coverageNote(array $data): ?string
    {
        $began = self::monitoringBeganOn($data['meta']['coverage_start'] ?? null);
        if ($began === null) {
            return null;
        }

        if (self::monitoringBeganAfterPeriod($data)) {
            return "Monitoring began {$began}, after the end of this report's period.";
        }

        $periodEnd = $data['meta']['period_end'] ?? null;

        return is_string($periodEnd) && $periodEnd !== ''
            ? "Monitoring began {$began}, so this report covers {$began} – ".Carbon::parse($periodEnd)->format('M j').'.'
            : "Monitoring began {$began}.";
    }

    /**
     * "Down Oct 5, 3:12 PM EDT", in the report timezone. An outage already
     * open when the period began has its start clamped to the period start,
     * which isn't when it began, so it reads "Down since before Oct 1".
     *
     * @param  array<string, mixed>  $outage  one of sections_data['uptime']['outages']
     */
    public static function outageStart(array $outage): string
    {
        $at = Carbon::parse((string) $outage['started_at']);

        if (! empty($outage['started_before_period'])) {
            return 'Down since before '.$at->format('M j');
        }

        return 'Down '.$at->setTimezone(self::timezone())->format('M j, g:i A T');
    }

    /**
     * The end of "Your site was backed up …" as a whole phrase, preposition
     * included ("by your host, Pressable"), or null when the source is unknown.
     *
     * @param  array<string, mixed>  $backups  sections_data['backups']
     */
    public static function backupDestination(array $backups): ?string
    {
        $source = $backups['source'] ?? null;
        if (is_string($source) && isset(self::BACKUP_DESTINATIONS[$source])) {
            return self::BACKUP_DESTINATIONS[$source];
        }

        $destination = trim((string) ($backups['destination'] ?? ''));
        if ($destination === '') {
            return null;
        }

        // Older reports stored only the place ("Host Automated Snapshots"),
        // and the templates put "by" in front of it.
        return preg_match('/^(by|to|in|on)\s/i', $destination) ? $destination : 'by '.$destination;
    }

    /**
     * "9 KB", "60 MB", "3.0 GB": a size the way the email's backups list shows it.
     */
    public static function bytes(int $bytes): string
    {
        return match (true) {
            $bytes >= 1024 ** 3 => number_format($bytes / 1024 ** 3, 1).' GB',
            $bytes >= 1024 ** 2 => number_format($bytes / 1024 ** 2, 0).' MB',
            default => number_format(max(1, $bytes / 1024), 0).' KB',
        };
    }

    /**
     * What's wrong with a report's traffic figures, as a clause for the
     * operator ("traffic was recorded on only 14 of 31 days"), or null when
     * nothing is: the figures are complete and believable, or the report was
     * compiled before traffic had a status.
     *
     * @param  array<string, mixed>  $traffic  sections_data['traffic']
     */
    public static function trafficProblem(array $traffic): ?string
    {
        $status = $traffic['status'] ?? null;
        $days = (int) ($traffic['days_covered'] ?? 0);
        $periodDays = (int) ($traffic['period_days'] ?? 0);
        $coverage = "traffic was recorded on only {$days} of {$periodDays} days";
        $fewDays = $periodDays > 0 && $days < $periodDays - 1;

        return match ($status) {
            'unsupported' => "traffic isn't collected for this host",
            'no_data' => "no traffic was recorded for this period, although this host's traffic is collected",
            'partial' => $fewDays ? $coverage : "the period's last day of traffic wasn't complete when the report was compiled",
            'suspect' => self::suspectTraffic($traffic).($fewDays ? "; also, {$coverage}" : ''),
            default => null,
        };
    }

    /** @param  array<string, mixed>  $traffic */
    private static function suspectTraffic(array $traffic): string
    {
        $share = $traffic['top_ip_share'] ?? null;
        if (($traffic['source'] ?? null) === 'nginx' && is_numeric($share) && (float) $share > ClientReportCompiler::TRAFFIC_TOP_IP_SHARE_MAX) {
            return (int) round((float) $share * 100).'% of requests came from two IP addresses, which looks like a proxy or gateway in front of the site rather than visitors';
        }

        return number_format((int) ($traffic['total_visits'] ?? 0)).' visits for '.number_format((int) ($traffic['total_requests'] ?? 0))
            ." requests doesn't look like real visitors";
    }

    /**
     * "None found", "2 found in 2 plugins (1 in inactive plugins)", or "Not
     * checked" when there was no plugin list or the feed was stale. Older
     * reports never actually checked, so a missing flag means not checked.
     *
     * @param  array<string, mixed>  $security  sections_data['security']
     */
    public static function vulnerabilities(array $security): string
    {
        if (! ($security['vulnerabilities_checked'] ?? false)) {
            return 'Not checked';
        }

        $vulns = (int) ($security['active_vulnerabilities'] ?? 0);
        if ($vulns === 0) {
            return 'None found';
        }

        $plugins = count((array) ($security['vulnerable_plugins'] ?? []));
        $inactive = (int) ($security['inactive_plugin_vulnerabilities'] ?? 0);

        return $vulns.' found'
            .($plugins > 0 ? ' in '.$plugins.' '.Str::plural('plugin', $plugins) : '')
            .($inactive > 0 ? ' ('.$inactive.' in inactive plugins)' : '');
    }

    /**
     * The names of the plugins with a known vulnerability, when any was found.
     *
     * @param  array<string, mixed>  $security  sections_data['security']
     * @return list<string>
     */
    public static function vulnerablePlugins(array $security): array
    {
        if (! ($security['vulnerabilities_checked'] ?? false) || (int) ($security['active_vulnerabilities'] ?? 0) === 0) {
            return [];
        }

        $names = array_map(fn ($name) => trim((string) $name), (array) ($security['vulnerable_plugins'] ?? []));

        return array_values(array_unique(array_filter($names, fn (string $name) => $name !== '')));
    }

    /**
     * "Nov 1": the date the plugin list was checked. It's the Companion
     * snapshot date, so it's often the day after the period ends; it says
     * when we looked, not the state over the whole period.
     *
     * @param  array<string, mixed>  $security  sections_data['security']
     */
    public static function vulnerabilitiesCheckedOn(array $security): ?string
    {
        return ! empty($security['vulnerabilities_as_of'])
            ? Carbon::parse((string) $security['vulnerabilities_as_of'])->format('M j')
            : null;
    }
}
