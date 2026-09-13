<?php

namespace Tests\Feature\Companion;

use App\Models\User;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

describe('Companion Download', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Stage a hermetic, temporary companion source directory so tests do not
        // depend on a pre-existing ~/Projects/clockwork-companion checkout (which
        // does not exist in clean CI environments like GitHub Actions).
        $this->fakeSourceDir = sys_get_temp_dir().'/cw-companion-test-'.bin2hex(random_bytes(6));
        mkdir($this->fakeSourceDir.'/src', 0755, true);
        file_put_contents(
            $this->fakeSourceDir.'/clockwork-companion.php',
            "<?php\n/**\n * Plugin Name: Clockwork Companion\n */\ndefine('CLOCKWORK_COMPANION_VERSION', '1.35.0');\n"
        );
        file_put_contents(
            $this->fakeSourceDir.'/src/Plugin.php',
            "<?php\nnamespace ClockworkCompanion;\nclass Plugin {}\n"
        );

        config(['clockwork.companion.local_path' => $this->fakeSourceDir]);
    });

    afterEach(function () {
        if (isset($this->fakeSourceDir) && is_dir($this->fakeSourceDir)) {
            $iter = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->fakeSourceDir, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($iter as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->fakeSourceDir);
        }
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

    it('returns 500 when local plugin source directory does not exist', function () {
        config(['clockwork.companion.local_path' => '/nonexistent/path/'.bin2hex(random_bytes(8))]);

        $response = $this->get(route('companion.download'));
        $response->assertStatus(500);
    });
});
