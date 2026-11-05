<?php

namespace Modules\ClientReports\Services;

use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\ClientManagement\Models\Client;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Models\ClientReportSchedule;
use Modules\ClientReports\Support\ReportListFilters;
use Modules\ClientReports\Support\ReportState;

/**
 * The Clients tab: every client with its sites, each site's care plan,
 * report schedule and recent report history, so an operator can see at a
 * glance who gets reports, what is waiting, and who has none set up.
 *
 * Sites without a client are gathered under one "Unassigned" entry, so
 * nothing on a care plan falls through the gaps. Archived sites are left
 * out (Site's global scope); inactive ones are listed but don't count as
 * missing a schedule.
 *
 * A row is a plain array:
 *   key        client id as a string, or "unassigned"
 *   client     ?Client
 *   name       what the list shows (the company, else the contact's name)
 *   contact    the contact's name when it differs from the name
 *   contacts   the client's email addresses
 *   sites      list of site rows (below)
 *   counts     sites, care, scheduled, missing, waiting, review
 *
 * A site row: site, eligible (care plan, or care plans off fleet-wide),
 * schedule (?ClientReportSchedule), schedule_state (scheduled|disabled|none),
 * missing (care plan, active, no schedule), reports (its reports, newest
 * period first, only loaded with history), months (Y-m => cell, last six
 * report periods: report, state).
 */
class ClientDirectory
{
    public const MONTHS = 6;

    /** A history-strip month with no report and no reason to expect one. */
    public const CELL_NONE = 'none';

    /** A month the schedule was set up for but the site had no care plan, so nothing was sent. */
    public const CELL_SKIPPED = 'skipped';

    public function __construct(protected ReportBrowser $browser) {}

    /**
     * The last report periods, oldest first, ending with last month.
     *
     * @return list<Carbon>
     */
    public function months(int $count = self::MONTHS): array
    {
        $last = now()->subMonthNoOverflow()->startOfMonth();

        return array_map(fn (int $i) => $last->copy()->subMonthsNoOverflow($i), range($count - 1, 0));
    }

    /**
     * Every client (and the Unassigned group, when it has sites), sorted by name.
     *
     * @param  bool  $withHistory  load every report of every site (the client page) instead of only the last months
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(bool $withHistory = false, ?string $only = null): Collection
    {
        $clients = Client::query()
            ->when($only !== null && $only !== ReportListFilters::UNASSIGNED, fn ($q) => $q->whereKey((int) $only))
            ->when($only === ReportListFilters::UNASSIGNED, fn ($q) => $q->whereRaw('1 = 0'))
            ->get(['id', 'name', 'company_name', 'email', 'additional_emails']);

        $sites = Site::query()
            ->when($only !== null, fn ($q) => $only === ReportListFilters::UNASSIGNED
                ? $q->whereNull('client_id')
                : $q->where('client_id', (int) $only))
            ->orderBy('domain')
            ->get(['id', 'domain', 'client_id', 'care_plan_enabled', 'is_inactive', 'created_at']);

        $schedules = ClientReportSchedule::query()
            ->whereIn('site_id', $sites->pluck('id'))
            ->get()
            ->keyBy('site_id');

        $months = $this->months();
        $reportQuery = $this->browser->query()->whereIn('site_id', $sites->pluck('id'));
        if (! $withHistory) {
            $reportQuery->where('period_start', '>=', $months[0]->toDateString())
                ->where('status', '!=', ClientReport::STATUS_SUPERSEDED);
        }
        $reportsBySite = $this->browser->get($reportQuery)
            ->sortByDesc(fn (ClientReport $r) => $r->period_start->format('Y-m-d').'-'.str_pad((string) $r->id, 12, '0', STR_PAD_LEFT))
            ->groupBy('site_id');

        $siteRows = $sites->map(fn (Site $site) => $this->siteRow($site, $schedules->get($site->id), $reportsBySite->get($site->id, collect()), $months))
            ->groupBy(fn (array $row) => $row['site']->client_id === null ? ReportListFilters::UNASSIGNED : (string) $row['site']->client_id);

        $rows = $clients->map(fn (Client $client) => $this->row(
            (string) $client->id,
            $client,
            $siteRows->get((string) $client->id, collect())->values()->all(),
        ));

        $unassigned = $siteRows->get(ReportListFilters::UNASSIGNED);
        if ($unassigned !== null && $unassigned->isNotEmpty()) {
            $rows->push($this->row(ReportListFilters::UNASSIGNED, null, $unassigned->values()->all()));
        }

        // Alphabetical, the Unassigned group last.
        return $rows
            ->sortBy(fn (array $row) => ($row['client'] === null ? '1' : '0').Str::lower($row['name']))
            ->values();
    }

    /** @return array<string, mixed>|null */
    public function find(string $key, bool $withHistory = true): ?array
    {
        return $this->rows($withHistory, $key)->first();
    }

    /**
     * Narrow the rows to the list's filters: q (client, contact, site or
     * recipient), care (yes: any site on a care plan, no: none), waiting
     * (a report waiting for review or sending) and missing (a care-plan site
     * with no schedule).
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function filter(Collection $rows, array $filters): Collection
    {
        $q = Str::lower(trim((string) ($filters['q'] ?? '')));
        $care = $filters['care'] ?? null;

        return $rows->filter(function (array $row) use ($q, $care, $filters) {
            if ($q !== '' && ! Str::contains($this->haystack($row), $q)) {
                return false;
            }
            if ($care === 'yes' && $row['counts']['care'] === 0) {
                return false;
            }
            if ($care === 'no' && $row['counts']['care'] > 0) {
                return false;
            }
            if (! empty($filters['waiting']) && $row['counts']['waiting'] === 0) {
                return false;
            }

            return empty($filters['missing']) || $row['counts']['missing'] > 0;
        })->values();
    }

    /** The list's default care-plan selection: care-plan clients, the rest folded away below. */
    public const CARE_DEFAULT = 'yes';

    /** The list's default order: clients needing attention first. */
    public const SORT_DEFAULT = 'attention';

    /**
     * The list filters a request asks for, defaults filled in: q, care
     * (yes | no | all), sort (attention | az), waiting, missing. queryFor()
     * turns them back into a query string without the defaults.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, string>
     */
    public static function filtersFrom(array $input): array
    {
        return array_filter([
            'q' => is_string($input['q'] ?? null) ? trim(mb_substr($input['q'], 0, 100)) : null,
            'care' => in_array($input['care'] ?? null, ['yes', 'no', 'all'], true) ? $input['care'] : self::CARE_DEFAULT,
            'sort' => in_array($input['sort'] ?? null, ['attention', 'az'], true) ? $input['sort'] : self::SORT_DEFAULT,
            'waiting' => ! empty($input['waiting']) ? '1' : null,
            'missing' => ! empty($input['missing']) ? '1' : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /**
     * The filters as query parameters for links, leaving out the defaults.
     *
     * @param  array<string, string|null>  $filters
     * @return array<string, string>
     */
    public static function queryFor(array $filters): array
    {
        return array_filter($filters, fn ($value, string $key) => $value !== null && $value !== ''
            && ! ($key === 'care' && $value === self::CARE_DEFAULT)
            && ! ($key === 'sort' && $value === self::SORT_DEFAULT), ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Split the rows into care-plan clients (any active site on a care plan)
     * and the rest, each row tagged with its 'section' (care | other). The
     * Unassigned group is split by site the same way, so care-plan sites
     * without a client sit with the care-plan clients.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, array<string, mixed>>}
     */
    public function split(Collection $rows): array
    {
        $care = collect();
        $other = collect();

        foreach ($rows as $row) {
            if ($row['client'] === null) {
                [$careSites, $otherSites] = collect($row['sites'])
                    ->partition(fn (array $s) => $s['eligible'] && ! $s['site']->is_inactive)
                    ->map(fn (Collection $sites) => $sites->values()->all())
                    ->all();
                if ($careSites !== []) {
                    $care->push(['section' => 'care'] + $this->row($row['key'], null, $careSites));
                }
                if ($otherSites !== []) {
                    $other->push(['section' => 'other'] + $this->row($row['key'], null, $otherSites));
                }

                continue;
            }

            $row['counts']['care'] > 0
                ? $care->push(['section' => 'care'] + $row)
                : $other->push(['section' => 'other'] + $row);
        }

        return [$care, $other];
    }

    /**
     * Order rows: 'attention' puts clients with a report waiting or a
     * care-plan site missing a schedule first, then A–Z; 'az' is A–Z. The
     * Unassigned group comes after the clients of its kind either way.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function sort(Collection $rows, string $sort = self::SORT_DEFAULT): Collection
    {
        return $rows->sortBy(fn (array $row) => sprintf(
            '%d%d%s',
            $sort === 'attention' && ($row['counts']['waiting'] > 0 || $row['counts']['missing'] > 0) ? 0 : 1,
            $row['client'] === null ? 1 : 0,
            Str::lower($row['name']),
        ))->values();
    }

    /**
     * The rows the list shows for its filters, in order, and the rows it
     * folds away below them: with "Care plan" selected, the care-plan
     * clients are listed and the others are folded into a collapsed "No care
     * plan" section; "No care plan" lists only those; "All" lists both,
     * care-plan clients first.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  rows()
     * @param  array<string, string>  $filters  filtersFrom()
     * @return array{listed: Collection<int, array<string, mixed>>, folded: Collection<int, array<string, mixed>>}
     */
    public function arrange(Collection $rows, array $filters): array
    {
        [$care, $other] = $this->split($rows);
        $narrow = ['care' => 'all'] + $filters;
        $sort = $filters['sort'] ?? self::SORT_DEFAULT;
        $care = $this->sort($this->filter($care, $narrow), $sort);
        $other = $this->sort($this->filter($other, $narrow), $sort);

        return match ($filters['care'] ?? self::CARE_DEFAULT) {
            'no' => ['listed' => $other, 'folded' => collect()],
            'all' => ['listed' => $care->concat($other)->values(), 'folded' => collect()],
            default => ['listed' => $care, 'folded' => $other],
        };
    }

    /**
     * @param  list<array<string, mixed>>  $sites
     * @return array<string, mixed>
     */
    protected function row(string $key, ?Client $client, array $sites): array
    {
        $company = trim((string) $client?->company_name);
        $person = trim((string) $client?->name);
        $name = $client === null ? 'Unassigned sites' : ($company !== '' ? $company : $person);

        $active = array_filter($sites, fn (array $s) => ! $s['site']->is_inactive);

        return [
            'key' => $key,
            'client' => $client,
            'name' => $name,
            'contact' => $client !== null && $person !== '' && $person !== $name ? $person : null,
            'contacts' => $client?->allRecipients() ?? [],
            'sites' => $sites,
            'counts' => [
                'sites' => count($sites),
                'care' => count(array_filter($active, fn (array $s) => $s['eligible'])),
                'scheduled' => count(array_filter($sites, fn (array $s) => $s['schedule_state'] === 'scheduled')),
                'missing' => count(array_filter($sites, fn (array $s) => $s['missing'])),
                'waiting' => array_sum(array_map(fn (array $s) => $s['waiting'], $sites)),
                'review' => array_sum(array_map(fn (array $s) => $s['review'], $sites)),
            ],
        ];
    }

    /**
     * @param  Collection<int, ClientReport>  $reports  newest period first
     * @param  list<Carbon>  $months
     * @return array<string, mixed>
     */
    protected function siteRow(Site $site, ?ClientReportSchedule $schedule, Collection $reports, array $months): array
    {
        $eligible = ScheduledReportRunner::siteIsEligible($site);
        $live = $reports->filter(fn (ClientReport $r) => $r->status !== ClientReport::STATUS_SUPERSEDED);

        $cells = [];
        foreach ($months as $month) {
            $key = $month->format('Y-m');
            $report = $live->first(fn (ClientReport $r) => $r->period_start->format('Y-m') === $key);
            $state = match (true) {
                $report !== null => ReportState::of($report),
                // The schedule existed by the run on the 1st of the next month
                // but the site has no care plan, so that run skipped it.
                $schedule !== null && ! $eligible && $schedule->created_at !== null
                    && $schedule->created_at->lte($month->copy()->addMonthNoOverflow()->endOfDay()) => self::CELL_SKIPPED,
                default => self::CELL_NONE,
            };
            $cells[$key] = ['month' => $month, 'report' => $report, 'state' => $state];
        }

        // Waiting/review count each site's newest live report only: an older
        // unsent period that a newer one followed isn't something to chase.
        $latestState = ($latest = $live->first()) !== null ? ReportState::of($latest) : null;

        return [
            'site' => $site,
            'eligible' => $eligible,
            'schedule' => $schedule,
            'schedule_state' => match (true) {
                $schedule === null => 'none',
                $schedule->is_enabled => 'scheduled',
                default => 'disabled',
            },
            'missing' => $schedule === null && $eligible && ! $site->is_inactive,
            'latest' => $latest,
            'latest_state' => $latestState,
            'waiting' => in_array($latestState, ReportState::WAITING, true) ? 1 : 0,
            'review' => in_array($latestState, [ReportState::NEEDS_REVIEW, ReportState::OUTDATED], true) ? 1 : 0,
            'reports' => $reports->values(),
            'months' => $cells,
        ];
    }

    /** @param array<string, mixed> $row */
    protected function haystack(array $row): string
    {
        $parts = [$row['name'], $row['contact'], ...$row['contacts']];
        foreach ($row['sites'] as $site) {
            $parts[] = $site['site']->domain;
            foreach ((array) ($site['schedule']?->recipients ?? []) as $recipient) {
                $parts[] = $recipient;
            }
        }

        return Str::lower(implode(' ', array_filter($parts)));
    }
}
