<?php

namespace Modules\ClientReports\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\ClientReports\Models\ClientReport;
use Modules\ClientReports\Support\ReportListFilters;
use Modules\ClientReports\Support\ReportState;
use Modules\ClientReports\Support\ReviewReasonCategories;

/**
 * Reads reports for the operator screens (the reports list, a client's
 * history, the preview's previous/next) without loading every report's
 * compiled sections: only sections_data.meta comes back, which is all the
 * state, review reasons and "outdated" check need.
 *
 * The models it returns carry just that meta in sections_data, so they are
 * for reading only: never save one. Anything that changes a report loads it
 * again in full.
 */
class ReportBrowser
{
    public const PER_PAGE = 25;

    private const LIGHT_COLUMNS = [
        'id', 'site_id', 'template_id', 'schedule_id', 'title', 'period_start', 'period_end',
        'client_name', 'client_email', 'status', 'sent_at', 'sent_to', 'public_token', 'created_at', 'updated_at',
    ];

    /** @return Builder<ClientReport> */
    public function query(): Builder
    {
        return ClientReport::query()
            ->select(self::LIGHT_COLUMNS)
            ->addSelect('sections_data->meta as report_meta')
            ->with([
                // A report outlives its site's archiving; keep the domain readable.
                'site' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'domain', 'client_id', 'care_plan_enabled', 'is_inactive', 'archived_at']),
                'site.client:id,name,company_name',
                'schedule:id,site_id,delivery_mode,last_sent_at,is_enabled,recipients',
            ]);
    }

    /**
     * @param  Builder<ClientReport>  $query
     * @return EloquentCollection<int, ClientReport>
     */
    public function get(Builder $query): EloquentCollection
    {
        $reports = $query->get();
        foreach ($reports as $report) {
            $meta = json_decode((string) $report->getAttribute('report_meta'), true);
            $attributes = Arr::except($report->getAttributes(), 'report_meta');
            $attributes['sections_data'] = json_encode(['meta' => is_array($meta) ? $meta : []]);
            $report->setRawAttributes($attributes, true);
        }

        return $reports;
    }

    /**
     * Months that have reports, newest first ("2026-10" => count of live reports).
     *
     * @return array<string, int>
     */
    public function periods(): array
    {
        $counts = [];
        ClientReport::query()
            ->where('status', '!=', ClientReport::STATUS_SUPERSEDED)
            ->pluck('period_start')
            ->each(function ($start) use (&$counts) {
                $key = Carbon::parse($start)->format('Y-m');
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            });
        krsort($counts);

        return $counts;
    }

    public function latestPeriod(): ?string
    {
        return array_key_first($this->periods());
    }

    /**
     * Every report the filters match, in list order: newest period first,
     * then by site.
     *
     * @return Collection<int, ClientReport>
     */
    public function list(ReportListFilters $filters): Collection
    {
        $query = $this->query();

        if ($filters->period !== null && $filters->period !== ReportListFilters::ALL) {
            $month = Carbon::createFromFormat('!Y-m', $filters->period);
            $query->where('period_start', '>=', $month->toDateString())
                ->where('period_start', '<', $month->copy()->addMonthNoOverflow()->toDateString());
        }

        if (! $filters->showSuperseded && $filters->status !== ReportState::SUPERSEDED) {
            $query->where('status', '!=', ClientReport::STATUS_SUPERSEDED);
        }

        if ($filters->client === ReportListFilters::UNASSIGNED) {
            $query->whereHas('site', fn ($q) => $q->withoutGlobalScopes()->whereNull('client_id'));
        } elseif ($filters->client !== null) {
            $query->whereHas('site', fn ($q) => $q->withoutGlobalScopes()->where('client_id', (int) $filters->client));
        }

        $reports = $this->get($query)->toBase();

        if ($filters->q !== '') {
            $needle = Str::lower($filters->q);
            $reports = $reports->filter(fn (ClientReport $r) => Str::contains(Str::lower(implode(' ', array_filter([
                $r->site?->domain, $r->title, $r->client_name, $r->client_email,
                $r->site?->client?->name, $r->site?->client?->company_name,
            ]))), $needle));
        }

        if ($filters->status !== null && $filters->status !== ReportListFilters::ALL) {
            $reports = $reports->filter(fn (ClientReport $r) => $filters->status === ReportListFilters::WAITING
                ? in_array(ReportState::of($r), ReportState::WAITING, true)
                : ReportState::of($r) === $filters->status);
        }

        if ($filters->category !== null) {
            $reports = $reports->filter(fn (ClientReport $r) => in_array($filters->category, ReviewReasonCategories::forReasons(ReportState::reasons($r)), true));
        }

        return $reports
            ->sort(fn (ClientReport $a, ClientReport $b) => [$b->period_start->format('Y-m-d'), Str::lower((string) $a->site?->domain), $b->id]
                <=> [$a->period_start->format('Y-m-d'), Str::lower((string) $b->site?->domain), $a->id])
            ->values();
    }

    /**
     * How many reports are in each state, for the summary strip.
     *
     * @param  Collection<int, ClientReport>  $reports
     * @return array<string, int>
     */
    public function summarize(Collection $reports): array
    {
        $counts = array_fill_keys(array_keys(ReportState::options()), 0);
        foreach ($reports as $report) {
            $state = ReportState::of($report);
            $counts[$state] = ($counts[$state] ?? 0) + 1;
        }
        $counts['total'] = $reports->count();

        return $counts;
    }
}
