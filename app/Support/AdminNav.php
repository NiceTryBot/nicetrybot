<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Container\Attributes\Singleton;

/**
 * The links an add-on brings to the Admin section's menu, after the core's own, shown only while the
 * add-on is installed and on.
 */
#[Singleton]
class AdminNav
{
    /**
     * Each link with the slug of the add-on that brought it.
     *
     * @var list<array{SettingsNavItem, string}>
     */
    private array $items = [];

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
