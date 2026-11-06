<?php

namespace Modules\Seo\Google;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Modules\Seo\Models\GoogleConnection;
use Throwable;

/**
 * Authorized JSON calls to Google APIs on the agency connection.
 *
 * Retries only connection errors and 5xx answers. A 403 or 429 quota answer
 * is never sent again a second later; the caller stops that property for the
 * night and the trailing sync window catches up. A 401 drops the cached
 * access token and tries once more with a fresh one.
 */
class GoogleApi
{
    public function __construct(
        private readonly GoogleTokenProvider $tokens,
        private readonly int $timeout = 30,
    ) {}

    /** @param array<string, mixed> $query */
    public function get(GoogleConnection $connection, string $url, array $query = []): GoogleApiResult
    {
        return $this->send($connection, 'GET', $url, $query);
    }

    /** @param array<string, mixed> $body */
    public function post(GoogleConnection $connection, string $url, array $body): GoogleApiResult
    {
        return $this->send($connection, 'POST', $url, $body);
    }

    /** @param array<string, mixed> $payload */
    private function send(GoogleConnection $connection, string $method, string $url, array $payload, bool $isRetry = false): GoogleApiResult
    {
        try {
            $token = $this->tokens->accessToken($connection);
        } catch (GoogleOAuthException $e) {
            return GoogleApiResult::failure(401, $e->getMessage(), $e->errorCode);
        }

        try {
            $request = Http::timeout($this->timeout)
                ->acceptJson()
                ->withToken($token)
                ->retry(2, 1000, fn (Throwable $e) => ! $e instanceof RequestException || $e->response->serverError(), throw: false);

            /** @var Response $response */
            $response = $method === 'GET' ? $request->get($url, $payload) : $request->post($url, $payload);
        } catch (ConnectionException $e) {
            return GoogleApiResult::failure(0, 'Could not reach Google: '.$e->getMessage(), 'connection');
        }

        if ($response->status() === 401 && ! $isRetry) {
            $this->tokens->forget($connection);

            return $this->send($connection, $method, $url, $payload, true);
        }

        if ($response->failed()) {
            return GoogleApiResult::failure($response->status(), $this->describe($response), $this->reason($response));
        }

        $json = $response->json();

        return GoogleApiResult::success($response->status(), is_array($json) ? $json : []);
    }

    private function describe(Response $response): string
    {
        $message = $response->json('error.message');

        return 'Google API HTTP '.$response->status().': '.(is_string($message) ? $message : mb_substr($response->body(), 0, 300));
    }

    /** Google puts the machine-readable reason in different places per API. */
    private function reason(Response $response): ?string
    {
        foreach (['error.errors.0.reason', 'error.status', 'error.details.0.reason'] as $path) {
            $value = $response->json($path);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
