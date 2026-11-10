<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use App\Enums\NavGroup;
use Illuminate\Container\Attributes\Singleton;

/**
 * The sidebar's links: the core adds its pages, and each add-on its own, so a page an add-on brings
 * shows only while the add-on is installed and on.
 */
#[Singleton]
class Nav
{
    /**
     * Each link with the slug of the add-on that brought it, or null for the core's.
     *
     * @var list<array{NavItem, string|null}>
     */
    private array $items = [];

    public function add(NavItem ...$items): void
    {
        foreach ($items as $item) {
            $this->items[] = [$item, null];
        }
    }

    /**
     * Add an add-on's links, shown only while it's on.
     */
    public function addFromAddon(string $addon, NavItem ...$items): void
    {
        foreach ($items as $item) {
            $this->items[] = [$item, $addon];
        }
    }

    /**
     * @return list<NavItem>
     */
    public function items(): array
    {
        $switchboard = app(AddonSwitchboard::class);
        $items = array_column(array_filter($this->items, fn (array $entry): bool => $switchboard->isOn($entry[1])), 0);
        usort($items, fn (NavItem $a, NavItem $b) => $a->order <=> $b->order);

        return $items;
    }

    /**
     * The links as the sidebar draws them: the ungrouped ones, then each group with a link to show.
     *
     * @return list<array{group: NavGroup|null, items: list<NavItem>}>
     */
    public function groups(): array
    {
        $items = $this->items();
        $groups = [];

        foreach ([null, ...NavGroup::cases()] as $group) {
            $inGroup = array_values(array_filter($items, fn (NavItem $item): bool => $item->group === $group));

            if ($inGroup !== []) {
                $groups[] = ['group' => $group, 'items' => $inGroup];
            }
        }

        return $groups;
    }
}
