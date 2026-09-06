<?php

namespace App\Support;

use App\Models\IntegrationCredential;

/**
 * DB-first, config()-fallback credential lookup. Mirrors Settings' per-request
 * cache shape, but is deliberately a separate class over a separate table —
 * app_settings is plain unencrypted JSON, and secrets need the 'encrypted'
 * cast IntegrationCredential.value carries. Never touches the DB during
 * config() loading; the cache only loads on first get()/source() call.
 */
class CredentialResolver
{
    /** @var array<string, ?string> */
    private array $cache = [];

    private bool $loaded = false;

    /**
     * $path is the suffix after 'clockwork.', e.g. 'pressable.client_id' —
     * matches the config key each client already falls back to.
     */
    public function get(string $path, mixed $default = null): mixed
    {
        $this->load();

        if (array_key_exists($path, $this->cache)) {
            return $this->cache[$path];
        }

        return config("clockwork.{$path}", $default);
    }

    /**
     * Where a value currently comes from — for the settings UI only, never
     * used to drive behavior.
     *
     * @return 'database'|'env'|'unset'
     */
    public function source(string $path): string
    {
        $this->load();

        if (array_key_exists($path, $this->cache)) {
            return 'database';
        }

        $value = config("clockwork.{$path}");

        return ($value !== null && $value !== '') ? 'env' : 'unset';
    }

    public function put(string $path, ?string $value): void
    {
        [$integration, $key] = explode('.', $path, 2);

        IntegrationCredential::query()->updateOrCreate(
            ['integration' => $integration, 'key' => $key],
            ['value' => $value],
        );

        $this->cache[$path] = $value;
        $this->loaded = true;
    }

    public function forget(string $path): void
    {
        [$integration, $key] = explode('.', $path, 2);

        IntegrationCredential::query()
            ->where('integration', $integration)
            ->where('key', $key)
            ->delete();

        unset($this->cache[$path]);
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->cache = IntegrationCredential::query()
            ->get(['integration', 'key', 'value'])
            ->mapWithKeys(fn (IntegrationCredential $row) => [
                "{$row->integration}.{$row->key}" => rescue(fn () => $row->value, null, false),
            ])
            ->all();
        $this->loaded = true;
    }
}
