<?php

use App\Models\CisaKevEntry;
use App\Services\Security\CisaKevClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function cisaKevFeed(array $vulnerabilities = []): array
{
    return [
        'title' => 'CISA Catalog of Known Exploited Vulnerabilities',
        'catalogVersion' => '2026.09.11',
        'dateReleased' => '2026-09-11T19:32:16.8993Z',
        'count' => count($vulnerabilities),
        'vulnerabilities' => $vulnerabilities,
    ];
}

function cisaKevItem(array $overrides = []): array
{
    return array_merge([
        'cveID' => 'CVE-2024-1234',
        'vendorProject' => 'WordPress',
        'product' => 'Sample Plugin',
        'vulnerabilityName' => 'Sample Plugin SQL Injection',
        'dateAdded' => '2024-05-10',
        'shortDescription' => 'Sample Plugin contains a SQL injection vulnerability.',
        'requiredAction' => 'Apply mitigations.',
        'dueDate' => '2024-05-31',
        'knownRansomwareCampaignUse' => 'Unknown',
        'notes' => 'https://nvd.nist.gov/vuln/detail/CVE-2024-1234',
    ], $overrides);
}

describe('clockwork:refresh-cisa-kev', function () {
    it('fetches the official CISA KEV JSON feed and populates cisa_kev_entries', function () {
        Http::fake([
            CisaKevClient::FEED_URL => Http::response(cisaKevFeed([
                cisaKevItem(['cveID' => 'CVE-2024-1111', 'vendorProject' => 'Elementor', 'product' => 'Elementor Pro']),
                cisaKevItem(['cveID' => 'CVE-2024-2222', 'vendorProject' => 'Automattic', 'product' => 'WooCommerce']),
            ]), 200),
        ]);

        $this->artisan('clockwork:refresh-cisa-kev')
            ->expectsOutputToContain('Catalog count=2  rows_inserted=2')
            ->assertSuccessful();

        expect(CisaKevEntry::count())->toBe(2);

        $entry = CisaKevEntry::where('cve', 'CVE-2024-1111')->first();
        expect($entry)->not->toBeNull()
            ->and($entry->vendor_project)->toBe('Elementor')
            ->and($entry->product)->toBe('Elementor Pro')
            ->and($entry->date_added?->format('Y-m-d'))->toBe('2024-05-10');
    });

    it('deduplicates duplicate CVE IDs in the incoming feed', function () {
        Http::fake([
            CisaKevClient::FEED_URL => Http::response(cisaKevFeed([
                cisaKevItem(['cveID' => 'CVE-2024-1111', 'vendorProject' => 'Elementor', 'product' => 'Elementor Pro']),
                cisaKevItem(['cveID' => 'CVE-2024-1111', 'vendorProject' => 'Elementor', 'product' => 'Elementor Pro Duplicate']),
            ]), 200),
        ]);

        $this->artisan('clockwork:refresh-cisa-kev')
            ->expectsOutputToContain('Catalog count=1  rows_inserted=1')
            ->assertSuccessful();

        expect(CisaKevEntry::count())->toBe(1);
    });

    it('is idempotent when run repeatedly against the feed', function () {
        Http::fake([
            CisaKevClient::FEED_URL => Http::response(cisaKevFeed([
                cisaKevItem(['cveID' => 'CVE-2024-1111']),
                cisaKevItem(['cveID' => 'CVE-2024-2222']),
                cisaKevItem(['cveID' => 'CVE-2024-3333']),
            ]), 200),
        ]);

        $this->artisan('clockwork:refresh-cisa-kev')->assertSuccessful();
        expect(CisaKevEntry::count())->toBe(3);

        $this->artisan('clockwork:refresh-cisa-kev')->assertSuccessful();
        expect(CisaKevEntry::count())->toBe(3);
    });

    it('fails gracefully and preserves existing entries on HTTP error', function () {
        CisaKevEntry::factory()->create(['cve' => 'CVE-2023-9999']);

        Http::fake([
            CisaKevClient::FEED_URL => Http::response('Server Error', 500),
        ]);

        $this->artisan('clockwork:refresh-cisa-kev')
            ->expectsOutputToContain('Refresh failed')
            ->assertFailed();

        expect(CisaKevEntry::count())->toBe(1)
            ->and(CisaKevEntry::where('cve', 'CVE-2023-9999')->exists())->toBeTrue();
    });

    it('fails gracefully on invalid JSON feed payload', function () {
        Http::fake([
            CisaKevClient::FEED_URL => Http::response(['unexpected' => 'structure'], 200),
        ]);

        $this->artisan('clockwork:refresh-cisa-kev')
            ->expectsOutputToContain('Refresh failed')
            ->assertFailed();
    });
});
