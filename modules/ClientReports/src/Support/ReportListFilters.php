<?php

namespace Modules\ClientReports\Support;

use Illuminate\Http\Request;

/**
 * The reports list's filters, read from and written back to the query
 * string, so a filtered list survives paging, opening a report and coming
 * back, and so the preview can step to the previous/next report of the
 * same list.
 */
final class ReportListFilters
{
    public const ALL = 'all';

    /** Every unsent state that needs someone (needs review, outdated, ready, partly sent). */
    public const WAITING = 'waiting';

    public const UNASSIGNED = 'unassigned';

    public function __construct(
        public ?string $period = null,
        public ?string $status = null,
        public ?string $category = null,
        public string $q = '',
        public bool $showSuperseded = false,
        public ?string $client = null,
        /** 'client' when the list was opened from a client's page, so Back returns there. */
        public ?string $from = null,
    ) {}

    public static function fromRequest(Request $request, ?string $defaultPeriod = null): self
    {
        $period = (string) $request->query('period', '');
        $period = $period === self::ALL || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) === 1
            ? $period
            : $defaultPeriod;

        $status = (string) $request->query('status', '');
        $status = $status === self::WAITING || ReportState::isKnown($status) ? $status : null;

        $category = (string) $request->query('category', '');
        $category = ReviewReasonCategories::isKnown($category) ? $category : null;

        $client = (string) $request->query('client', '');
        $client = $client === self::UNASSIGNED || ctype_digit($client) ? $client : null;

        return new self(
            period: $period,
            status: $status,
            category: $category,
            q: trim(mb_substr((string) $request->query('q', ''), 0, 100)),
            showSuperseded: $request->boolean('superseded'),
            client: $client,
            from: $client !== null && $request->query('from') === 'client' ? 'client' : null,
        );
    }

    /**
     * The non-empty filters as query parameters.
     *
     * @return array<string, string>
     */
    public function toQuery(): array
    {
        return array_filter([
            'period' => $this->period,
            'status' => $this->status,
            'category' => $this->category,
            'q' => $this->q,
            'superseded' => $this->showSuperseded ? '1' : null,
            'client' => $this->client,
            'from' => $this->from,
        ], fn ($value) => $value !== null && $value !== '');
    }

    /** @param array<string, string|null> $changes */
    public function with(array $changes): array
    {
        return array_filter(array_merge($this->toQuery(), $changes), fn ($value) => $value !== null && $value !== '');
    }

    public function isFiltered(): bool
    {
        return $this->status !== null || $this->category !== null || $this->q !== '' || $this->client !== null;
    }
}
