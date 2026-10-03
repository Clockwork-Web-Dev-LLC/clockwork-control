<?php

namespace Modules\EmailAuth\Services;

use Modules\EmailAuth\Contracts\DnsTxtResolver;
use Throwable;

class NativeDnsTxtResolver implements DnsTxtResolver
{
    public function query(string $domain, string $type = 'TXT'): array
    {
        $domain = strtolower(trim($domain));
        $upperType = strtoupper($type);

        $dnsType = match ($upperType) {
            'TXT' => DNS_TXT,
            'MX' => DNS_MX,
            default => DNS_ANY,
        };

        try {
            $rawRecords = @dns_get_record($domain, $dnsType);

            if ($rawRecords === false) {
                return [
                    'status' => self::STATUS_ERROR,
                    'records' => [],
                    'error' => 'Native DNS resolution failed',
                ];
            }

            if (empty($rawRecords)) {
                return [
                    'status' => self::STATUS_NO_DATA,
                    'records' => [],
                    'error' => null,
                ];
            }

            $records = [];
            foreach ($rawRecords as $rec) {
                if ($upperType === 'TXT' && isset($rec['txt'])) {
                    // In PHP dns_get_record, 'entries' array contains split strings if present
                    if (! empty($rec['entries'])) {
                        $records[] = implode('', $rec['entries']);
                    } else {
                        $records[] = (string) $rec['txt'];
                    }
                } elseif ($upperType === 'MX' && isset($rec['target'])) {
                    $records[] = [
                        'priority' => (int) ($rec['pri'] ?? 0),
                        'target' => rtrim((string) $rec['target'], '.'),
                    ];
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
        } catch (Throwable $e) {
            return [
                'status' => self::STATUS_ERROR,
                'records' => [],
                'error' => $e->getMessage(),
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
}
