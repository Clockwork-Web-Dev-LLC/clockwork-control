<?php

namespace Modules\Seo\Services;

/** What one site's sync did. Errors are already cut to 480 characters. */
final readonly class SyncOutcome
{
    /**
     * @param  list<string>  $errors
     */
    public function __construct(
        public bool $ok,
        public array $errors = [],
        public bool $revoked = false,
        public int $gscMonths = 0,
        public int $ga4Months = 0,
    ) {}
}
