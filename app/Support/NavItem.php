<?php

namespace App\Support;

use App\Enums\NavGroup;
use Closure;

/**
 * One link in the sidebar, from the core or an add-on. Items are sorted by order; the core's are
 * spaced ten apart so an add-on can slot in between.
 */
final readonly class NavItem
{
    /**
     * @param  string  $label  Untranslated; it's translated as the sidebar is drawn.
     * @param  list<string>  $current  Route name patterns the item is current on; its own route when empty.
     * @param  bool  $ai  Mark the page as having AI Ask.
     * @param  (Closure(): ?string)|null  $badge  Text for a badge, worked out as the sidebar is drawn; null for none.
     * @param  NavGroup|null  $group  The titled group it's under; null for the workspace's own links.
     */
    public function __construct(
        public string $label,
        public string $icon,
        public string $route,
        public int $order,
        public array $current = [],
        public bool $ai = false,
        public ?Closure $badge = null,
        public string $badgeColor = 'zinc',
        public ?string $test = null,
        public ?NavGroup $group = null,
    ) {
        //
    }

    /**
     * @return list<string>
     */
    public function currentPatterns(): array
    {
        return $this->current === [] ? [$this->route] : $this->current;
    }
}
