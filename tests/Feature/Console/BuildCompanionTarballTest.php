<?php

namespace Tests\Feature\Console;

use App\Services\Companion\CompanionTarballBuilder;

/*
|--------------------------------------------------------------------------
| Coverage for clockwork:build-companion-tarball
|--------------------------------------------------------------------------
|
| CompanionTarballBuilder::buildLocalTarballBytes() is mocked so this suite
| never shells out to the real `tar` binary or stages a real copy of the
| source tree (see the real class for that logic). The version-string read
| and the final .tgz/.sha256 write ARE the command's own behavior, so they
| run for real against a throwaway temp directory that's cleaned up after
| every test.
*/

function makeFakeCompanionSourceDir(string $version = '1.30.4'): string
{
    $dir = sys_get_temp_dir().'/clockwork-tarball-test-'.bin2hex(random_bytes(6));
    mkdir($dir, 0755, true);
    file_put_contents(
        $dir.'/clockwork-companion.php',
        "<?php\ndefine('CLOCKWORK_COMPANION_VERSION', '{$version}');\n"
    );

    return $dir;
}

function rmTestDirTree(string $path): void
{
    if (! is_dir($path)) {
        return;
    }
    $iter = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iter as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($path);
}

describe('clockwork:build-companion-tarball — preconditions', function () {
    it('fails when CLOCKWORK_COMPANION_LOCAL_PATH is not set or not a directory', function () {
        config(['clockwork.companion.local_path' => '/definitely/does/not/exist/anywhere']);

        $this->artisan('clockwork:build-companion-tarball')->assertFailed();
    });

    it('fails when the loader has no CLOCKWORK_COMPANION_VERSION define', function () {
        $dir = sys_get_temp_dir().'/clockwork-tarball-test-noversion-'.bin2hex(random_bytes(6));
        mkdir($dir, 0755, true);
        file_put_contents($dir.'/clockwork-companion.php', "<?php\n// no version constant here\n");
        config(['clockwork.companion.local_path' => $dir]);

        try {
            $this->artisan('clockwork:build-companion-tarball')
                ->assertFailed()
                ->expectsOutputToContain('Could not read CLOCKWORK_COMPANION_VERSION');
        } finally {
            rmTestDirTree($dir);
        }
    });
});

describe('clockwork:build-companion-tarball — happy path', function () {
    it('writes a .tgz + matching .sha256 built from the mocked tarball bytes', function () {
        $sourceDir = makeFakeCompanionSourceDir('1.30.4');
        $outDir = sys_get_temp_dir().'/clockwork-tarball-out-'.bin2hex(random_bytes(6));
        mkdir($outDir, 0755, true);
        config(['clockwork.companion.local_path' => $sourceDir]);

        $fakeBytes = "fake-tarball-bytes-\x00-not-a-real-tgz";

        $this->mock(CompanionTarballBuilder::class, function ($mock) use ($sourceDir, $fakeBytes) {
            $mock->shouldReceive('buildLocalTarballBytes')
                ->once()
                ->with($sourceDir)
                ->andReturn($fakeBytes);
        });

        try {
            $this->artisan('clockwork:build-companion-tarball', ['--out' => $outDir])
                ->assertSuccessful();

            $tarPath = $outDir.'/clockwork-companion-1.30.4.tgz';
            $hashPath = $tarPath.'.sha256';

            expect(is_file($tarPath))->toBeTrue()
                ->and(file_get_contents($tarPath))->toBe($fakeBytes)
                ->and(is_file($hashPath))->toBeTrue()
                ->and(trim((string) file_get_contents($hashPath)))->toBe(hash('sha256', $fakeBytes));
        } finally {
            rmTestDirTree($sourceDir);
            rmTestDirTree($outDir);
        }
    });

    it('defaults --out to the parent directory of the source path when not given', function () {
        $sourceDir = makeFakeCompanionSourceDir('2.0.0');
        // dirname($sourceDir) is the system temp dir itself — writable and
        // already exists, so this exercises the "no --out" default branch
        // without needing to create anything extra.
        config(['clockwork.companion.local_path' => $sourceDir]);

        $fakeBytes = 'other-fake-bytes';
        $this->mock(CompanionTarballBuilder::class, function ($mock) use ($fakeBytes) {
            $mock->shouldReceive('buildLocalTarballBytes')->once()->andReturn($fakeBytes);
        });

        $expectedTar = dirname($sourceDir).'/clockwork-companion-2.0.0.tgz';
        $expectedHash = $expectedTar.'.sha256';

        try {
            $this->artisan('clockwork:build-companion-tarball')->assertSuccessful();

            expect(is_file($expectedTar))->toBeTrue()
                ->and(file_get_contents($expectedTar))->toBe($fakeBytes);
        } finally {
            @unlink($expectedTar);
            @unlink($expectedHash);
            rmTestDirTree($sourceDir);
        }
    });
});

describe('clockwork:build-companion-tarball — failure paths', function () {
    it('fails cleanly when the builder throws', function () {
        $sourceDir = makeFakeCompanionSourceDir('1.30.4');
        config(['clockwork.companion.local_path' => $sourceDir]);

        $this->mock(CompanionTarballBuilder::class, function ($mock) {
            $mock->shouldReceive('buildLocalTarballBytes')->once()->andThrow(new \RuntimeException('tar exited 1'));
        });

        try {
            $this->artisan('clockwork:build-companion-tarball')
                ->assertFailed()
                ->expectsOutputToContain('Tarball build failed');

            expect(is_file($sourceDir.'/clockwork-companion-1.30.4.tgz'))->toBeFalse();
        } finally {
            rmTestDirTree($sourceDir);
        }
    });

    it('fails when the given --out directory does not exist', function () {
        $sourceDir = makeFakeCompanionSourceDir('1.30.4');
        config(['clockwork.companion.local_path' => $sourceDir]);

        $this->mock(CompanionTarballBuilder::class, function ($mock) {
            $mock->shouldReceive('buildLocalTarballBytes')->once()->andReturn('bytes');
        });

        try {
            $this->artisan('clockwork:build-companion-tarball', ['--out' => '/definitely/does/not/exist/out'])
                ->assertFailed()
                ->expectsOutputToContain('Output directory does not exist');
        } finally {
            rmTestDirTree($sourceDir);
        }
    });
});
