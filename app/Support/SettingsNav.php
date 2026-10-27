<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Container\Attributes\Singleton;

/**
 * The settings menu's links: the core adds its settings, and each add-on its own, so a settings page an
 * add-on brings shows only while the add-on is installed and on.
 */
#[Singleton]
class SettingsNav
{
    /**
     * Each link with the slug of the add-on that brought it, or null for the core's.
     *
     * @var list<array{SettingsNavItem, string|null}>
     */
    private array $items = [];

    public function add(SettingsNavItem ...$items): void
    {
        foreach ($items as $item) {
            $this->items[] = [$item, null];
        }
    }

    /**
     * Add an add-on's links, shown only while it's on.
     */
    public function addFromAddon(string $addon, SettingsNavItem ...$items): void
    {
        foreach ($items as $item) {
            $this->items[] = [$item, $addon];
        }
    }

    /**
     * @return list<SettingsNavItem>
     */
    public function items(): array
    {
        $switchboard = app(AddonSwitchboard::class);
        $items = array_column(array_filter($this->items, fn (array $entry): bool => $switchboard->isOn($entry[1])), 0);
        usort($items, fn (SettingsNavItem $a, SettingsNavItem $b) => $a->order <=> $b->order);

        return $items;
    }
}
