<?php

namespace Modules\Seo\Ai;

use App\Models\Site;
use Carbon\CarbonImmutable;
use Modules\Seo\DataForSeo\DataForSeoClient;
use Modules\Seo\DataForSeo\Feature;
use Modules\Seo\DataForSeo\TaskRunner;
use Modules\Seo\Keywords\Market;
use Modules\Seo\Models\AiCheck;
use Modules\Seo\Models\AiPrompt;
use Modules\Seo\Models\ProviderTask;
use Modules\Seo\Models\SiteProfile;
use Modules\Seo\Services\BrandRegex;
use RuntimeException;

/**
 * AI visibility: ask each client's prompts of each AI engine on a
 * schedule, then store whether the client was mentioned or cited.
 *
 * An AI answer changes from one asking to the next, so a single check is
 * a sample. What means something is the rate over many prompts and weeks.
 */
class AiVisibilityTracker
{
    public function __construct(
        private readonly TaskRunner $tasks,
        private readonly AiVisibilitySettings $settings,
    ) {}

    /**
     * Queue every prompt that is due, on every chosen engine.
     *
     * @return array{due: int, posted: int, failed: int, cost: float, error: ?string, refused: bool}
     */
    public function postDue(?int $siteId = null): array
    {
        $summary = ['due' => 0, 'posted' => 0, 'failed' => 0, 'cost' => 0.0, 'error' => null, 'refused' => false];
        $interval = $this->settings->intervalDays();
        if ($interval === null) {
            return $summary;
        }

        $today = CarbonImmutable::now('UTC')->startOfDay();
        $cutoff = $today->subDays($interval - 1)->toDateString();

        $prompts = AiPrompt::query()
            ->where('is_active', true)
            ->whereIn('site_id', SiteProfile::query()->where('is_enabled', true)->select('site_id'))
            ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
            ->orderBy('site_id')->orderBy('id')
            ->get()
            ->groupBy('site_id');

        foreach ($prompts as $site => $sitePrompts) {
            $market = Market::forProfile(SiteProfile::query()->where('site_id', (int) $site)->first());

            foreach ($this->settings->engines() as $engine) {
                $due = $sitePrompts->filter(fn (AiPrompt $p) => (($p->last_posted ?? [])[$engine] ?? '') < $cutoff)->values();
                if ($due->isEmpty()) {
                    continue;
                }
                $summary['due'] += $due->count();

                $items = $due->map(fn (AiPrompt $p) => [
                    'task' => ['keyword' => $p->prompt, 'location_code' => $market->locationCode, 'language_code' => $market->languageCode, 'priority' => 1],
                    'context' => ['prompt_id' => $p->id, 'engine' => $engine, 'date' => $today->toDateString()],
                ])->all();

                $startedAt = now();
                $result = $this->tasks->post(Feature::AI_VISIBILITY, AiEngines::kind($engine), AiEngines::ALL[$engine][1], $items, AiEngines::price($engine), (int) $site);

                $summary['posted'] += $result['posted'];
                $summary['failed'] += $result['failed'];
                $summary['cost'] += $result['cost'];
                $summary['error'] ??= $result['error'];

                // Only the prompts DataForSEO accepted are marked, so a
                // rejected one is tried again next run.
                $accepted = ProviderTask::query()
                    ->where('provider', DataForSeoClient::PROVIDER)->where('kind', AiEngines::kind($engine))->where('site_id', (int) $site)
                    ->where('posted_at', '>=', $startedAt->copy()->subSecond())
                    ->get()->map(fn (ProviderTask $t) => (int) ($t->context['prompt_id'] ?? 0))->filter()->all();

                foreach ($due->whereIn('id', $accepted) as $prompt) {
                    $prompt->forceFill(['last_posted' => [$engine => $today->toDateString()] + ($prompt->last_posted ?? [])])->save();
                }

                if ($result['refused']) {
                    $summary['refused'] = true;

                    return $summary;
                }
            }
        }

        return $summary;
    }

    /**
     * Fetch finished answers and store them.
     *
     * @return array{collected: int, pending: int, failed: int}
     */
    public function collect(): array
    {
        $summary = ['collected' => 0, 'pending' => 0, 'failed' => 0];

        foreach (AiEngines::ALL as $engine => [, , $getPath]) {
            $result = $this->tasks->collect(AiEngines::kind($engine), $getPath, function (array $task, ProviderTask $row): void {
                $this->store($task, $row);
            });
            foreach ($summary as $key => $value) {
                $summary[$key] = $value + $result[$key];
            }
        }

        return $summary;
    }

    public function pending(): int
    {
        return array_sum(array_map(fn (string $engine) => $this->tasks->pendingCount(AiEngines::kind($engine)), array_keys(AiEngines::ALL)));
    }

    /** @param  array<string, mixed>  $task */
    private function store(array $task, ProviderTask $row): void
    {
        $context = (array) $row->context;
        $prompt = AiPrompt::query()->with('site')->find((int) ($context['prompt_id'] ?? 0));
        $engine = (string) ($context['engine'] ?? '');

        // The prompt or its site was removed while the check was queued.
        if ($prompt === null || ! $prompt->site instanceof Site || ! isset(AiEngines::ALL[$engine])) {
            return;
        }

        $result = $task['result'][0] ?? null;
        if (! is_array($result)) {
            throw new RuntimeException('DataForSEO returned no answer.');
        }

        $profile = SiteProfile::query()->where('site_id', $prompt->site_id)->first();
        $parsed = AiAnswerParser::parse(
            $result,
            (string) $prompt->site->domain,
            BrandRegex::parseTerms($profile?->brand_terms),
            array_values(array_filter((array) ($profile->competitors ?? []), 'is_string')),
        );

        AiCheck::query()->updateOrCreate(
            ['prompt_id' => $prompt->id, 'engine' => $engine, 'checked_on' => (string) ($context['date'] ?? now('UTC')->toDateString())],
            ['site_id' => $prompt->site_id] + $parsed,
        );
    }
}
