<?php

namespace App\Services\Security;

use App\Models\IgnoredIssue;
use App\Models\IgnoredWpAdmin;
use App\Models\Site;
use App\Support\Settings;
use Illuminate\Support\Collection;

class FleetAdminAuditor
{
    public const SETTING_DOMAINS = 'security.approved_admin_email_domains';

    public const SETTING_EMAILS = 'security.approved_admin_emails';

    public function __construct(
        protected Settings $settings,
    ) {}

    /**
     * @return list<string>
     */
    public function approvedDomains(): array
    {
        return $this->normalizeList($this->settings->get(self::SETTING_DOMAINS, []));
    }

    /**
     * @return list<string>
     */
    public function approvedEmails(): array
    {
        return $this->normalizeList($this->settings->get(self::SETTING_EMAILS, []), emails: true);
    }

    public function allowlistConfigured(): bool
    {
        return $this->approvedDomains() !== [] || $this->approvedEmails() !== [];
    }

    /**
     * Flatten every snapshot admin across companion-installed, monitored sites.
     *
     * @return list<array<string, mixed>>
     */
    public function inventory(): array
    {
        $ignoredBySite = IgnoredWpAdmin::query()
            ->get(['id', 'site_id', 'subject'])
            ->groupBy('site_id');

        $rows = [];
        foreach ($this->candidateSites() as $site) {
            $snapshotAdmins = is_array($site->companion_snapshot['admins']['admins'] ?? null)
                ? $site->companion_snapshot['admins']['admins']
                : [];

            foreach ($snapshotAdmins as $admin) {
                if (! is_array($admin)) {
                    continue;
                }
                $classified = $this->classify($admin);
                $subject = IgnoredWpAdmin::subjectFor(
                    (string) ($admin['email'] ?? ''),
                    (string) ($admin['login'] ?? ''),
                );
                $ignoredRow = ($ignoredBySite->get($site->id) ?? collect())
                    ->firstWhere('subject', $subject);
                $ignored = $ignoredRow !== null;

                $rows[] = [
                    'site_id' => $site->id,
                    'domain' => $site->domain,
                    'login' => (string) ($admin['login'] ?? ''),
                    'email' => (string) ($admin['email'] ?? ''),
                    'display_name' => (string) ($admin['display_name'] ?? ''),
                    'registered_at' => $admin['registered_at'] ?? null,
                    'last_seen_at' => $admin['last_seen_at'] ?? null,
                    'active_sessions' => (int) ($admin['active_sessions'] ?? 0),
                    'subject' => $subject,
                    'flags' => $classified['flags'],
                    'flagged' => $classified['flagged'] && ! $ignored,
                    'ignored' => $ignored,
                    'ignored_id' => $ignoredRow->id ?? null,
                ];
            }
        }

        usort($rows, function (array $a, array $b): int {
            return [$b['flagged'] ? 1 : 0, $a['domain'], $a['login']]
                <=> [$a['flagged'] ? 1 : 0, $b['domain'], $b['login']];
        });

        return $rows;
    }

    /**
     * Sites with at least one unignored flagged admin, excluding site-level Issues ignores.
     *
     * @return Collection<int, Site>
     */
    public function flaggedSites(): Collection
    {
        $ignoredSiteIds = IgnoredIssue::query()
            ->where('issue_type', IgnoredIssue::TYPE_WP_ADMIN_FLAGGED)
            ->pluck('site_id');

        $flaggedSiteIds = collect($this->inventory())
            ->where('flagged', true)
            ->pluck('site_id')
            ->unique()
            ->values();

        if ($flaggedSiteIds->isEmpty()) {
            return collect();
        }

        return Site::query()
            ->with('server:id,name,is_ignored')
            ->whereIn('id', $flaggedSiteIds)
            ->whereNotIn('id', $ignoredSiteIds)
            ->where('is_inactive', false)
            ->hostMonitored()
            ->orderBy('domain')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $admin
     * @return array{flagged: bool, flags: list<string>}
     */
    public function classify(array $admin): array
    {
        $flags = [];
        $login = strtolower(trim((string) ($admin['login'] ?? '')));
        $email = strtolower(trim((string) ($admin['email'] ?? '')));

        if ($login === 'admin') {
            $flags[] = 'default_login';
        }

        if ($this->allowlistConfigured() && ! $this->emailIsApproved($email)) {
            $flags[] = 'unapproved_email';
        }

        return [
            'flagged' => $flags !== [],
            'flags' => $flags,
        ];
    }

    public function emailIsApproved(string $email): bool
    {
        $email = strtolower(trim($email));
        if ($email === '') {
            return false;
        }

        if (in_array($email, $this->approvedEmails(), true)) {
            return true;
        }

        $at = strrpos($email, '@');
        if ($at === false) {
            return false;
        }

        $domain = substr($email, $at + 1);

        return in_array($domain, $this->approvedDomains(), true);
    }

    /**
     * @return Collection<int, Site>
     */
    private function candidateSites(): Collection
    {
        return Site::query()
            ->where('is_inactive', false)
            ->where('companion_installed', true)
            ->whereNotNull('companion_snapshot')
            ->hostMonitored()
            ->orderBy('domain')
            ->get(['id', 'domain', 'companion_snapshot', 'server_id', 'hosting_provider', 'is_inactive']);
    }

    /**
     * @return list<string>
     */
    private function normalizeList(mixed $raw, bool $emails = false): array
    {
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            $value = strtolower(trim((string) $item));
            $value = ltrim($value, '@');
            if ($value === '') {
                continue;
            }
            if ($emails && ! str_contains($value, '@')) {
                continue;
            }
            $out[] = $value;
        }

        return array_values(array_unique($out));
    }
}
