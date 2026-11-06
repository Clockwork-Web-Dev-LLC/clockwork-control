<?php

namespace Modules\ClientReports\Support;

use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;

/**
 * Where a report stands for the operator screens, worked out the same way
 * on every page (list, client page, history strip, preview, email version):
 *
 *  - sent          everyone on it has it
 *  - partly_sent   some recipients have it, the rest are still owed it
 *  - superseded    a newer copy for the same schedule and period replaced it
 *  - outdated      compiled under older report rules and not with anyone yet:
 *                  recompile before sending (ClientReport::isStale())
 *  - needs_review  held: something in it needs a person to check or fix it
 *  - ready         nothing to fix: a clean draft, or held only because it is
 *                  its schedule's first report, waiting for someone to send it
 */
final class ReportState
{
    public const SENT = 'sent';

    public const PARTLY_SENT = 'partly_sent';

    public const SUPERSEDED = 'superseded';

    public const OUTDATED = 'outdated';

    public const NEEDS_REVIEW = 'needs_review';

    public const READY = 'ready';

    /** Unsent states that need someone to act before the client has the report. */
    public const WAITING = [self::NEEDS_REVIEW, self::OUTDATED, self::READY, self::PARTLY_SENT];

    /** @var array<string, array{label: string, pill: string, icon: string, hint: string}> */
    private const META = [
        self::NEEDS_REVIEW => ['label' => 'Needs review', 'pill' => 'status-orange', 'icon' => 'fa-hand', 'hint' => 'Held: something in it needs checking or fixing before it goes out.'],
        self::OUTDATED => ['label' => 'Outdated', 'pill' => 'cr-pill-purple', 'icon' => 'fa-clock-rotate-left', 'hint' => 'Compiled before the latest report fixes. Recompile it before sending.'],
        self::READY => ['label' => 'Ready to send', 'pill' => 'cr-pill-blue', 'icon' => 'fa-paper-plane', 'hint' => 'Nothing to fix: waiting for someone to send it (a schedule\'s first report always waits).'],
        self::PARTLY_SENT => ['label' => 'Partly sent', 'pill' => 'status-yellow', 'icon' => 'fa-circle-half-stroke', 'hint' => 'Some recipients have it; sending again only emails the rest.'],
        self::SENT => ['label' => 'Sent', 'pill' => 'status-green', 'icon' => 'fa-circle-check', 'hint' => 'Everyone on it has it.'],
        self::SUPERSEDED => ['label' => 'Replaced', 'pill' => 'status-unknown', 'icon' => 'fa-clone', 'hint' => 'A newer copy for this period replaced it. It can\'t be sent.'],
    ];

    public static function of(ClientReport $report): string
    {
        return self::classify(
            (string) $report->status,
            (array) ($report->sent_to ?? []),
            $report->isStale(),
            self::reasons($report),
        );
    }

    /**
     * @param  list<string>  $sentTo
     * @param  iterable<mixed>  $reasons
     */
    public static function classify(string $status, array $sentTo, bool $stale, iterable $reasons): string
    {
        return match (true) {
            $status === ClientReport::STATUS_SUPERSEDED => self::SUPERSEDED,
            $status === ClientReport::STATUS_SENT => self::SENT,
            $sentTo !== [] => self::PARTLY_SENT,
            $stale => self::OUTDATED,
            ReviewReasonCategories::forReasons($reasons) !== [] && ! ReviewReasonCategories::onlyFirstReport($reasons) => self::NEEDS_REVIEW,
            default => self::READY,
        };
    }

    /**
     * What the operator should check before this report goes out: the stored
     * review reasons, or nothing once it has been sent.
     *
     * @return list<string>
     */
    public static function reasons(ClientReport $report): array
    {
        if ($report->status === ClientReport::STATUS_SENT) {
            return [];
        }

        $data = is_array($report->sections_data) ? $report->sections_data : [];

        return array_values(array_filter(
            (array) ($data['meta']['review_reasons'] ?? []),
            fn ($reason) => is_string($reason) && trim($reason) !== '',
        ));
    }

    /**
     * Why the Send button can't be used right now, or null when it can.
     */
    public static function sendBlockedReason(ClientReport $report): ?string
    {
        return match (true) {
            $report->status === ClientReport::STATUS_SUPERSEDED => 'A newer copy for this period replaced this one. Send that one instead.',
            empty($report->sent_to) && $report->isStale() => $report->canRecompile()
                ? 'Compiled before the latest report fixes: recompile it, check the new copy, then send that one.'
                : 'Compiled before the latest report fixes: generate a new report for this period instead.',
            $report->recipients() !== [] && $report->recipientsToSend() === [] => 'Everyone on this report already has it.',
            default => null,
        };
    }

    /**
     * The confirmation shown before a real send: who gets it, and what the
     * send changes. Sending a schedule's first report by hand is what lets
     * that schedule email its later clean reports by itself.
     */
    public static function sendConfirmation(ClientReport $report, string $glue = ' '): string
    {
        $to = $report->recipientsToSend() ?: $report->recipients();
        $lines = [$to === []
            ? 'Email this report? You will be asked for the address to send it to.'
            : 'Email report to '.implode(', ', $to).'?'];
        if ($report->status === ClientReport::STATUS_SENT) {
            array_unshift($lines, 'This report was already sent'.($report->sent_at ? ' on '.$report->sent_at->format('M j, Y') : '').'. Sending it again emails everyone on it a second copy.');
        }
        $lines[] = 'They get it straight away and the report is marked as sent.';

        $schedule = $report->schedule;
        if ($schedule !== null && $schedule->last_sent_at === null && $schedule->delivery_mode === ClientReportSchedule::MODE_AUTO) {
            $lines[] = 'This is the first report from this schedule: once it has been sent, the schedule emails its clean reports automatically every month (anything that needs a look is still held for review).';
        } elseif ($schedule !== null && $schedule->last_sent_at === null) {
            $lines[] = 'This is the first report sent from this schedule.';
        }

        return implode($glue, $lines);
    }

    /** The confirmation shown before a test copy goes to the operator. */
    public static function testConfirmation(?string $operatorEmail): string
    {
        return 'Send a test copy to '.($operatorEmail ?: 'your own address').'? Only you get it, with [TEST] in the subject. The client gets nothing, and the report and its schedule don\'t change.';
    }

    /** @return array<string, string> state => label, in the order the screens list them */
    public static function options(): array
    {
        return array_map(fn (array $meta) => $meta['label'], self::META);
    }

    public static function isKnown(?string $state): bool
    {
        return $state !== null && isset(self::META[$state]);
    }

    public static function label(string $state): string
    {
        return self::META[$state]['label'] ?? ucfirst(str_replace('_', ' ', $state));
    }

    /** CSS class of the status pill (the app's status-* palette, dark-mode aware). */
    public static function pill(string $state): string
    {
        return self::META[$state]['pill'] ?? 'status-unknown';
    }

    public static function icon(string $state): string
    {
        return self::META[$state]['icon'] ?? 'fa-circle';
    }

    public static function hint(string $state): string
    {
        return self::META[$state]['hint'] ?? '';
    }
}
