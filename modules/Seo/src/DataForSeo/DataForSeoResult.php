<?php

namespace Modules\Seo\DataForSeo;

/**
 * The outcome of one DataForSEO call. Failures come back as values, not
 * exceptions, so one site's error never stops the next site's work.
 *
 * A call carries one or more tasks. The call can succeed while a task
 * inside it fails, so callers read tasks(), not just ok.
 */
final readonly class DataForSeoResult
{
    /** DataForSEO's "all good" codes: Ok, and Task Created for posted work. */
    public const OK_CODES = [20000, 20100];

    /** Still working on it: Task Handed, Task In Queue. Not an error. */
    public const PENDING_CODES = [40601, 40602];

    /**
     * @param  list<array<string, mixed>>  $tasks  each: id, status_code, status_message, cost, data, result
     */
    public function __construct(
        public bool $ok,
        public int $httpStatus,
        public int $statusCode,
        public float $cost,
        public array $tasks = [],
        public ?string $error = null,
        public bool $refused = false,
    ) {}

    public static function failure(string $error, int $httpStatus = 0, int $statusCode = 0, bool $refused = false): self
    {
        return new self(false, $httpStatus, $statusCode, 0.0, [], mb_substr($error, 0, 480), $refused);
    }

    /** @return list<array<string, mixed>> */
    public function okTasks(): array
    {
        return array_values(array_filter($this->tasks, fn (array $t) => in_array((int) ($t['status_code'] ?? 0), self::OK_CODES, true)));
    }

    /** @return list<array<string, mixed>> */
    public function failedTasks(): array
    {
        return array_values(array_filter($this->tasks, fn (array $t) => ! in_array((int) ($t['status_code'] ?? 0), [...self::OK_CODES, ...self::PENDING_CODES], true)));
    }

    /** The first task's result rows, for calls that carry a single task. */
    public function firstResult(): array
    {
        return (array) ($this->okTasks()[0]['result'] ?? []);
    }

    public function isPending(): bool
    {
        return $this->tasks !== []
            && array_filter($this->tasks, fn (array $t) => ! in_array((int) ($t['status_code'] ?? 0), self::PENDING_CODES, true)) === [];
    }
}
