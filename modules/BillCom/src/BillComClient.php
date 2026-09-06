<?php

namespace Modules\BillCom;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Read-only client for the Bill.com v2 API.
 *
 * Why v2 not v3: Bill.com's v3 API docs are extensive but the documented
 * production hostname (gateway.bill.com) has no DNS records — it's not
 * actually live for standard accounts. The v2 API at api.bill.com/api/v2
 * is the working surface.
 *
 * Auth: POST /api/v2/Login.json with form-encoded {userName, password, orgId,
 * devKey} → response envelope {response_status: 0, response_data: {sessionId}}.
 * response_status:0 means success, :1 means error (counterintuitive).
 *
 * Subsequent calls: form-encoded {devKey, sessionId, data: '<json>'} POSTed
 * to endpoints like /List/Customer.json, /List/Invoice.json, /List/Item.json.
 *
 * Sessions expire after ~35 min idle → response_status:1 with "session
 * invalid" message. We cache for 25 min and re-login on session-invalid.
 *
 * NOT implemented: writes. Bill.com is the agency's source of truth; Clockwork
 * only reads.
 */
class BillComClient
{
    private const SESSION_CACHE_KEY = 'bill_com.session_id';

    /** Sessions live ~35 min server-side; cache for 25 to give breathing room. */
    private const SESSION_CACHE_TTL_SECONDS = 25 * 60;

    public function __construct(
        protected ?string $username = null,
        protected ?string $password = null,
        protected ?string $orgId = null,
        protected ?string $devKey = null,
        protected ?string $baseUrl = null,
        protected ?int $timeout = null,
    ) {
        $this->username ??= (string) config('clockwork.bill_com.username');
        $this->password ??= (string) config('clockwork.bill_com.password');
        $this->orgId ??= (string) config('clockwork.bill_com.org_id');
        $this->devKey ??= (string) config('clockwork.bill_com.dev_key');
        $this->baseUrl ??= (string) config('clockwork.bill_com.base_url', 'https://api.bill.com/api/v2');
        $this->timeout ??= (int) config('clockwork.bill_com.timeout', 30);
    }

    /**
     * Verify credentials work. Returns the session ID on success; throws on failure.
     */
    public function ping(): string
    {
        Cache::forget(self::SESSION_CACHE_KEY);

        return $this->sessionId();
    }

    /**
     * List customers, paginated. Yields rows one at a time.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function customers(int $maxPerPage = 100): \Generator
    {
        yield from $this->paginate('/List/Customer.json', $maxPerPage);
    }

    /**
     * List Items (the product/service catalog), paginated.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function items(int $maxPerPage = 100): \Generator
    {
        yield from $this->paginate('/List/Item.json', $maxPerPage);
    }

    /**
     * List invoices in a date window, paginated. v2 filter syntax is an array
     * of `{field, op, value}` objects. invoiceLineItems are inlined in the
     * response by default — no nested flag needed.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function invoicesSince(\DateTimeInterface $since, int $maxPerPage = 100): \Generator
    {
        $sinceDate = $since->format('Y-m-d');
        yield from $this->paginate('/List/Invoice.json', $maxPerPage, [
            'filters' => [
                ['field' => 'invoiceDate', 'op' => '>=', 'value' => $sinceDate],
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $extraData
     * @return \Generator<int, array<string, mixed>>
     */
    private function paginate(string $route, int $maxPerPage, array $extraData = []): \Generator
    {
        $start = 0;
        $safetyLimit = 200; // 200 pages × 100 = 20k rows
        $page = 0;

        do {
            $page++;
            if ($page > $safetyLimit) {
                throw new RuntimeException("Bill.com pagination exceeded {$safetyLimit} pages on {$route}; aborting.");
            }

            $data = array_merge(['start' => $start, 'max' => $maxPerPage], $extraData);
            $response = $this->postWithSession($route, ['data' => json_encode($data)]);
            $body = $this->unwrap($response, $route);

            $rows = is_array($body) ? $body : [];
            $count = 0;
            foreach ($rows as $row) {
                if (is_array($row)) {
                    yield $row;
                    $count++;
                }
            }

            $start += $count;
            // Stop when the server returned fewer rows than max (last page)
            // or zero rows (empty result).
        } while ($count >= $maxPerPage);
    }

    /**
     * Issue an authenticated POST. Re-logs in once on session-invalid.
     *
     * @param  array<string, string>  $body
     */
    private function postWithSession(string $route, array $body): Response
    {
        $send = function (array $extraBody) use ($route): Response {
            return Http::timeout($this->timeout)
                ->asForm()
                ->retry(2, 500, throw: false)
                ->post(rtrim($this->baseUrl, '/').'/'.ltrim($route, '/'), array_merge([
                    'devKey' => $this->devKey,
                    'sessionId' => $this->sessionId(),
                ], $extraBody));
        };

        $response = $send($body);

        // Detect expired session in the v2 envelope and re-login once.
        if ($this->isSessionInvalid($response)) {
            Cache::forget(self::SESSION_CACHE_KEY);
            $response = $send($body);
        }

        if ($response->failed()) {
            $excerpt = mb_strimwidth((string) $response->body(), 0, 300, '…');
            throw new RuntimeException("Bill.com POST {$route} HTTP {$response->status()}: {$excerpt}");
        }

        return $response;
    }

    /**
     * Read the v2 envelope. response_status === 0 means success;
     * anything else is an error (counterintuitive but it's their convention).
     */
    private function unwrap(Response $response, string $route): mixed
    {
        $body = $response->json();
        if (! is_array($body)) {
            throw new RuntimeException("Bill.com {$route} returned non-array body");
        }

        $status = $body['response_status'] ?? null;
        if ($status !== 0) {
            $message = (string) ($body['response_message'] ?? 'unknown error');
            $errorMessage = isset($body['response_data']['error_message'])
                ? (string) $body['response_data']['error_message']
                : '';
            throw new RuntimeException("Bill.com {$route} {$message} {$errorMessage}");
        }

        return $body['response_data'] ?? [];
    }

    private function isSessionInvalid(Response $response): bool
    {
        $body = $response->json();
        if (! is_array($body)) {
            return false;
        }
        if (($body['response_status'] ?? null) === 0) {
            return false;
        }

        $errorCode = (string) ($body['response_data']['error_code'] ?? '');
        $errorMessage = (string) ($body['response_data']['error_message'] ?? '');

        // BDC_1107 = invalid session. Match defensively on the message too in case
        // Bill.com changes the code; "session" + "invalid|expired" is the signal.
        return $errorCode === 'BDC_1107'
            || (stripos($errorMessage, 'session') !== false && stripos($errorMessage, 'invalid') !== false)
            || (stripos($errorMessage, 'session') !== false && stripos($errorMessage, 'expired') !== false);
    }

    private function sessionId(): string
    {
        $cached = Cache::get(self::SESSION_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        if ($this->username === '' || $this->password === '' || $this->orgId === '' || $this->devKey === '') {
            throw new RuntimeException('Bill.com credentials are not configured. Set CLOCKWORK_BILL_COM_USERNAME / PASSWORD / ORG_ID / DEV_KEY in .env.');
        }

        $loginUrl = rtrim($this->baseUrl, '/').'/Login.json';
        $response = Http::timeout($this->timeout)
            ->asForm()
            ->post($loginUrl, [
                'userName' => $this->username,
                'password' => $this->password,
                'orgId' => $this->orgId,
                'devKey' => $this->devKey,
            ]);

        if ($response->failed()) {
            $excerpt = mb_strimwidth((string) $response->body(), 0, 300, '…');
            throw new RuntimeException("Bill.com Login.json HTTP {$response->status()}: {$excerpt}");
        }

        $body = $response->json();
        if (! is_array($body) || ($body['response_status'] ?? null) !== 0) {
            $message = is_array($body) ? (string) ($body['response_message'] ?? 'unknown') : 'non-array body';
            $errorMessage = isset($body['response_data']['error_message'])
                ? (string) $body['response_data']['error_message']
                : '';
            throw new RuntimeException("Bill.com login rejected: {$message} {$errorMessage}");
        }

        $sessionId = (string) ($body['response_data']['sessionId'] ?? '');
        if ($sessionId === '') {
            throw new RuntimeException('Bill.com login returned no sessionId');
        }

        Cache::put(self::SESSION_CACHE_KEY, $sessionId, self::SESSION_CACHE_TTL_SECONDS);

        return $sessionId;
    }
}
