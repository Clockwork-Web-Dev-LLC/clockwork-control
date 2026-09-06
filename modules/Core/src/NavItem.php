<?php

namespace Modules\Core;

/**
 * A single gear-menu link a module contributes. Kept deliberately small —
 * no icon color, no active-state highlighting, no badge — those exist on
 * the hand-written core nav items but nothing needs them here yet; add
 * them if/when a real module contribution needs them.
 */
final readonly class NavItem
{
    public function __construct(
        public string $label,
        public string $icon,
        public string $route,
        public ?\Closure $when = null,
    ) {}

    public function isVisible(): bool
    {
        return $this->when === null || ($this->when)();
    }
}
