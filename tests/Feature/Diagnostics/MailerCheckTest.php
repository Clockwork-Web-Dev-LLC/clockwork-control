<?php

use App\Services\Diagnostics\CheckResult;
use App\Services\Diagnostics\Checks\MailerCheck;

/**
 * Coverage for MailerCheck (App\Services\Diagnostics\DiagnosticCheck).
 * Verified against the real class: this is NOT an HTTP check — it opens a
 * raw TCP socket (fsockopen) to the configured mail.mailers.smtp host:port
 * and reads whatever banner line comes back. It never sends mail, so
 * Mail::fake() is irrelevant here; there is no Illuminate HTTP client call
 * for Http::fake() to intercept either.
 *
 *   - mail.default !== 'smtp'        -> STATUS_SKIPPED (log/array/sendmail
 *     transports have no "connectivity" concept).
 *   - smtp but host/port unset       -> STATUS_FAIL.
 *   - fsockopen fails                -> STATUS_FAIL with "errno=... " detail.
 *   - fsockopen succeeds             -> STATUS_OK with whatever banner line
 *     was read (or null detail if the peer sent nothing).
 *
 * The "ok" and "real connection failure" cases below exercise a real
 * loopback TCP socket rather than mocking fsockopen (which can't be faked
 * short of intercepting a global PHP function) — per this phase's
 * convention for a check whose entire job is confirming real socket
 * connectivity works. The "ok" case spawns a short-lived background PHP
 * process (via proc_open) that binds 127.0.0.1 on an ephemeral port, writes
 * one banner line to the first connection, and exits — a real SMTP server
 * would do the same on connect, before any command is sent.
 */
describe('MailerCheck', function () {
    it("skips cleanly when the mail transport isn't smtp", function () {
        config(['mail.default' => 'log']);

        $result = app(MailerCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_SKIPPED)
            ->and($result->summary)->toBe("Transport is 'log', not smtp");
    });

    it('fails when the smtp transport has no host/port configured', function () {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '',
            'mail.mailers.smtp.port' => 0,
        ]);

        $result = app(MailerCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('SMTP host/port not configured');
    });

    it('fails with a real connection error when nothing is listening on the configured port', function () {
        // Port 1 is a reserved, essentially-never-bound port — connecting to
        // it on loopback gets an immediate real ECONNREFUSED from the OS
        // rather than a slow timeout, so this stays fast without mocking.
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1,
        ]);

        $result = app(MailerCheck::class)->run();

        expect($result->status)->toBe(CheckResult::STATUS_FAIL)
            ->and($result->summary)->toBe('Cannot reach 127.0.0.1:1')
            ->and($result->detail)->toContain('errno=');
    });

    it('returns ok with the banner line from a real local SMTP-shaped listener', function () {
        $port = random_int(20000, 60000);

        // A real SMTP server sends its greeting banner immediately on
        // connect, before any command — this tiny background listener
        // mimics exactly that. Accepts up to 3 connections (not just 1):
        // the bind-probe loop below opens and closes a throwaway connection
        // to detect when the listener is ready, which would otherwise
        // consume the single accept() meant for the real MailerCheck
        // connection that follows.
        $listenerCode = sprintf(
            '$s = @stream_socket_server("tcp://127.0.0.1:%d", $en, $es); '.
            'if (!$s) { exit(1); } '.
            'for ($i = 0; $i < 3; $i++) { '.
            '  $c = @stream_socket_accept($s, 10); '.
            '  if (!$c) { break; } '.
            '  @fwrite($c, "220 fake.smtp.test ESMTP Ready\r\n"); usleep(300000); @fclose($c); '.
            '} '.
            'fclose($s);',
            $port,
        );

        $process = proc_open(
            sprintf('php -r %s', escapeshellarg($listenerCode)),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        expect($process)->not->toBeFalse();

        // Poll for the listener to actually be bound instead of a fixed
        // sleep — a fixed delay is exactly the kind of thing that's fine on
        // a fast local machine and flaky on a loaded CI runner. Give it up
        // to 2s in 10ms increments; a real bind is normally near-instant.
        $bound = false;
        for ($i = 0; $i < 200; $i++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.05);
            if ($probe !== false) {
                fclose($probe);
                $bound = true;
                break;
            }
            usleep(10000);
        }
        expect($bound)->toBeTrue('listener never bound port '.$port);

        try {
            config([
                'mail.default' => 'smtp',
                'mail.mailers.smtp.host' => '127.0.0.1',
                'mail.mailers.smtp.port' => $port,
            ]);

            $result = app(MailerCheck::class)->run();
        } finally {
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }

        expect($result->status)->toBe(CheckResult::STATUS_OK)
            ->and($result->summary)->toBe("Reached 127.0.0.1:{$port}")
            ->and($result->detail)->toBe('220 fake.smtp.test ESMTP Ready');
    });
});
