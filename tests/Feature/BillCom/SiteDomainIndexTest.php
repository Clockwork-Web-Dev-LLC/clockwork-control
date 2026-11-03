<?php

namespace Tests\Feature\BillCom;

use App\Models\Site;
use Modules\BillCom\SiteDomainIndex;

/**
 * SiteDomainIndex is the normalized Site lookup both Bill.com syncs build
 * once per run. Feature (not Unit) because build() reads the sites table,
 * including archived rows behind the notArchived global scope.
 */
describe('SiteDomainIndex', function () {
    it('finds a www.-stored site by its bare domain and vice versa', function () {
        $www = Site::factory()->create(['domain' => 'www.example.com']);
        $bare = Site::factory()->create(['domain' => 'example.org']);

        $index = SiteDomainIndex::build();

        expect($index->find('example.com')?->id)->toBe($www->id)
            ->and($index->find('HTTP://WWW.EXAMPLE.COM/'))->not->toBeNull()
            ->and($index->find('www.example.org')?->id)->toBe($bare->id)
            ->and($index->find('missing-example.net'))->toBeNull()
            ->and($index->collisions())->toBe([]);
    });

    it('never returns an archived site, but remembers its domain', function () {
        Site::factory()->archived()->create(['domain' => 'www.example.net']);

        $index = SiteDomainIndex::build();

        expect($index->find('example.net'))->toBeNull()
            ->and($index->belongsToArchivedSite('example.net'))->toBeTrue()
            ->and($index->belongsToArchivedSite('missing-example.net'))->toBeFalse();
    });

    it('does not call a domain archived-only when a live site also has it', function () {
        Site::factory()->archived()->create(['domain' => 'www.example.com']);
        $live = Site::factory()->create(['domain' => 'example.com']);

        $index = SiteDomainIndex::build();

        expect($index->find('www.example.com')?->id)->toBe($live->id)
            ->and($index->belongsToArchivedSite('example.com'))->toBeFalse()
            ->and($index->collisions())->toBe([]);
    });

    it('collects the TLDs of non-archived sites only', function () {
        Site::factory()->create(['domain' => 'www.example.tv']);
        Site::factory()->create(['domain' => 'example.law']);
        Site::factory()->archived()->create(['domain' => 'example.museum']);

        $tlds = SiteDomainIndex::build()->tlds();

        expect($tlds)->toContain('tv', 'law')
            ->and($tlds)->not->toContain('museum');
    });

    it('prefers the exact domain on a collision and records it', function () {
        $apex = Site::factory()->create(['domain' => 'example.com']);
        $www = Site::factory()->create(['domain' => 'www.example.com']);

        $index = SiteDomainIndex::build();

        expect($index->find('example.com')?->id)->toBe($apex->id)
            ->and($index->find('www.example.com')?->id)->toBe($www->id)
            ->and($index->collisions())->toBe(['example.com' => 2]);
    });

    it('falls back to the lowest id when no colliding site matches exactly', function () {
        $first = Site::factory()->create(['domain' => 'www.example.org']);
        Site::factory()->create(['domain' => 'WWW.Example.org.']);

        $index = SiteDomainIndex::build();

        expect($index->find('https://example.org/')?->id)->toBe($first->id)
            ->and($index->collisions())->toBe(['example.org' => 2]);
    });

    it('records nothing for a collision no lookup touched', function () {
        Site::factory()->create(['domain' => 'example.com']);
        Site::factory()->create(['domain' => 'www.example.com']);

        $index = SiteDomainIndex::build();
        $index->find('missing-example.com');

        expect($index->collisions())->toBe([]);
    });
});
