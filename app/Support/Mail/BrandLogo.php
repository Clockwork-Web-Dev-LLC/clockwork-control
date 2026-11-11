<?php

namespace App\Support\Mail;

use App\Services\Companion\CompanionBrandingManager;
use App\Support\SsrfGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fetches the white-label logo once a day so client-facing emails can embed it
 * inline (CID) instead of hot-linking — inline images render even where a mail
 * client blocks remote images by default.
 */
class BrandLogo
{
    private const MAX_BYTES = 2_097_152;

    /**
     * Raw image bytes, or null when the URL is unusable or the fetch fails.
     *
     * A logo uploaded in Settings lives on Control's public disk, whose URL
     * is often relative ("/storage/branding/…") or on Control's own host,
     * which clients can't reach: that one is read from the disk instead.
     */
    public static function bytes(string $url): ?string
    {
        $local = self::fromPublicDisk($url);
        if ($local !== null) {
            return $local;
        }

        if (! Str::startsWith($url, ['https://', 'http://'])) {
            return null;
        }

        try {
            $encoded = Cache::remember('client-report-logo:'.md5($url), now()->addDay(), function () use ($url) {
                SsrfGuard::assertPublic($url);
                $response = Http::timeout(5)
                    ->withOptions([
                        'allow_redirects' => [
                            'max' => 3,
                            'strict' => true,
                            'protocols' => ['http', 'https'],
                            'on_redirect' => SsrfGuard::onRedirect(),
                        ],
                    ])
                    ->get($url);

                $body = $response->body();

                return $response->successful()
                    && str_starts_with((string) $response->header('Content-Type'), 'image/')
                    && strlen($body) <= self::MAX_BYTES
                    ? base64_encode($body)
                    : '';
            });
        } catch (Throwable) {
            return null;
        }

        return $encoded !== '' ? (base64_decode($encoded, true) ?: null) : null;
    }

    /** The image's MIME type, for embedding it ("image/png" when unknown). */
    public static function mimeType(string $bytes): string
    {
        try {
            $type = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        } catch (Throwable) {
            $type = false;
        }

        return is_string($type) && str_starts_with($type, 'image/') ? $type : 'image/png';
    }

    /**
     * Whether an email may link to $url as a remote image: an absolute
     * http(s) URL that isn't on Control's own (private) host.
     */
    public static function linkable(string $url): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return Str::startsWith($url, ['https://', 'http://']) && is_string($host)
            && $host !== parse_url((string) config('app.url'), PHP_URL_HOST)
            && self::publicDiskPath($url) === null;
    }

    private static function fromPublicDisk(string $url): ?string
    {
        $path = self::publicDiskPath($url);
        if ($path === null) {
            return null;
        }

        try {
            $disk = Storage::disk(CompanionBrandingManager::LOGO_DISK);
            $bytes = $disk->exists($path) ? $disk->get($path) : null;
        } catch (Throwable) {
            return null;
        }

        return is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /** The path on the public disk that $url points at, or null when it's elsewhere. */
    private static function publicDiskPath(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        try {
            $prefix = rtrim(Storage::disk(CompanionBrandingManager::LOGO_DISK)->url(''), '/').'/';
        } catch (Throwable) {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $prefixPath = parse_url($prefix, PHP_URL_PATH) ?: $prefix;
        $sameHost = ! Str::startsWith($url, ['https://', 'http://']) || Str::startsWith($url, $prefix)
            || parse_url($url, PHP_URL_HOST) === parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! $sameHost || ! is_string($path) || ! Str::startsWith($path, $prefixPath)) {
            return null;
        }

        $relative = ltrim(Str::after($path, $prefixPath), '/');

        return $relative !== '' && ! str_contains($relative, '..') ? rawurldecode($relative) : null;
    }
}
