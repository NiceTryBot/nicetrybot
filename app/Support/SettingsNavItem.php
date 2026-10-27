<?php

namespace App\Support;

/**
 * One link in the settings menu, from the core or an add-on. Items are sorted by order; the core's are
 * spaced ten apart so an add-on can slot in between.
 */
final readonly class SettingsNavItem
{
    /**
     * @param  string  $label  Untranslated; it's translated as the menu is drawn.
     * @param  list<string>  $current  Route name patterns the item is current on; when empty, it's current on its own URL.
     */
    public function __construct(
        public string $label,
        public string $route,
        public int $order,
        public array $current = [],
    ) {
        //
    }

    /**
     * Whether the item is current on this request, or null to leave it to the link's URL.
     */
    public function isCurrent(): ?bool
    {
        return $this->current === [] ? null : request()->routeIs(...$this->current);
    }
}
