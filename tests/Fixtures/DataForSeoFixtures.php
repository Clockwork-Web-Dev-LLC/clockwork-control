<?php

namespace Tests\Fixtures;

/**
 * DataForSEO v3 response envelopes for Http::fake(), shaped after the
 * provider's published OpenAPI specification (BaseResponseInfo and
 * BaseResponseTaskInfo): every answer carries status_code, cost and a tasks
 * array, each task with its own status_code, cost, data and result.
 */
class DataForSeoFixtures
{
    public const API = 'https://api.dataforseo.com/*';

    public const SANDBOX = 'https://sandbox.dataforseo.com/*';

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @return array<string, mixed>
     */
    public static function envelope(array $tasks, ?float $cost = null, int $status = 20000, string $message = 'Ok.'): array
    {
        return [
            'version' => '0.1.20261109',
            'status_code' => $status,
            'status_message' => $message,
            'time' => '0.1 sec.',
            'cost' => $cost ?? array_sum(array_column($tasks, 'cost')),
            'tasks_count' => count($tasks),
            'tasks_error' => count(array_filter($tasks, fn ($t) => ($t['status_code'] ?? 0) >= 40000)),
            'tasks' => $tasks,
        ];
    }

    /**
     * @param  array<int|string, mixed>|null  $result
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function task(string $id, ?array $result = null, float $cost = 0.0, int $status = 20000, string $message = 'Ok.', array $data = []): array
    {
        return [
            'id' => $id,
            'status_code' => $status,
            'status_message' => $message,
            'time' => '0.05 sec.',
            'cost' => $cost,
            'result_count' => $result === null ? 0 : count($result),
            'path' => [],
            'data' => $data,
            'result' => $result,
        ];
    }

    /** What task_post answers: one "Task Created." per task, echoing its tag. */
    public static function created(array $ids, float $costEach = 0.0006): array
    {
        $tasks = [];
        foreach (array_values($ids) as $index => $id) {
            $tasks[] = self::task($id, null, $costEach, 20100, 'Task Created.', ['tag' => (string) $index]);
        }

        return self::envelope($tasks);
    }

    /** What task_get answers while the work is still queued. */
    public static function inQueue(string $id): array
    {
        return self::envelope([self::task($id, null, 0.0, 40602, 'Task In Queue.')]);
    }

    public static function error(int $status, string $message): array
    {
        return ['version' => '0.1', 'status_code' => $status, 'status_message' => $message, 'time' => '0 sec.', 'cost' => 0, 'tasks_count' => 0, 'tasks_error' => 0, 'tasks' => null];
    }

    public static function userData(float $balance = 48.25): array
    {
        return self::envelope([self::task('user-data', [[
            'login' => 'agency@example.com',
            'timezone' => 'UTC',
            'money' => ['total' => 50.0, 'balance' => $balance],
        ]])]);
    }
}
