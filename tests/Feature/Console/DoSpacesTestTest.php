<?php

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Storage;

/**
 * Coverage for the `clockwork:do-spaces-test` connectivity-check command
 * (App\Console\Commands\DoSpacesTest), which wraps
 * App\Services\DigitalOcean\SpacesClient::smokeTest(). SpacesClient talks to
 * the `do_spaces` filesystem disk (an S3-compatible Flysystem adapter), so
 * the boundary to mock here is the Storage facade, not Http — Storage::fake()
 * exercises a real local disk for the success path, and Storage::shouldReceive
 * simulates a rejected S3 credential for the failure path.
 */
describe('clockwork:do-spaces-test', function () {
    it('exits with failure and reports nothing configured when the key/secret are missing', function () {
        config([
            'clockwork.do_spaces.key' => '',
            'clockwork.do_spaces.secret' => '',
        ]);

        $this->artisan('clockwork:do-spaces-test')
            ->assertFailed()
            ->expectsOutputToContain('CLOCKWORK_DO_SPACES_KEY / CLOCKWORK_DO_SPACES_SECRET not set');
    });

    it('reports the bucket reachable and lists sample keys when credentials are valid', function () {
        config([
            'clockwork.do_spaces.key' => 'do-spaces-key',
            'clockwork.do_spaces.secret' => 'do-spaces-secret',
            'clockwork.do_spaces.bucket' => 'demo-bucket',
            'clockwork.do_spaces.region' => 'nyc3',
        ]);

        Storage::fake('do_spaces');
        Storage::disk('do_spaces')->put('example.com/2026-08-01-00-00-00-example.sql.gz', 'fake-dump');
        Storage::disk('do_spaces')->put('example.com/2026-08-01-00-00-30-files.tar.gz', 'fake-tar');

        $this->artisan('clockwork:do-spaces-test')
            ->assertSuccessful()
            ->expectsOutputToContain('Bucket:  demo-bucket')
            ->expectsOutputToContain('Authenticated and bucket reachable.')
            ->expectsOutputToContain('example.com');
    });

    it('exits with failure and reports the error when the bucket is unreachable', function () {
        config([
            'clockwork.do_spaces.key' => 'do-spaces-key',
            'clockwork.do_spaces.secret' => 'do-spaces-secret',
            'clockwork.do_spaces.bucket' => 'demo-bucket',
            'clockwork.do_spaces.region' => 'nyc3',
        ]);

        Storage::shouldReceive('disk')
            ->with('do_spaces')
            ->andThrow(new \RuntimeException('S3 credentials rejected'));

        $this->artisan('clockwork:do-spaces-test')
            ->assertFailed()
            ->expectsOutputToContain('Spaces smoke test failed: S3 credentials rejected');
    });
});
