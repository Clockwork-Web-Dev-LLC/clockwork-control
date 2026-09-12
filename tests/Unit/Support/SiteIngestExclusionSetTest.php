<?php

use App\Models\Site;
use App\Models\SiteIngestExclusion;
use App\Support\SiteIngestExclusionSet;
use Tests\TestCase;

uses(TestCase::class);

function exclusionSet(array $rows): SiteIngestExclusionSet
{
    return SiteIngestExclusionSet::fromExclusions(collect($rows)->map(
        fn (array $row) => new SiteIngestExclusion($row)
    ));
}

it('blocks by domain regardless of which importer is asking', function () {
    $set = exclusionSet([
        [
            'hosting_provider' => Site::HOSTING_PROVIDER_SPINUPWP,
            'domain' => 'Old.Example.com',
            'provider_site_id' => '123',
        ],
    ]);

    expect($set->blocks(Site::HOSTING_PROVIDER_SPINUPWP, null, 'old.example.com'))->toBeTrue()
        ->and($set->blocks(Site::HOSTING_PROVIDER_PRESSABLE, '999', 'old.example.com'))->toBeTrue()
        ->and($set->blocks(Site::HOSTING_PROVIDER_SPINUPWP, '999', 'other.com'))->toBeFalse();
});

it('blocks a renamed host site by provider site id', function () {
    $set = exclusionSet([
        [
            'hosting_provider' => Site::HOSTING_PROVIDER_PRESSABLE,
            'domain' => 'old-name.com',
            'provider_site_id' => '999000',
        ],
    ]);

    expect($set->blocks(Site::HOSTING_PROVIDER_PRESSABLE, 999000, 'renamed.com'))->toBeTrue()
        ->and($set->blocks(Site::HOSTING_PROVIDER_SPINUPWP, '999000', 'renamed.com'))->toBeFalse();
});
