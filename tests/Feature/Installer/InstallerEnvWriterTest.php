<?php

use App\Installer\InstallerEnvWriter;

describe('InstallerEnvWriter', function () {
    it('writes key values atomically and updates runtime env and config', function () {
        $tempPath = tempnam(sys_get_temp_dir(), 'env_test_');
        file_put_contents($tempPath, "APP_NAME=OldName\nAPP_KEY=oldkey\n");

        $writer = new InstallerEnvWriter($tempPath);

        $ok = $writer->writeMany([
            'APP_NAME' => 'Clockwork Production',
            'APP_KEY' => 'base64:newsecretkey123',
            'APP_URL' => 'https://panel.example.com',
            'DB_HOST' => 'mysql.internal',
        ]);

        expect($ok)->toBeTrue();

        $content = file_get_contents($tempPath);
        expect($content)->toContain('APP_NAME="Clockwork Production"');
        expect($content)->toContain('APP_KEY=base64:newsecretkey123');
        expect($content)->toContain('APP_URL=https://panel.example.com');
        expect($content)->toContain('DB_HOST=mysql.internal');

        expect(getenv('APP_NAME'))->toBe('Clockwork Production');
        expect(getenv('APP_URL'))->toBe('https://panel.example.com');
        expect(config('app.name'))->toBe('Clockwork Production');

        // DB_HOST is deliberately NOT reflected into the live process during tests (see
        // InstallerEnvWriter::writeMany's $isTestProtected) — leaking a DB connection mutation
        // process-wide would corrupt whatever real test DB the rest of the suite is using. The
        // file write above still happened; only the runtime env/config side effect is skipped.
        expect(getenv('DB_HOST'))->not->toBe('mysql.internal');

        @unlink($tempPath);
    });

    it('safely quotes values containing spaces or special characters', function () {
        $writer = new InstallerEnvWriter;

        expect($writer->formatEnvValue('simple'))->toBe('simple');
        expect($writer->formatEnvValue('with space'))->toBe('"with space"');
        expect($writer->formatEnvValue('hash#symbol'))->toBe('"hash#symbol"');
        expect($writer->formatEnvValue('quote"val'))->toBe('"quote\"val"');
        expect($writer->formatEnvValue(''))->toBe('');
    });
});
