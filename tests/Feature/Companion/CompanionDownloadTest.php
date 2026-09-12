<?php

namespace Tests\Feature\Companion;

use App\Models\User;
use ZipArchive;

describe('Companion Download', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    });

    it('downloads clockwork-companion.zip with valid plugin archive structure', function () {
        $response = $this->get(route('companion.download'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        expect($response->headers->get('Content-Disposition'))->toContain('clockwork-companion-')
            ->and($response->headers->get('Content-Disposition'))->toContain('.zip');

        $tempZip = tempnam(sys_get_temp_dir(), 'cw_test_zip_');
        file_put_contents($tempZip, $response->getContent());

        $zip = new ZipArchive;
        $res = $zip->open($tempZip);
        expect($res)->toBeTrue();

        // Must contain main plugin entry point
        $hasLoader = $zip->locateName('clockwork-companion/clockwork-companion.php') !== false;
        expect($hasLoader)->toBeTrue();

        $zip->close();
        @unlink($tempZip);
    });
});
