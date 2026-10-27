<?php

namespace App\Support;

use Closure;

/**
 * One link in the cog menu, from the core or an add-on. Items are sorted by order; the core's are
 * spaced ten apart so an add-on can slot in between.
 */
final readonly class WorkspaceMenuItem
{
    /**
     * @param  string  $label  Untranslated; it's translated as the menu is drawn.
     * @param  (Closure(): ?string)|null  $badge  Text for a badge, worked out as the menu is drawn; null for none.
     * @param  string|null  $badgeColor  A Flux badge color; null for the default.
     * @param  bool  $alert  While the item has a badge, put a dot on the cog too, so it's in sight with the menu shut (like an emergency switch that's on).
     */
    public function __construct(
        public string $label,
        public string $icon,
        public string $route,
        public int $order,
        public ?Closure $badge = null,
        public ?string $badgeColor = null,
        public bool $alert = false,
        public ?string $test = null,
    ) {
        //
    }
}
