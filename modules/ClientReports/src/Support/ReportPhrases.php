<?php

namespace Modules\ClientReports\Support;

use App\Models\SiteBackup;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
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
     * An invalid setting falls back to UTC rather than breaking the email.
     */
    public static function timezone(): string
    {
        $zone = trim((string) config('clockwork.reports.timezone', 'America/New_York'));

        try {
            return (new DateTimeZone($zone !== '' ? $zone : 'UTC'))->getName();
        } catch (Throwable) {
            return 'UTC';
        }
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
