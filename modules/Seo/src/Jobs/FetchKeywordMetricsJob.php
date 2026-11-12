<?php

namespace Modules\Seo\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Seo\Keywords\KeywordMetricsService;
use Modules\Seo\Keywords\Market;

/**
 * Buys keyword metrics a person approved. Queued so the tool that started
 * it answers at once; the results are read back from the cache table.
 *
 * tries = 1: a retry would buy the same keywords again.
 */
class FetchKeywordMetricsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<string>  $keywords
     * @param  array{location_code: int, language_code: string, city: ?string}  $market
     */
    public function __construct(
        public array $keywords,
        public array $market,
        public ?int $userId = null,
        public ?int $siteId = null,
    ) {}

    public function handle(KeywordMetricsService $metrics): void
    {
        $market = new Market((int) $this->market['location_code'], (string) $this->market['language_code'], $this->market['city'] ?? null);
        $user = $this->userId !== null ? User::query()->find($this->userId) : null;

        $summary = $metrics->fetch($this->keywords, $market, $user, $this->siteId);

        Log::info('seo.keyword_metrics.fetched', [
            'user_id' => $this->userId,
            'keywords' => count($this->keywords),
            'stored' => $summary['stored'],
            'no_data' => $summary['no_data'],
            'cost' => $summary['cost'],
            'error' => $summary['error'],
        ]);
    }
}
