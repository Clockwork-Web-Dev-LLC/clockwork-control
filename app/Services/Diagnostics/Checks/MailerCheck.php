<?php

namespace App\Services\Diagnostics\Checks;

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\DiagnosticCheck;
use Throwable;

/**
 * Connectivity-only mailer check — opens a TCP socket to the configured SMTP
 * host:port and reads the greeting. Does NOT send a real message. A live
 * "send test email" lives behind its own button so this page is safe to
 * spam-click.
 *
 * Returns "skipped" for non-SMTP transports (log, array, sendmail) since
 * connectivity isn't a meaningful concept there.
 */
class MailerCheck implements DiagnosticCheck
{
    public function id(): string
    {
        return 'mailer';
    }

    public function name(): string
    {
        return 'Mailer (SMTP)';
    }

    public function description(): string
    {
        return 'Open a TCP socket to the configured SMTP host and read its banner.';
    }

    public function run(): CheckResult
    {
        $mailer = (string) config('mail.default');
        if ($mailer !== 'smtp') {
            return CheckResult::skipped(
                "Transport is '{$mailer}', not smtp",
                'Connectivity check only applies to SMTP transports.',
            );
        }

        $cfg = config('mail.mailers.smtp', []);
        $host = (string) ($cfg['host'] ?? '');
        $port = (int) ($cfg['port'] ?? 0);
        if ($host === '' || $port === 0) {
            return CheckResult::fail('SMTP host/port not configured');
        }

        $start = microtime(true);
        $errno = 0;
        $errstr = '';
        try {
            $fp = @fsockopen($host, $port, $errno, $errstr, 5);
            if (! $fp) {
                return CheckResult::fail(
                    "Cannot reach {$host}:{$port}",
                    "errno={$errno} {$errstr}",
                    (int) ((microtime(true) - $start) * 1000),
                );
            }
            stream_set_timeout($fp, 5);
            $banner = trim((string) fgets($fp, 1024));
            @fclose($fp);

            return CheckResult::ok(
                "Reached {$host}:{$port}",
                $banner !== '' ? $banner : null,
                (int) ((microtime(true) - $start) * 1000),
            );
        } catch (Throwable $e) {
            return CheckResult::fail(
                "Cannot reach {$host}:{$port}",
                $e->getMessage(),
                (int) ((microtime(true) - $start) * 1000),
            );
        }
    }
}
