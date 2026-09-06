<?php

namespace Tests\Concerns;

use App\Support\IssueCounter;

/**
 * AppServiceProvider's `layouts.app` view composer calls IssueCounter::total()
 * on every authenticated page render. Two of its queries use MySQL-only
 * JSON_EXTRACT/JSON_UNQUOTE/CAST(...AS UNSIGNED) syntax that the sqlite test
 * DB can't parse. Call mockIssueCounterZero() before hitting any authenticated
 * route from a test.
 */
trait RendersAuthenticatedPages
{
    protected function mockIssueCounterZero(): void
    {
        $this->partialMock(IssueCounter::class, function ($mock) {
            $mock->shouldReceive('total')->andReturn(0);
        });
    }
}
