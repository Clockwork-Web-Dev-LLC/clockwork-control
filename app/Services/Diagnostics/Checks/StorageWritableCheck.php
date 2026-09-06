<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Throwable;

/**
 * Writes a tiny temp file under storage/app and removes it. Catches
 * permission/disk-full breakage that would otherwise only show up the
 * next time something tries to log or cache.
 */
class StorageWritableCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'storage';
    }

    public function name(): string
    {
        return 'Storage writable';
    }

    public function description(): string
    {
        return 'Write + delete a probe file in storage/app.';
    }

    public function run(): CheckResult
    {
        $dir = storage_path('app');
        $path = $dir.'/diagnostics-probe.tmp';

        $start = microtime(true);
        try {
            if (! is_dir($dir)) {
                return CheckResult::fail("Directory missing: {$dir}");
            }

            $bytes = @file_put_contents($path, 'probe-'.time());
            if ($bytes === false) {
                return CheckResult::fail("Cannot write to {$dir}", 'file_put_contents returned false');
            }

            @unlink($path);
            $ms = (int) ((microtime(true) - $start) * 1000);

            $free = @disk_free_space($dir);
            $detail = $free !== false ? sprintf('%.1f GB free on volume', $free / 1024 / 1024 / 1024) : null;

            return CheckResult::ok("Writable · {$bytes} bytes round-tripped", $detail, $ms);
        } catch (Throwable $e) {
            return CheckResult::fail(
                'Write probe failed',
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
