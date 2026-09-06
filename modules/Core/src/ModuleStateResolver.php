<?php

namespace Modules\Core;

use Throwable;

/**
 * Resolves whether a module is enabled at runtime.
 *
 * Reads from the installed_modules table once per request lifecycle and
 * memoizes the map. If the table does not exist (e.g. pre-migration or
 * fresh install) or if a module row is absent, it fails open (returns true)
 * so existing installations and test runs remain completely unaffected.
 */
class ModuleStateResolver
{
    /** @var array<string, bool>|null */
    protected ?array $state = null;

    public function isEnabled(string $moduleId): bool
    {
        if ($this->state === null) {
            $this->state = $this->loadState();
        }

        return $this->state[$moduleId] ?? true;
    }

    /**
     * Clear the memoized state cache (useful in tests or after updating modules).
     */
    public function flush(): void
    {
        $this->state = null;
    }

    /**
     * @return array<string, bool>
     */
    protected function loadState(): array
    {
        try {
            return InstalledModule::pluck('enabled', 'module_id')
                ->mapWithKeys(fn ($enabled, $id) => [(string) $id => (bool) $enabled])
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
