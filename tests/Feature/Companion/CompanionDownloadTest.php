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

        // Stage a hermetic, temporary companion source directory
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

        // Stage a hermetic, temporary renegade source directory
        $this->fakeRenegadeDir = sys_get_temp_dir().'/cw-renegade-test-'.bin2hex(random_bytes(6));
        mkdir($this->fakeRenegadeDir.'/src', 0755, true);
        file_put_contents(
            $this->fakeRenegadeDir.'/clockwork-renegade.php',
            "<?php\n/**\n * Plugin Name: Clockwork Renegade\n */\ndefine('CLOCKWORK_RENEGADE_VERSION', '1.0.0');\n"
        );
        file_put_contents(
            $this->fakeRenegadeDir.'/src/Plugin.php',
            "<?php\nnamespace ClockworkRenegade;\nclass Plugin {}\n"
        );
        file_put_contents(
            $this->fakeRenegadeDir.'/readme.txt',
            "=== Clockwork Renegade ===\n"
        );

        config([
            'clockwork.companion.local_path' => $this->fakeSourceDir,
            'clockwork.renegade.local_path' => $this->fakeRenegadeDir,
        ]);
    });

    afterEach(function () {
        foreach ([$this->fakeSourceDir ?? null, $this->fakeRenegadeDir ?? null] as $dir) {
            if ($dir && is_dir($dir)) {
                $iter = new RecursiveIteratorIterator(
                    new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                    RecursiveIteratorIterator::CHILD_FIRST,
                );
                foreach ($iter as $item) {
                    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
                }
                @rmdir($dir);
            }
        }
    });

    it('renders the plugin downloads hub page', function () {
        $response = $this->get(route('downloads.index'));

        $response->assertOk();
        $response->assertSee('WordPress Plugins &amp; Downloads', false);
        $response->assertSee('Clockwork Companion');
        $response->assertSee('Clockwork Renegade');
        $response->assertSee(route('companion.download'));
        $response->assertSee(route('renegade.download'));
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

    it('downloads clockwork-renegade.zip with valid plugin archive structure', function () {
        $response = $this->get(route('renegade.download'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        expect($response->headers->get('Content-Disposition'))->toContain('clockwork-renegade-')
            ->and($response->headers->get('Content-Disposition'))->toContain('.zip');

        $tempZip = tempnam(sys_get_temp_dir(), 'cw_renegade_test_zip_');
        file_put_contents($tempZip, $response->getContent());

        $zip = new ZipArchive;
        $res = $zip->open($tempZip);
        expect($res)->toBeTrue();

        // Must contain main plugin entry point and readme
        $hasLoader = $zip->locateName('clockwork-renegade/clockwork-renegade.php') !== false;
        expect($hasLoader)->toBeTrue();
        $hasReadme = $zip->locateName('clockwork-renegade/readme.txt') !== false;
        expect($hasReadme)->toBeTrue();

        $zip->close();
        @unlink($tempZip);
    });

    it('returns 500 when local companion plugin source directory does not exist', function () {
        config(['clockwork.companion.local_path' => '/nonexistent/path/'.bin2hex(random_bytes(8))]);

        $response = $this->get(route('companion.download'));
        $response->assertStatus(500);
    });

    it('returns 500 when local renegade plugin source directory does not exist', function () {
        config(['clockwork.renegade.local_path' => '/nonexistent/path/'.bin2hex(random_bytes(8))]);

        $response = $this->get(route('renegade.download'));
        $response->assertStatus(500);
    });
});
