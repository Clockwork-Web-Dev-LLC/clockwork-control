<?php

namespace App\Services\Screenshots;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

class SiteScreenshotService
{
    /**
     * Capture or refresh the homepage screenshot for a given site.
     * Fetches from Automattic's mShots service and caches the resulting JPEG to public disk.
     */
    public function capture(Site $site, bool $force = false): bool
    {
        // Don't re-capture if we already captured within the last 7 days unless forced
        if (! $force
            && $site->screenshot_captured_at
            && $site->screenshot_captured_at->isAfter(now()->subDays(7))
            && $site->screenshot_path
            && Storage::disk('public')->exists($site->screenshot_path)) {
            return true;
        }

        try {
            $targetUrl = 'https://'.$site->domain;
            $mshotsUrl = 'https://s0.wp.com/mshots/v1/'.rawurlencode($targetUrl).'?w=800';

            $response = Http::timeout(15)
                ->withHeaders([
                    'User-Agent' => 'ClockworkControl/1.3.0 (+https://clockworkcontrol.com)',
                ])
                ->get($mshotsUrl);

            if (! $response->successful()) {
                Log::warning("Failed to fetch mShots screenshot for site {$site->domain}", [
                    'status' => $response->status(),
                ]);

                return false;
            }

            $body = $response->body();
            // Validate that we received non-empty binary image content
            if (strlen($body) < 100) {
                return false;
            }

            $filename = "screenshots/{$site->id}.jpg";
            Storage::disk('public')->put($filename, $body);

            $site->update([
                'screenshot_path' => $filename,
                'screenshot_captured_at' => now(),
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning("Exception capturing screenshot for {$site->domain}: ".$e->getMessage());

            return false;
        }
    }
}
