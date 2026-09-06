<?php

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\Checks\DigitalOceanSpacesCheck;
use Illuminate\Support\Facades\Storage;

/**
 * Coverage for DigitalOceanSpacesCheck (App\Services\Diagnostics\DiagnosticCheck),
 * a thin wrapper over App\Services\DigitalOcean\SpacesClient::smokeTest(). Spaces
 * access goes through the `do_spaces` Flysystem disk (S3-compatible), not raw
 * Http, so the boundary to fake here is Storage — mirroring
 * tests/Feature/Console/DoSpacesTestTest.php's pattern for the same client:
 * Storage::fake('do_spaces') exercises a real local disk for the success path,
 * and Storage::shouldReceive simulates a rejected S3 credential for the
 * failure path. Credential is a separate HMAC key+secret pair, distinct from
 * the DigitalOcean personal access token used by DigitalOceanCheck.
 */
describe('DigitalOceanSpacesCheck', function () {
    it('skips cleanly when no DO Spaces key/secret are configured', function () {
        config([
            'clockwork.do_spaces.key' => '',
            'clockwork.do_spaces.secret' => '',
        ]);

        $result = app(DigitalOceanSpacesCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toContain('CLOCKWORK_DO_SPACES_KEY');
    });

    it('returns ok and lists sample keys when the bucket is reachable', function () {
        config([
            'clockwork.do_spaces.key' => 'do-spaces-key',
            'clockwork.do_spaces.secret' => 'do-spaces-secret',
            'clockwork.do_spaces.bucket' => 'demo-bucket',
            'clockwork.do_spaces.region' => 'nyc3',
        ]);

        Storage::fake('do_spaces');
        Storage::disk('do_spaces')->put('example.com/2026-08-01-00-00-00-example.sql.gz', 'fake-dump');
        Storage::disk('do_spaces')->put('example.com/2026-08-01-00-00-30-files.tar.gz', 'fake-tar');

        $result = app(DigitalOceanSpacesCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toContain('demo-bucket')
            ->and($result->summary)->toContain('reachable')
            ->and($result->summary)->toContain('sample key');
    });

    it('returns fail with the exception message when the bucket is unreachable', function () {
        config([
            'clockwork.do_spaces.key' => 'do-spaces-key',
            'clockwork.do_spaces.secret' => 'do-spaces-secret',
            'clockwork.do_spaces.bucket' => 'demo-bucket',
            'clockwork.do_spaces.region' => 'nyc3',
        ]);

        Storage::shouldReceive('disk')
            ->with('do_spaces')
            ->andThrow(new RuntimeException('S3 credentials rejected'));

        $result = app(DigitalOceanSpacesCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Request failed')
            ->and($result->detail)->toBe('S3 credentials rejected');
    });
});
