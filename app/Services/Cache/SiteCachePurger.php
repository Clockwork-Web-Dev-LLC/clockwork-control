<?php

namespace App\Services\Cache;

use App\Models\Site;
use App\Services\Cloudflare\CloudflareClient;
use App\Services\Companion\ClockworkCompanionClient;
use App\Services\Companion\CompanionInstaller;
use App\Services\Domains\RootDomainResolver;
use Illuminate\Support\Facades\Log;
use Modules\Pressable\PressableClient;
use Throwable;

/**
 * Best-effort multi-layer cache flush. Never throws to the caller — each
 * layer is isolated so a Cloudflare miss cannot skip Pressable, etc.
 */
class SiteCachePurger
{
    /**
     * @return array{ok: bool, layers: array<string, array{ok: bool, message: string}>}
     */
    public function purge(Site $site): array
    {
        $layers = [];

        $layers['companion'] = $this->purgeCompanion($site);
        $layers['pressable'] = $this->purgePressable($site);
        $layers['spinup_ssh'] = $this->purgeSpinupSshFallback($site, $layers['companion']['ok']);
        $layers['cloudflare'] = $this->purgeCloudflare($site);

        $any = collect($layers)->contains(fn (array $layer) => $layer['ok']);

        return [
            'ok' => $any,
            'layers' => $layers,
        ];
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function purgeCompanion(Site $site): array
    {
        if (! $site->companion_installed) {
            return ['ok' => false, 'message' => 'Companion not installed'];
        }

        if (! in_array('cache-flush', $site->companion_capabilities ?? [], true)) {
            return ['ok' => false, 'message' => 'Companion lacks cache-flush capability'];
        }

        try {
            $result = (new ClockworkCompanionClient($site))->flushCache();

            return [
                'ok' => (bool) ($result['ok'] ?? false),
                'message' => (string) ($result['detail'] ?? 'Companion flushed'),
            ];
        } catch (Throwable $e) {
            Log::warning('cache_purge.companion_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function purgePressable(Site $site): array
    {
        if (! $site->isPressable() || ! $site->pressable_site_id) {
            return ['ok' => false, 'message' => 'Not a Pressable site'];
        }

        try {
            $pressable = app(PressableClient::class);
            if ($pressable->isViewOnly()) {
                return ['ok' => false, 'message' => 'Pressable is view-only'];
            }
            $pressable->purgeEdgeCache($site->pressable_site_id);
            $pressable->flushObjectCache($site->pressable_site_id);

            return ['ok' => true, 'message' => 'Pressable edge + object cache flushed'];
        } catch (Throwable $e) {
            Log::warning('cache_purge.pressable_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function purgeSpinupSshFallback(Site $site, bool $companionFlushed): array
    {
        if ($companionFlushed) {
            return ['ok' => false, 'message' => 'Skipped — Companion already flushed'];
        }

        if (! $site->isSpinupWp() || ! $site->wp_path || ! $site->site_user || ! $site->server) {
            return ['ok' => false, 'message' => 'No SSH cache-flush path'];
        }

        try {
            $result = app(CompanionInstaller::class)->flushWpCache($site);
            if (($result['exit'] ?? 1) !== 0) {
                return ['ok' => false, 'message' => $result['output'] ?: 'wp cache flush failed'];
            }

            return ['ok' => true, 'message' => 'SSH wp cache flush'];
        } catch (Throwable $e) {
            Log::warning('cache_purge.spinup_ssh_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * @return array{ok: bool, message: string}
     */
    private function purgeCloudflare(Site $site): array
    {
        if ($site->cloudflare_state !== Site::CF_PROXIED) {
            return ['ok' => false, 'message' => 'Cloudflare is not proxied'];
        }

        $cloudflare = app(CloudflareClient::class);
        if (! $cloudflare->isWriteConfigured()) {
            return ['ok' => false, 'message' => 'Cloudflare write token not configured'];
        }

        try {
            $root = RootDomainResolver::resolve($site->domain);
            $zoneName = $root !== '' ? $root : $site->domain;
            $zone = $cloudflare->zoneByName($zoneName);
            if (! is_array($zone) || empty($zone['id'])) {
                return ['ok' => false, 'message' => 'Cloudflare zone not found'];
            }

            $files = array_values(array_unique([
                'https://'.$site->domain.'/',
                'https://www.'.$site->domain.'/',
            ]));
            $cloudflare->purgeCacheFiles((string) $zone['id'], $files);

            return ['ok' => true, 'message' => 'Cloudflare URL purge'];
        } catch (Throwable $e) {
            Log::warning('cache_purge.cloudflare_failed', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }
}
