<?php

namespace App\Services\Logs;

use App\Models\Site;
use App\Models\ThreatLog;
use Carbon\CarbonImmutable;

class NginxLogParser
{
    /**
     * Combined log format:
     *   $remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent
     *   "$http_referer" "$http_user_agent"
     */
    private const PATTERN = '/^(?<ip>\S+) \S+ \S+ \[(?<time>[^\]]+)\] "(?<method>\S+)\s(?<path>[^"]*?)\s(?<protocol>HTTP\/[\d.]+)" (?<status>\d+) (?<size>\d+|-) "(?<referer>[^"]*)" "(?<ua>[^"]*)"/';

    /**
     * Parse a chunk of nginx access log text into ThreatLog rows.
     *
     * Returns an array shaped for `ThreatLog::insert()`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function parse(string $contents, Site $site): array
    {
        $rows = [];
        $now = now();

        foreach (preg_split('/\r?\n/', $contents) ?: [] as $line) {
            if ($line === '') {
                continue;
            }

            if (! preg_match(self::PATTERN, $line, $m)) {
                continue;
            }

            $eventAt = CarbonImmutable::createFromFormat('d/M/Y:H:i:s O', $m['time']) ?: $now;

            $rows[] = [
                'site_id' => $site->id,
                'source' => ThreatLog::SOURCE_NGINX,
                'event_at' => $eventAt,
                'ip' => $m['ip'],
                'user_agent' => mb_substr($m['ua'] ?? '', 0, 1024),
                'request_path' => mb_substr($m['path'] ?? '', 0, 2048),
                'request_method' => mb_substr($m['method'] ?? '', 0, 16),
                'status_code' => (int) $m['status'],
                'raw' => json_encode([
                    'referer' => $m['referer'] ?? null,
                    'protocol' => $m['protocol'] ?? null,
                    'size' => $m['size'] ?? null,
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }
}
