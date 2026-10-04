<?php

namespace Modules\EmailAuth\Contracts;

interface DnsTxtResolver
{
    public const STATUS_OK = 'ok';

    public const STATUS_NXDOMAIN = 'nxdomain';

    public const STATUS_NO_DATA = 'no_data';

    public const STATUS_ERROR = 'error';

    /**
     * Query DNS records for a domain.
     *
     * @return array{status: string, records: array<int, string|array<string, mixed>>, error: ?string}
     */
    public function query(string $domain, string $type = 'TXT'): array;

    /**
     * Resolve all TXT records for a hostname, joining multi-part strings and trimming quotes.
     *
     * @return array<int, string>
     */
    public function resolveTxt(string $domain): array;

    /**
     * Resolve MX records for a domain.
     *
     * @return array<int, array{target: string, priority: int}>
     */
    public function resolveMx(string $domain): array;
}
