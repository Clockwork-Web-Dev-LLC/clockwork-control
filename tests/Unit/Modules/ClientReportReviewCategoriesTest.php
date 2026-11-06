<?php

use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Services\ScheduledReportRunner;
use Modules\ClientReports\Support\ReportState;
use Modules\ClientReports\Support\ReviewReasonCategories as Categories;

/*
|--------------------------------------------------------------------------
| Sorting stored review reasons into categories, and a report's state
|--------------------------------------------------------------------------
|
| The reports list filters and chips read meta.review_reasons, which are
| plain sentences. These pin the wording each category is recognised by,
| using sentences in the shape ReportReviewFlags writes them.
*/

describe('ReviewReasonCategories', function () {
    it('puts each kind of hold reason in its category', function (string $reason, string $category) {
        expect(Categories::categorize($reason))->toBe($category);
    })->with([
        'first report' => [ScheduledReportRunner::FIRST_REPORT_REASON, Categories::FIRST_REPORT],
        'malware flagged' => ['The malware scan flagged files to review: php_in_uploads ×2 (scan #1). The scan also stopped before it finished.', Categories::MALWARE],
        'malware cleaned' => ['An earlier malware scan this month flagged malware (on 2 days), but the latest scan did not: check it was cleaned up.', Categories::MALWARE],
        'core files' => ['WordPress core files differ from the official release.', Categories::MALWARE],
        'core file check' => ['No WordPress core-file check ran this month, although this host supports one, so the client would read "Not checked this month".', Categories::MALWARE],
        'blocklist' => ['The site is on a spam or malware blocklist.', Categories::MALWARE],
        'paused vulnerable plugin' => ['Updates are paused for a plugin with a known vulnerability: Example Plugin 1.0 (fixed in 1.1; auto-paused on 2026-10-19 after 5 failed attempts; 1.2 is waiting).', Categories::VULNERABILITIES],
        'waiting vulnerable plugin' => ['A plugin with a known vulnerability has an update waiting: Example Plugin.', Categories::VULNERABILITIES],
        'no backups' => ['No backups were found this month.', Categories::BACKUPS],
        'few backup days' => ['Backed up on only 4 of 31 days this month.', Categories::BACKUPS],
        'relay failing' => ['Off-site backups have been failing since Oct 3.', Categories::BACKUPS],
        'no updates applied' => ['No updates were applied this month although updates were waiting: Example Plugin, so the client would read "No updates were applied this month". Check why they are failing or paused.', Categories::UPDATES],
        'companion silent' => ['Companion hasn\'t reported since Oct 2, so waiting updates and plugin vulnerabilities are out of date.', Categories::UPDATES],
        'uptime unchecked' => ['Uptime monitoring is on, but the site hasn\'t been checked since Oct 2, so the Uptime section was left out.', Categories::UPTIME],
        'uptime began' => ['Uptime monitoring began Oct 12, so uptime covers only part of the month.', Categories::UPTIME],
        'still down' => ['The site was still down at the end of the month.', Categories::UPTIME],
        'probe blocked' => ['The uptime monitor was blocked (HTTP 429) from Oct 3, 9:00 AM; the site was not necessarily down.', Categories::UPTIME],
        'proxy traffic' => ['100% of requests came from two IP addresses, which looks like a proxy or gateway in front of the site rather than visitors, so the Traffic section was left out.', Categories::TRAFFIC],
        'lab forms' => ['Contact-form checks failed: the forms accepted 18 of 19 automated check submissions this month.', Categories::FORMS],
        'live forms' => ['Not every live contact-form test reported its email as sent: 75% of 4 test submissions did.', Categories::FORMS],
        'partial month' => ['Monitoring began Oct 12, so this report covers only part of the month.', Categories::PARTIAL_MONTH],
        'after period' => ['Monitoring began Nov 2, after the end of this report\'s period, so it covers none of it.', Categories::PARTIAL_MONTH],
        'unrecognised' => ['Something nobody has written a rule for yet.', Categories::OTHER],
    ]);

    it('groups a report\'s reasons by category in the order they first appear, ignoring blanks', function () {
        $reasons = [
            ScheduledReportRunner::FIRST_REPORT_REASON,
            'No backups were found this month.',
            '',
            'The malware scan flagged something (scan #4).',
            'Backed up on only 4 of 31 days this month.',
            null,
        ];

        expect(Categories::group($reasons))->toBe([
            Categories::FIRST_REPORT => [ScheduledReportRunner::FIRST_REPORT_REASON],
            Categories::BACKUPS => ['No backups were found this month.', 'Backed up on only 4 of 31 days this month.'],
            Categories::MALWARE => ['The malware scan flagged something (scan #4).'],
        ])->and(Categories::forReasons($reasons))->toBe([Categories::FIRST_REPORT, Categories::BACKUPS, Categories::MALWARE]);
    });

    it('knows when a report is held only for being its schedule\'s first', function () {
        expect(Categories::onlyFirstReport([ScheduledReportRunner::FIRST_REPORT_REASON]))->toBeTrue()
            ->and(Categories::onlyFirstReport([ScheduledReportRunner::FIRST_REPORT_REASON, 'No backups were found this month.']))->toBeFalse()
            ->and(Categories::onlyFirstReport([]))->toBeFalse();
    });

    it('offers every category but "first report" as a list filter, each with a label, chip, icon and site tab', function () {
        $options = Categories::filterOptions();

        expect($options)->not->toHaveKey(Categories::FIRST_REPORT)
            ->and(array_keys($options))->toContain(Categories::MALWARE, Categories::BACKUPS, Categories::UPDATES, Categories::VULNERABILITIES, Categories::PARTIAL_MONTH, Categories::TRAFFIC, Categories::UPTIME, Categories::FORMS)
            ->and(Categories::siteTab(Categories::MALWARE))->toBe('security')
            ->and(Categories::siteTab(Categories::FORMS))->toBe('forms')
            ->and(Categories::siteTab(Categories::FIRST_REPORT))->toBeNull()
            ->and(Categories::isKnown('nonsense'))->toBeFalse();
    });
});

describe('ReportState::classify', function () {
    it('works out where a report stands', function (string $status, array $sentTo, bool $stale, array $reasons, string $state) {
        expect(ReportState::classify($status, $sentTo, $stale, $reasons))->toBe($state);
    })->with([
        'replaced copy' => [ClientReport::STATUS_SUPERSEDED, [], false, ['No backups were found this month.'], ReportState::SUPERSEDED],
        'sent' => [ClientReport::STATUS_SENT, ['a@example.com'], true, [], ReportState::SENT],
        'partly sent' => [ClientReport::STATUS_GENERATED, ['a@example.com'], true, [], ReportState::PARTLY_SENT],
        'outdated beats held' => [ClientReport::STATUS_GENERATED, [], true, ['No backups were found this month.'], ReportState::OUTDATED],
        'held' => [ClientReport::STATUS_GENERATED, [], false, [ScheduledReportRunner::FIRST_REPORT_REASON, 'No backups were found this month.'], ReportState::NEEDS_REVIEW],
        'first report only' => [ClientReport::STATUS_GENERATED, [], false, [ScheduledReportRunner::FIRST_REPORT_REASON], ReportState::READY],
        'clean draft' => [ClientReport::STATUS_GENERATED, [], false, [], ReportState::READY],
    ]);
});
