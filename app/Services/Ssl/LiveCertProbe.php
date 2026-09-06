<?php

namespace App\Services\Ssl;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reads a certificate's expiry straight off the wire via a TLS handshake —
 * no SSH, no hosting-provider API, works for any HTTPS site regardless of
 * host. This is the only source of SSL data for sites with no spinupwp_id
 * (Pressable today), which have no equivalent to SpinupWP's per-site cert
 * API for SiteCertRefresher to call.
 */
class LiveCertProbe
{
    public function __construct(private readonly int $timeoutSeconds = 8) {}

    /**
     * Returns the certificate's notAfter date, or null if the handshake
     * failed for any reason (unreachable, DNS failure, connection refused,
     * TLS negotiation failure, malformed cert). Never throws — a probe
     * failure just means "couldn't determine expiry this run," not a
     * reason to interrupt a fleet-wide check loop.
     */
    public function expiryFor(string $domain): ?CarbonImmutable
    {
        try {
            $context = stream_context_create([
                'ssl' => [
                    'capture_peer_cert' => true,
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'SNI_enabled' => true,
                    'peer_name' => $domain,
                ],
            ]);

            $stream = @stream_socket_client(
                "ssl://{$domain}:443",
                $errno,
                $errstr,
                $this->timeoutSeconds,
                STREAM_CLIENT_CONNECT,
                $context,
            );

            if ($stream === false) {
                return null;
            }

            $params = stream_context_get_params($stream);
            fclose($stream);

            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if ($cert === null) {
                return null;
            }

            $parsed = openssl_x509_parse($cert);
            if (! is_array($parsed) || ! isset($parsed['validTo_time_t'])) {
                return null;
            }

            return CarbonImmutable::createFromTimestamp((int) $parsed['validTo_time_t']);
        } catch (Throwable) {
            return null;
        }
    }
}
