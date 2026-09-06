<?php

use Illuminate\Support\Facades\Http;
use Modules\SpinupWp\SpinupWpClient;
use Tests\Fixtures\SpinupWpFixtures;

/**
 * Proves the tests/Fixtures/ convention actually round-trips through a real
 * client — not just that the fixture arrays are well-formed PHP. Extend
 * this pattern (one real assertion per integration) as Phases 1-7 need
 * more of the fixture library exercised for real.
 */
it('feeds a realistic SpinupWP payload through the real client', function () {
    config(['clockwork.spinupwp.token' => 'fake-token', 'clockwork.spinupwp.base_url' => 'https://api.spinupwp.test']);

    Http::fake([
        'api.spinupwp.test/sites/67890' => Http::response(['data' => SpinupWpFixtures::site()], 200),
    ]);

    $client = new SpinupWpClient(token: 'fake-token', baseUrl: 'https://api.spinupwp.test');

    $site = $client->site(67890);

    expect($site)
        ->toBeArray()
        ->and($site['site_domain'])->toBe('example.com')
        ->and($site['ssl']['status'])->toBe('active');
});
