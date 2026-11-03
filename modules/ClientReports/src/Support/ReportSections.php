<?php

namespace Modules\ClientReports\Support;

/**
 * Which sections of a compiled report the client sees.
 *
 * Decided once, from the report's sections_data, for the HTML email, the
 * plain-text email and the preview page (which is what prints as the PDF,
 * and is also the public report view), so the three can't drift apart. A
 * section a template left out isn't compiled at all; one that was compiled
 * but has nothing true to show is hidden, and the operator preview says why.
 */
final class ReportSections
{
    /** How the operator notes name each section that can be hidden. */
    private const LABELS = [
        'uptime' => 'Uptime',
        'performance' => 'Speed & performance',
        'traffic' => 'Traffic',
        'forms' => 'Contact forms',
        'backups' => 'Backups',
        'work_log' => 'Work log',
    ];

    /**
     * @param  array<string, mixed>  $data  a report's sections_data
     * @return array<string, bool> section key => shown to the client
     */
    public static function visibility(array $data): array
    {
        $uptime = (array) ($data['uptime'] ?? []);
        $perf = (array) ($data['performance'] ?? []);
        $forms = (array) ($data['forms'] ?? []);

        return [
            'updates' => isset($data['updates']),
            // A site the uptime probe doesn't check has no uptime to show.
            'uptime' => isset($data['uptime']) && ($uptime['monitored'] ?? true),
            'security' => isset($data['security']),
            'performance' => isset($data['performance']) && ! empty($perf['has_performance'])
                && (! empty($perf['mobile']) || ! empty($perf['desktop'])),
            'traffic' => isset($data['traffic']) && ! empty($data['traffic']['has_traffic']),
            // Reports compiled before schema 2 counted form passes wrongly
            // (always 0%), so their forms numbers are never shown.
            'forms' => self::hasCurrentFormsData($data) && isset($data['forms'])
                && ((int) ($forms['live_tests'] ?? 0) > 0 || (int) ($forms['lab_tests'] ?? 0) > 0),
            // Only backups we actually saw recently; "none found", "stopped"
            // and "couldn't check" are for the operator, never the client.
            'backups' => isset($data['backups']) && ! empty($data['backups']['verified']),
            'work_log' => isset($data['work_log']) && ! empty($data['work_log']['entries']),
        ];
    }

    /**
     * Why each compiled section is hidden from the client, as a short note
     * for the operator preview. Sections that are shown, and sections the
     * report's template didn't include, aren't listed.
     *
     * @param  array<string, mixed>  $data  a report's sections_data
     * @return array<string, string> section key => note
     */
    public static function hiddenNotes(array $data): array
    {
        $notes = [];

        foreach (self::visibility($data) as $key => $shown) {
            if ($shown || ! isset($data[$key])) {
                continue;
            }

            $reason = self::hiddenReason($key, (array) $data[$key], $data);
            if ($reason !== null) {
                $notes[$key] = self::LABELS[$key].' hidden from the client: '.$reason.'.';
            }
        }

        return $notes;
    }

    /**
     * @param  array<string, mixed>  $section
     * @param  array<string, mixed>  $data
     */
    private static function hiddenReason(string $key, array $section, array $data): ?string
    {
        return match ($key) {
            'uptime' => ! empty($section['probe_stopped'])
                ? 'the uptime probe '.(! empty($section['last_checked_at']) ? "hasn't checked the site since {$section['last_checked_at']}" : 'has never checked the site')
                : "the uptime probe doesn't check this site",
            'performance' => 'no speed test ran in this period',
            'traffic' => 'no traffic was recorded for this period',
            'forms' => self::hasCurrentFormsData($data)
                ? 'no form tests ran in this period'
                : 'compiled before form results were counted correctly',
            'backups' => match ($section['status'] ?? null) {
                'none_found' => 'no backups were found this period',
                'stale' => 'no backups in the last 10 days of the period'
                    .(! empty($section['last_backup_at']) ? " (last one {$section['last_backup_at']})" : ''),
                'no_source' => 'there is no backup source for this host',
                default => 'backups could not be checked',
            },
            'work_log' => 'no hours were logged this period',
            default => null,
        };
    }

    /** @param  array<string, mixed>  $data */
    private static function hasCurrentFormsData(array $data): bool
    {
        return (int) ($data['meta']['schema_version'] ?? 1) >= 2;
    }
}
