<?php

use Modules\Seo\Services\QueryFilters;

it('treats whole-quoted queries and search operators as junk', function (string $query) {
    expect(QueryFilters::isJunk($query))->toBeTrue();
})->with([
    '"acme roofing"',
    '“acme roofing”',
    'site:acme.com',
    'roof repair site:acme.com',
    'inurl:services',
    'intitle:"roof repair"',
    '-site:acme.com roofing',
    '   ',
]);

it('keeps ordinary queries, including ones with quotes inside', function (string $query) {
    expect(QueryFilters::isJunk($query))->toBeFalse();
})->with([
    'roof repair phoenix',
    'joe\'s "best" pizza',
    '2" pvc pipe',
    'what is a site map',
    'site plan template',
]);
