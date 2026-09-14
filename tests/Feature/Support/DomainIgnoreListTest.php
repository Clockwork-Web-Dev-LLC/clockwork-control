<?php

namespace Tests\Feature\Support;

use App\Models\Site;
use App\Support\Monitoring\DomainIgnoreList;
use App\Support\Settings;

describe('DomainIgnoreList', function () {
    it('parses textarea input into normalized patterns and flags invalid lines', function () {
        $parsed = DomainIgnoreList::parseInput(
            "*.MyStagingWebsite.com\n  *.builtlikeclockwork.com  \n\nstaging.example.com, *.mystagingwebsite.com\nnot a pattern!\n*"
        );

        expect($parsed['patterns'])->toBe([
            '*.mystagingwebsite.com',
            '*.builtlikeclockwork.com',
            'staging.example.com',
        ])->and($parsed['invalid'])->toBe(['not a pattern!', '*']);
    });

    it('parses empty input to no patterns', function () {
        expect(DomainIgnoreList::parseInput(null))->toBe(['patterns' => [], 'invalid' => []])
            ->and(DomainIgnoreList::parseInput("  \n\n"))->toBe(['patterns' => [], 'invalid' => []]);
    });

    it('matches wildcard and exact patterns case-insensitively', function () {
        app(Settings::class)->put(DomainIgnoreList::SETTING_KEY, [
            '*.mystagingwebsite.com',
            'staging.example.com',
        ]);
        $list = app(DomainIgnoreList::class);

        expect($list->matches('michelli.mystagingwebsite.com'))->toBeTrue()
            ->and($list->matches('a.b.mystagingwebsite.com'))->toBeTrue()
            ->and($list->matches('Michelli.MyStagingWebsite.com'))->toBeTrue()
            ->and($list->matches('staging.example.com'))->toBeTrue()
            // The wildcard requires a subdomain — the bare apex is NOT covered.
            ->and($list->matches('mystagingwebsite.com'))->toBeFalse()
            ->and($list->matches('prod.example.com'))->toBeFalse()
            ->and($list->matches(null))->toBeFalse();
    });

    it('drops non-string and out-of-charset patterns defensively when reading settings', function () {
        app(Settings::class)->put(DomainIgnoreList::SETTING_KEY, [
            '*.mystagingwebsite.com',
            42,
            ['nested'],
            'sql%injection.com',
            'under_score.com',
            '*', // bare wildcard would ignore the whole fleet
        ]);

        expect(app(DomainIgnoreList::class)->patterns())->toBe(['*.mystagingwebsite.com']);
    });

    it('excludes matching sites at the query level via the notDomainIgnored scope', function () {
        app(Settings::class)->put(DomainIgnoreList::SETTING_KEY, [
            '*.mystagingwebsite.com',
            'staging.example.com',
        ]);

        Site::factory()->create(['domain' => 'michelli.mystagingwebsite.com']);
        Site::factory()->create(['domain' => 'staging.example.com']);
        $kept = Site::factory()->create(['domain' => 'prod.example.com']);
        // Apex of a `*.` pattern is not covered — subdomains only.
        $apex = Site::factory()->create(['domain' => 'mystagingwebsite.com']);

        $domains = Site::query()->notDomainIgnored()->pluck('domain');

        expect($domains->sort()->values()->all())->toBe([
            $apex->domain,
            $kept->domain,
        ]);
    });

    it('is a no-op when no patterns are configured', function () {
        Site::factory()->create(['domain' => 'anything.mystagingwebsite.com']);

        expect(Site::query()->notDomainIgnored()->count())->toBe(1)
            ->and(app(DomainIgnoreList::class)->hasPatterns())->toBeFalse();
    });
});
