<?php

namespace Modules\EmailAuth\Services;

use Illuminate\Support\Facades\Http;
use Modules\EmailAuth\Contracts\DnsTxtResolver;
use Throwable;

class DohDnsTxtResolver implements DnsTxtResolver
{
    protected const CLOUDFLARE_URL = 'https://cloudflare-dns.com/dns-query';

    protected const GOOGLE_URL = 'https://dns.google/resolve';

    protected const TIMEOUT_SECONDS = 5;

    public function query(string $domain, string $type = 'TXT'): array
    {
        $domain = strtolower(trim($domain));

        // 1. Try Cloudflare DoH
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Accept' => 'application/dns-json'])
                ->get(self::CLOUDFLARE_URL, [
                    'name' => $domain,
                    'type' => strtoupper($type),
                ]);

            if ($response->successful()) {
                return $this->parseDnsResponse($response->json(), $type);
            }
        } catch (Throwable) {
            // Fall through to Google DoH fallback
        }

        // 2. Fallback to Google DoH
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['Accept' => 'application/json'])
                ->get(self::GOOGLE_URL, [
                    'name' => $domain,
                    'type' => strtoupper($type),
                ]);

            if ($response->successful()) {
                return $this->parseDnsResponse($response->json(), $type);
            }

            return [
                'status' => self::STATUS_ERROR,
                'records' => [],
                'error' => 'DNS query failed on both Cloudflare and Google DoH endpoints.',
            ];
        } catch (Throwable $e) {
            return [
                'status' => self::STATUS_ERROR,
                'records' => [],
                'error' => 'DNS query error: '.$e->getMessage(),
            ];
        }
    }

    public function resolveTxt(string $domain): array
    {
        $res = $this->query($domain, 'TXT');

        return $res['status'] === self::STATUS_OK ? $res['records'] : [];
    }

    public function resolveMx(string $domain): array
    {
        $res = $this->query($domain, 'MX');

        return $res['status'] === self::STATUS_OK ? $res['records'] : [];
    }

    protected function parseDnsResponse(array $json, string $type): array
    {
        $rcode = (int) ($json['Status'] ?? -1);

        // RCODE 3 = NXDOMAIN
        if ($rcode === 3) {
            return [
                'status' => self::STATUS_NXDOMAIN,
                'records' => [],
                'error' => null,
            ];
        }

        if ($rcode !== 0) {
            return [
                'status' => self::STATUS_ERROR,
                'records' => [],
                'error' => "DNS server returned non-zero RCODE {$rcode}",
            ];
        }

        $answers = $json['Answer'] ?? [];
        if (empty($answers)) {
            return [
                'status' => self::STATUS_NO_DATA,
                'records' => [],
                'error' => null,
            ];
        }

        $records = [];
        $upperType = strtoupper($type);

        foreach ($answers as $ans) {
            $raw = (string) ($ans['data'] ?? '');
            if ($raw === '') {
                continue;
            }

            if ($upperType === 'TXT') {
                $cleaned = $this->cleanTxtRecord($raw);
                if ($cleaned !== '') {
                    $records[] = $cleaned;
                }
            } elseif ($upperType === 'MX') {
                $parsed = $this->cleanMxRecord($raw);
                if ($parsed !== null) {
                    $records[] = $parsed;
                }
            } else {
                $records[] = $raw;
            }
        }

        if (empty($records)) {
            return [
                'status' => self::STATUS_NO_DATA,
                'records' => [],
                'error' => null,
            ];
        }

        return [
            'status' => self::STATUS_OK,
            'records' => $records,
            'error' => null,
        ];
    }

    /**
     * Unescape and assemble split TXT records (e.g. `"part1" "part2"` -> `part1part2`).
     */
    protected function cleanTxtRecord(string $raw): string
    {
        // Many DoH implementations return concatenated quoted strings: `"chunk 1" "chunk 2"`
        if (preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $raw, $matches)) {
            $combined = '';
            foreach ($matches[1] as $chunk) {
                $combined .= stripcslashes($chunk);
            }

            return trim($combined);
        }

        // If not multiple quoted strings, trim enclosing quotes
        $trimmed = trim($raw);
        if (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"') && strlen($trimmed) >= 2) {
            $trimmed = substr($trimmed, 1, -1);
        }

        return trim(stripcslashes($trimmed));
    }

    protected function cleanMxRecord(string $raw): ?array
    {
        $parts = preg_split('/\s+/', trim($raw), 2);
        if (count($parts) < 2) {
            return null;
        }

        return [
            'priority' => (int) $parts[0],
            'target' => rtrim($parts[1], '.'),
        ];
    }
}
