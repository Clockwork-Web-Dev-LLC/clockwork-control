<?php

namespace Modules\Core;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ModuleDirectoryClient
{
    public const CACHE_KEY = 'clockwork.modules.directory';

    public function __construct(
        protected ?string $apiUrl = null,
        protected ?int $cacheTtl = null,
    ) {
        $this->apiUrl = $apiUrl ?? (string) config('clockwork.modules.api_url', 'https://clockworkcontrol.com/api/modules.json');
        $this->cacheTtl = $cacheTtl ?? (int) config('clockwork.modules.cache_ttl', 21600);
    }

    /**
     * @return array{
     *     schema_version: string,
     *     generated_at: string,
     *     total_modules: int,
     *     modules: list<array<string, mixed>>,
     *     source: 'network' | 'cache' | 'fallback'
     * }
     */
    public function fetch(bool $forceRefresh = false): array
    {
        if ($forceRefresh) {
            Cache::forget(self::CACHE_KEY);
        }

        if (! $forceRefresh && Cache::has(self::CACHE_KEY)) {
            /** @var array{schema_version: string, generated_at: string, total_modules: int, modules: list<array<string, mixed>>} $cached */
            $cached = Cache::get(self::CACHE_KEY);

            return [
                ...$cached,
                'source' => 'cache',
            ];
        }

        try {
            $response = Http::timeout(8)
                ->withHeaders([
                    'Accept' => 'application/json',
                    'User-Agent' => 'ClockworkControl-DirectoryClient/1.0',
                ])
                ->get($this->apiUrl);

            if ($response->successful()) {
                $data = $response->json();
                if (is_array($data) && isset($data['modules']) && is_array($data['modules'])) {
                    Cache::put(self::CACHE_KEY, $data, $this->cacheTtl);

                    return [
                        ...$data,
                        'source' => 'network',
                    ];
                }
            }
        } catch (Throwable $e) {
            Log::warning('Failed fetching module directory feed from '.$this->apiUrl.': '.$e->getMessage());
        }

        if (Cache::has(self::CACHE_KEY)) {
            /** @var array{schema_version: string, generated_at: string, total_modules: int, modules: list<array<string, mixed>>} $stale */
            $stale = Cache::get(self::CACHE_KEY);

            return [
                ...$stale,
                'source' => 'cache',
            ];
        }

        return $this->fallbackCatalog();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(bool $forceRefresh = false): array
    {
        return $this->fetch($forceRefresh)['modules'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function byCategory(string $category, bool $forceRefresh = false): array
    {
        return array_values(array_filter(
            $this->all($forceRefresh),
            fn (array $m) => ($m['category'] ?? '') === $category
        ));
    }

    /**
     * @return ?array<string, mixed>
     */
    public function find(string $id, bool $forceRefresh = false): ?array
    {
        foreach ($this->all($forceRefresh) as $m) {
            if (($m['id'] ?? '') === $id) {
                return $m;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     schema_version: string,
     *     generated_at: string,
     *     total_modules: int,
     *     modules: list<array<string, mixed>>,
     *     source: 'fallback'
     * }
     */
    protected function fallbackCatalog(): array
    {
        $bundled = ModuleCatalog::bundled();
        $modules = [];

        foreach ($bundled as $id => $item) {
            $manifest = $item['manifest'];
            $modules[] = [
                'id' => $manifest->id,
                'name' => $manifest->name,
                'description' => $manifest->description,
                'category' => $item['category'],
                'author' => 'Clockwork Web Dev',
                'author_url' => 'https://clockworkwd.com',
                'status' => $manifest->status,
                'status_note' => $manifest->statusNote,
                'repository' => 'https://github.com/Clockwork-Web-Dev-LLC/clockwork-control',
                'package_name' => "clockwork/module-{$manifest->id}",
                'composer_type' => 'clockworkcontrol-module',
                'icon' => $manifest->id,
                'tags' => [$item['category'], $manifest->id],
                'min_version' => '1.0.0',
                'is_bundled' => true,
                'capabilities' => [],
                'credential_fields' => array_map(
                    fn ($meta, $key) => [
                        'key' => (string) $key,
                        'label' => (string) ($meta['label'] ?? $key),
                        'secret' => (bool) ($meta['secret'] ?? true),
                    ],
                    $manifest->credentialFields,
                    array_keys($manifest->credentialFields)
                ),
            ];
        }

        return [
            'schema_version' => '1.0',
            'generated_at' => now()->toIso8601String(),
            'total_modules' => count($modules),
            'modules' => $modules,
            'source' => 'fallback',
        ];
    }
}
