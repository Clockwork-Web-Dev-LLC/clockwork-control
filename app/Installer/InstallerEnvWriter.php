<?php

namespace App\Installer;

class InstallerEnvWriter
{
    /**
     * @param  ?string  $envPath  Custom path for testing isolation. Defaults to base_path('.env').
     */
    public function __construct(
        protected ?string $envPath = null,
    ) {}

    public function envPath(): string
    {
        if ($this->envPath !== null) {
            return $this->envPath;
        }

        if (app()->runningUnitTests()) {
            $token = getenv('TEST_TOKEN') ?: '1';
            $testDir = storage_path('framework/testing');
            if (! is_dir($testDir)) {
                @mkdir($testDir, 0755, true);
            }

            return "{$testDir}/.env.testing_{$token}";
        }

        return base_path('.env');
    }

    /**
     * Write multiple environment variables atomically, apply chmod 0600,
     * and update runtime environment state (putenv, $_ENV, $_SERVER, config).
     *
     * @param  array<string, scalar|null>  $keyValues
     */
    public function writeMany(array $keyValues): bool
    {
        $path = $this->envPath();

        if (! file_exists($path)) {
            $examplePath = base_path('.env.example');
            if (file_exists($examplePath)) {
                copy($examplePath, $path);
            } else {
                file_put_contents($path, '', LOCK_EX);
            }
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return false;
        }

        foreach ($keyValues as $key => $value) {
            $valStr = (string) ($value ?? '');
            $formatted = $this->formatEnvValue($valStr);
            $newLine = "{$key}={$formatted}";
            $pattern = '/^#?\s*'.preg_quote($key, '/').'\s*=.*$/m';

            if (preg_match($pattern, $contents)) {
                $contents = preg_replace($pattern, $newLine, $contents);
            } else {
                $contents = rtrim($contents).PHP_EOL.$newLine.PHP_EOL;
            }

            // Sync running process environment (protect test runner from DB, encryption key, and driver mutations).
            // APP_ENV/APP_DEBUG are the load-bearing ones here: putenv() is process-global, so leaking
            // APP_ENV=production mid-suite makes app()->runningUnitTests() return false for every test that
            // runs afterward in the same process (CSRF's own test-mode bypass, the installer gate's bypass,
            // etc. all key off it) — this bit the test suite for real before these two keys were added.
            $isTestProtected = app()->runningUnitTests() && in_array($key, [
                'APP_ENV', 'APP_DEBUG', 'APP_KEY',
                'DB_CONNECTION', 'DB_DATABASE', 'DB_HOST', 'DB_PORT', 'DB_USERNAME', 'DB_PASSWORD',
                'SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION',
            ], true);
            if (! $isTestProtected) {
                putenv("{$key}={$valStr}");
                $_ENV[$key] = $valStr;
                $_SERVER[$key] = $valStr;

                $this->syncConfig($key, $valStr);
            }
        }

        $written = file_put_contents($path, $contents, LOCK_EX) !== false;

        if ($written && file_exists($path)) {
            @chmod($path, 0600);
        }

        return $written;
    }

    /**
     * Write a single environment variable.
     */
    public function write(string $key, ?string $value): bool
    {
        return $this->writeMany([$key => $value]);
    }

    /**
     * Format value safely with quotes if spaces or special characters exist.
     */
    public function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/\s|"|\'|#|\$|\\\\/', $value)) {
            return '"'.str_replace(['\\', '"'], ['\\\\', '\"'], $value).'"';
        }

        return $value;
    }

    /**
     * Map common env keys to their runtime Laravel config paths.
     */
    protected function syncConfig(string $key, string $value): void
    {
        $map = [
            'APP_NAME' => 'app.name',
            'APP_KEY' => 'app.key',
            'APP_URL' => 'app.url',
            'APP_TIMEZONE' => 'app.timezone',
            'DB_CONNECTION' => 'database.default',
            'DB_HOST' => 'database.connections.mysql.host',
            'DB_PORT' => 'database.connections.mysql.port',
            'DB_DATABASE' => 'database.connections.mysql.database',
            'DB_USERNAME' => 'database.connections.mysql.username',
            'DB_PASSWORD' => 'database.connections.mysql.password',
            'SESSION_DRIVER' => 'session.driver',
            'CACHE_STORE' => 'cache.default',
            'QUEUE_CONNECTION' => 'queue.default',
            'MAIL_MAILER' => 'mail.default',
            'MAIL_HOST' => 'mail.mailers.smtp.host',
            'MAIL_PORT' => 'mail.mailers.smtp.port',
            'MAIL_USERNAME' => 'mail.mailers.smtp.username',
            'MAIL_PASSWORD' => 'mail.mailers.smtp.password',
            'MAIL_FROM_ADDRESS' => 'mail.from.address',
            'MAIL_FROM_NAME' => 'mail.from.name',
            'GOOGLE_CLIENT_ID' => 'services.google.client_id',
            'GOOGLE_CLIENT_SECRET' => 'services.google.client_secret',
            'GOOGLE_REDIRECT_URI' => 'services.google.redirect',
        ];

        if (isset($map[$key])) {
            config([$map[$key] => $value]);
        }
    }
}
