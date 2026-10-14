<?php

namespace App\Support\Mail;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches the white-label logo once a day so client-facing emails can embed it
 * inline (CID) instead of hot-linking — inline images render even where a mail
 * client blocks remote images by default.
 */
class BrandLogo
{
    /** Raw image bytes, or null when the URL is unusable or the fetch fails. */
    public static function bytes(string $url): ?string
    {
        if (! Str::startsWith($url, ['https://', 'http://'])) {
            return null;
        }

        try {
            $encoded = Cache::remember('client-report-logo:'.md5($url), now()->addDay(), function () use ($url) {
                $response = Http::timeout(5)->get($url);

                return $response->successful() && str_starts_with((string) $response->header('Content-Type'), 'image/')
                    ? base64_encode($response->body())
                    : '';
            });
        } catch (Throwable) {
            return null;
        }

        return $encoded !== '' ? (base64_decode($encoded, true) ?: null) : null;
    }
}
