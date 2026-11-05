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
     * Words that make a contact's stored name an organisation's rather than
     * a person's ("St James Church", "Example Design Group"), lower case.
     */
    private const ORGANISATION_WORDS = [
        'academy', 'agency', 'and', 'association', 'bank', 'board', 'cafe', 'capital', 'center', 'centre',
        'church', 'cid', 'clinic', 'club', 'co', 'coalition', 'college', 'committee', 'company', 'consulting',
        'corp', 'corporation', 'council', 'department', 'dept', 'design', 'designs', 'enterprises', 'firm',
        'for', 'foundation', 'fund', 'gallery', 'global', 'group', 'health', 'holdings', 'hospital', 'hotel',
        'inc', 'industries', 'info', 'inn', 'institute', 'interiors', 'international', 'law', 'library', 'llc',
        'llp', 'ltd', 'marketing', 'media', 'ministries', 'ministry', 'museum', 'national', 'network', 'of',
        'office', 'partners', 'pllc', 'program', 'programme', 'project', 'properties', 'realty', 'resort',
        'restaurant', 'sales', 'salon', 'school', 'services', 'shop', 'society', 'solutions', 'spa', 'store',
        'studio', 'studios', 'support', 'team', 'the', 'trust', 'university', 'web', 'website',
    ];

    /**
     * The first name to greet a contact by ("Jane" for "Jane Example"), or
     * null when the stored name isn't plainly one person's: an organisation
     * ("Example Institute", an acronym, a name with an organisation word or
     * more than three words), an initial ("J. Example"), a name with a note
     * in brackets or with digits or symbols ("Jane (Example Co)"), or one
     * not written as a name (all lower case, or run together with capitals
     * inside, "ExampleSupply"). Null greets "Hi there,", which is never
     * wrong; a guessed name can be.
     */
    public static function firstName(?string $name): ?string
    {
        $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
        if ($name === '' || preg_match('/[()\[\]{}\/\\\\&@,+#:;!?"_\d]/u', $name)) {
            return null;
        }

        $words = explode(' ', $name);
        if (count($words) > 3) {
            return null;
        }

        foreach ($words as $word) {
            if (in_array(mb_strtolower(rtrim($word, '.')), self::ORGANISATION_WORDS, true)
                || (mb_strlen($word) > 1 && preg_match('/^\p{Lu}+$/u', $word))) {
                return null;
            }
        }

        $first = $words[0];

        return mb_strlen($first) >= 2 && preg_match("/^\p{Lu}\p{Ll}*(?:['’\-]\p{Lu}?\p{Ll}+)*$/u", $first)
            ? $first
            : null;
    }

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
     * "Oct 3, 2026": a stored time's date in the report timezone, or null
     * when there's no (readable) time.
     */
    public static function date(mixed $at): ?string
    {
        if (! is_string($at) || trim($at) === '') {
            return null;
        }

        try {
            return Carbon::parse($at)->setTimezone(self::timezone())->format('M j, Y');
        } catch (Throwable) {
            return null;
        }
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
     * Whether uptime monitoring began only after the report's period had
     * ended, so the uptime section has nothing to say about it (a report
     * compiled late for a past month). Reports compiled before uptime kept
     * its own start fall back to meta.coverage_start.
     *
     * @param  array<string, mixed>  $data  a report's sections_data
     */
    public static function monitoringBeganAfterPeriod(array $data): bool
    {
        return self::beganAfterPeriod($data['uptime']['monitored_since'] ?? $data['meta']['coverage_start'] ?? null, $data);
    }

    /**
     * Whether a stored time falls after the period's last day, as the report
     * timezone reads it (the zone its dates are shown in).
     *
     * @param  array<string, mixed>  $data  a report's sections_data
     */
    public static function beganAfterPeriod(mixed $began, array $data): bool
    {
        $periodEnd = $data['meta']['period_end'] ?? null;
        if (self::monitoringBeganOn($began) === null || ! is_string($periodEnd) || $periodEnd === '') {
            return false;
        }

        try {
            return Carbon::parse($began)->greaterThan(Carbon::parse($periodEnd, self::timezone())->endOfDay());
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Whether two stored times fall on different days as the report
     * timezone reads them, the first one later.
     */
    public static function laterDay(mixed $at, mixed $than): bool
    {
        if (self::monitoringBeganOn($at) === null) {
            return false;
        }
        if (self::monitoringBeganOn($than) === null) {
            return true;
        }

        $zone = self::timezone();

        return Carbon::parse($at)->setTimezone($zone)->toDateString() > Carbon::parse($than)->setTimezone($zone)->toDateString();
    }

    /**
     * "Monitoring began Oct 19, so this report covers Oct 19 – Oct 31." for a
     * site added after the period started, so a late enrolment doesn't read
     * as a whole month of care. Null otherwise. Uptime monitoring switched on
     * later than that isn't this: the uptime line says so on its own.
     *
     * @param  array<string, mixed>  $data  a report's sections_data
     */
    public static function coverageNote(array $data): ?string
    {
        $began = self::monitoringBeganOn($data['meta']['coverage_start'] ?? null);
        if ($began === null) {
            return null;
        }

        // A care plan switched on mid-month: the site may have been watched
        // before, but it wasn't being looked after.
        $subject = ($data['meta']['coverage_source'] ?? null) === 'care_plan' ? 'Your care plan' : 'Monitoring';

        if (self::beganAfterPeriod($data['meta']['coverage_start'], $data)) {
            return "{$subject} began {$began}, after the end of this report's period.";
        }

        $periodEnd = $data['meta']['period_end'] ?? null;

        return is_string($periodEnd) && $periodEnd !== ''
            ? "{$subject} began {$began}, so this report covers {$began} – ".Carbon::parse($periodEnd)->format('M j').'.'
            : "{$subject} began {$began}.";
    }

    /**
     * "Down Oct 5, 3:12 PM EDT", in the report timezone. An outage already
     * open when the period began has its start clamped to the period start,
     * which isn't when it began, so it reads "Down since before Oct 1": the
     * period's first day in the report timezone. Reports compiled before the
     * period was measured in that zone clamped to midnight UTC, which is
     * read as the UTC date it is.
     *
     * @param  array<string, mixed>  $outage  one of sections_data['uptime']['outages']
     */
    public static function outageStart(array $outage): string
    {
        $at = Carbon::parse((string) $outage['started_at']);

        if (! empty($outage['started_before_period'])) {
            $utc = $at->copy()->utc();
            $legacyUtcStart = $utc->format('H:i:s') === '00:00:00';

            return 'Down since before '.($legacyUtcStart ? $utc : $at->setTimezone(self::timezone()))->format('M j');
        }

        return 'Down '.$at->setTimezone(self::timezone())->format('M j, g:i A T');
    }

    /**
     * What the updates section says when no update is listed. Only what's
     * known: nothing was waiting at the end of the month (the snapshot said
     * so), never that no updates were needed all month, since something
     * other than Control can apply one. When something was waiting, or we
     * can't tell (including reports compiled before waiting updates were
     * recorded), just that none were applied.
     *
     * @param  array<string, mixed>  $updates  sections_data['updates']
     */
    public static function noUpdatesLine(array $updates): string
    {
        return array_key_exists('pending_plugins', $updates) && ! ClientReportCompiler::updatesWereWaiting($updates)
            ? 'Everything was up to date at the end of the month.'
            : 'No updates were applied this month.';
    }

    /** At or above this availability the figure reads as good. */
    public const UPTIME_GOOD = 99.9;

    /** At or above this (and below UPTIME_GOOD) it reads as a warning; below it, as bad. */
    public const UPTIME_FAIR = 99.0;

    /**
     * 'good', 'warn' or 'bad' for an availability percentage, so a month
     * at 97% isn't shown in the colour of a perfect one; null when there's
     * no figure.
     */
    public static function uptimeTone(mixed $percentage): ?string
    {
        if (! is_numeric($percentage)) {
            return null;
        }

        return match (true) {
            (float) $percentage >= self::UPTIME_GOOD => 'good',
            (float) $percentage >= self::UPTIME_FAIR => 'warn',
            default => 'bad',
        };
    }

    /**
     * "40.7 GB", "512 MB": data transferred, from traffic.bandwidth_mb, or
     * null when the host reported no byte counts.
     */
    public static function bandwidth(mixed $megabytes): ?string
    {
        $mb = is_numeric($megabytes) ? (float) $megabytes : 0.0;
        if ($mb <= 0) {
            return null;
        }

        return $mb >= 1024 ? number_format($mb / 1024, 1).' GB' : number_format($mb, 0).' MB';
    }

    /**
     * "Files & database", "Database" or "Files": what a day's backups held,
     * from one of backups.recent's kinds lists.
     *
     * @param  array<int, mixed>  $kinds
     */
    public static function backupKinds(array $kinds): string
    {
        return match (true) {
            in_array(SiteBackup::KIND_FULL, $kinds, true),
            in_array(SiteBackup::KIND_FILES, $kinds, true) && in_array(SiteBackup::KIND_DATABASE, $kinds, true) => 'Files & database',
            in_array(SiteBackup::KIND_DATABASE, $kinds, true) => 'Database',
            default => 'Files',
        };
    }

    /** "45 min", "6h 19m": a length of time the way the email's outage list shows it. */
    public static function duration(int $minutes): string
    {
        return $minutes >= 60 ? intdiv($minutes, 60).'h '.($minutes % 60).'m' : max(0, $minutes).' min';
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
     * Why the "Malicious visitors blocked" count is hidden, as a note for
     * the operator, or null when it's shown (or the report predates the
     * reason being kept and has no unbanned lockouts to explain it).
     *
     * @param  array<string, mixed>  $security  sections_data['security']
     */
    public static function blockedVisitorsNote(array $security): ?string
    {
        if (($security['blocked_threats_count'] ?? null) !== null) {
            return null;
        }

        $lockouts = (int) ($security['unbanned_lockouts'] ?? 0);
        $reason = match ($security['blocks_unmeasured'] ?? ($lockouts > 0 ? 'unbanned_lockouts' : null)) {
            'unbanned_lockouts' => $lockouts.' '.Str::plural('lockout', $lockouts).' this period never became a ban, so a count would leave those visitors out',
            'ingestion_stopped' => 'no lockout from any site has reached Control since '
                .(self::date($security['lockouts_last_ingested_at'] ?? null) ?? 'well before the period end')
                .', so a count would cover only part of the period',
            'no_lockout_feed' => "nothing shows this site's lockouts reached Control all period (no blocker reporting to Control now, "
                ."no provisioned fail2ban jail, or the server's lockouts weren't pulled at the end of the period)",
            default => null,
        };

        return $reason !== null ? 'Malicious visitors blocked hidden from the client: '.$reason.'.' : null;
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
