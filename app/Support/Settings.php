<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * Tiny key-value settings facade backed by app_settings. Reads are cached per request
 * so a busy controller doesn't hammer the table. Writes invalidate the local cache.
 */
class Settings
{
    /** @var array<string, mixed> */
    private array $cache = [];

    private bool $loaded = false;

    public function get(string $key, mixed $default = null): mixed
    {
        $this->load();

        return array_key_exists($key, $this->cache) ? $this->cache[$key] : $default;
    }

    public function put(string $key, mixed $value): void
    {
        AppSetting::query()->updateOrCreate(['key' => $key], ['value' => $value]);
        $this->cache[$key] = $value;
        $this->loaded = true;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function putMany(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->put($key, $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->load();

        return $this->cache;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        try {
            $this->cache = AppSetting::query()
                ->get(['key', 'value'])
                ->pluck('value', 'key')
                ->all();
            $this->loaded = true;
        } catch (\Throwable) {
            $this->cache = [];
        }
    }
}
