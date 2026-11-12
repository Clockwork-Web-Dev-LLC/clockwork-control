<?php

namespace Modules\Seo\DataForSeo;

use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Seo\Models\ProviderTask;
use Throwable;

/**
 * "Post now, collect later". DataForSEO's cheapest prices are for work put
 * on its queue and fetched when ready, usually a few minutes on. Every
 * scheduled feature (rank tracking, crawls, AI checks, map grids) uses this
 * so the waiting, retrying and giving up is written once.
 */
class TaskRunner
{
    /** A task that is still not ready after this long is written off. */
    public const GIVE_UP_AFTER_HOURS = 48;

    public function __construct(private readonly DataForSeoClient $client) {}

    /**
     * Post tasks and remember each one so it can be collected later.
     *
     * @param  string  $kind  what the result is (serp_organic, on_page_crawl, ...), which names the collector
     * @param  list<array{task: array<string, mixed>, context?: array<string, mixed>}>  $items
     * @param  float  $estimatePerTask  expected cost of one task, for the budget check
     * @return array{posted: int, failed: int, cost: float, error: ?string, refused: bool}
     */
    public function post(string $feature, string $kind, string $path, array $items, float $estimatePerTask, ?int $siteId = null, ?User $user = null): array
    {
        $summary = ['posted' => 0, 'failed' => 0, 'cost' => 0.0, 'error' => null, 'refused' => false];

        foreach (array_chunk($items, DataForSeoClient::MAX_TASKS_PER_POST) as $chunk) {
            // The tag comes back on each task, so a result can be matched to
            // what was asked even if DataForSEO reorders or drops one.
            $tasks = [];
            foreach ($chunk as $index => $item) {
                $tasks[] = $item['task'] + ['tag' => (string) $index];
            }

            $result = $this->client->post($path, $tasks, $feature, $estimatePerTask * count($tasks), $siteId, $user);

            if (! $result->ok) {
                $summary['failed'] += count($chunk);
                $summary['error'] = $result->error;
                $summary['refused'] = $result->refused;

                // A refusal or a dead account won't get better on the next
                // chunk. Stop, so the reason is reported once.
                break;
            }

            $summary['cost'] += $result->cost;

            foreach ($result->tasks as $position => $task) {
                $tag = $task['data']['tag'] ?? null;
                $item = $chunk[is_numeric($tag) ? (int) $tag : $position] ?? null;
                $taskId = (string) ($task['id'] ?? '');

                if ($item === null || $taskId === '' || ! in_array((int) ($task['status_code'] ?? 0), DataForSeoResult::OK_CODES, true)) {
                    $summary['failed']++;
                    $summary['error'] ??= mb_substr((string) ($task['status_message'] ?? 'DataForSEO did not accept a task.'), 0, 480);

                    continue;
                }

                ProviderTask::query()->updateOrCreate(
                    ['provider' => DataForSeoClient::PROVIDER, 'task_id' => $taskId],
                    [
                        'feature' => $feature,
                        'kind' => $kind,
                        'site_id' => $siteId,
                        'context' => $item['context'] ?? null,
                        'status' => ProviderTask::STATUS_PENDING,
                        'cost_usd' => (float) ($task['cost'] ?? 0),
                        'posted_at' => now(),
                    ],
                );
                $summary['posted']++;
            }
        }

        return $summary;
    }

    /**
     * Fetch finished tasks of one kind and hand each result to the handler.
     *
     * @param  string  $pathTemplate  the task_get path with {id}, e.g. /v3/serp/google/organic/task_get/advanced/{id}
     * @param  callable(array<string, mixed>, ProviderTask): void  $handler  receives the task (with its result) and our row
     * @return array{collected: int, pending: int, failed: int}
     */
    public function collect(string $kind, string $pathTemplate, callable $handler, int $limit = 2000): array
    {
        $summary = ['collected' => 0, 'pending' => 0, 'failed' => 0];

        $rows = ProviderTask::query()
            ->where('provider', DataForSeoClient::PROVIDER)
            ->where('kind', $kind)
            ->where('status', ProviderTask::STATUS_PENDING)
            ->orderBy('posted_at')
            ->limit($limit)
            ->get();

        foreach ($rows as $row) {
            $result = $this->client->get(str_replace('{id}', $row->task_id, $pathTemplate), $row->feature, $row->site_id);
            $row->attempts++;

            if ($result->ok && $result->isPending()) {
                $this->stillPending($row, $summary);

                continue;
            }

            $task = $result->okTasks()[0] ?? null;

            if (! $result->ok || $task === null) {
                $message = $result->error ?? (string) ($result->tasks[0]['status_message'] ?? 'DataForSEO returned no result for this task.');

                // A failed call is not the same as a failed task: the task
                // may be fine and DataForSEO briefly unreachable.
                if (! $result->ok && $result->statusCode === 0) {
                    $row->error = mb_substr($message, 0, 480);
                    $this->stillPending($row, $summary);

                    continue;
                }

                $this->fail($row, $message, $summary);

                continue;
            }

            try {
                $handler($task, $row);
            } catch (Throwable $e) {
                report($e);
                $this->fail($row, 'Could not store the result: '.$e->getMessage(), $summary);

                continue;
            }

            $row->forceFill(['status' => ProviderTask::STATUS_COLLECTED, 'collected_at' => now(), 'error' => null])->save();
            $summary['collected']++;
        }

        return $summary;
    }

    /** How many tasks of a kind are still waiting. */
    public function pendingCount(string $kind): int
    {
        return ProviderTask::query()
            ->where('provider', DataForSeoClient::PROVIDER)
            ->where('kind', $kind)
            ->where('status', ProviderTask::STATUS_PENDING)
            ->count();
    }

    /** @param  array{collected: int, pending: int, failed: int}  $summary */
    private function stillPending(ProviderTask $row, array &$summary): void
    {
        if ($row->posted_at !== null && $row->posted_at->lt(Carbon::now()->subHours(self::GIVE_UP_AFTER_HOURS))) {
            $this->fail($row, 'Not ready after '.self::GIVE_UP_AFTER_HOURS.' hours; written off.', $summary);

            return;
        }

        $row->save();
        $summary['pending']++;
    }

    /** @param  array{collected: int, pending: int, failed: int}  $summary */
    private function fail(ProviderTask $row, string $message, array &$summary): void
    {
        $row->forceFill(['status' => ProviderTask::STATUS_FAILED, 'error' => mb_substr($message, 0, 480)])->save();
        $summary['failed']++;
    }
}
