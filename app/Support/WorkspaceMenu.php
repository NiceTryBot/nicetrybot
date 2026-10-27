<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Container\Attributes\Singleton;

/**
 * The cog menu's links, at the top right: the core adds its own (the team's sites and visitor data),
 * and each add-on its own, so a page an add-on brings there shows only while the add-on is installed and on.
 */
#[Singleton]
class WorkspaceMenu
{
    /**
     * Each link with the slug of the add-on that brought it, or null for the core's.
     *
     * @var list<array{WorkspaceMenuItem, string|null}>
     */
    private array $items = [];

    public function add(WorkspaceMenuItem ...$items): void
    {
        foreach ($items as $item) {
            $this->items[] = [$item, null];
        }
    }

    /**
     * Add an add-on's links, shown only while it's on.
     */
    public function addFromAddon(string $addon, WorkspaceMenuItem ...$items): void
    {
        foreach ($items as $item) {
            $this->items[] = [$item, $addon];
        }
    }

    /**
     * @return list<WorkspaceMenuItem>
     */
    public function items(): array
    {
        $switchboard = app(AddonSwitchboard::class);
        $items = array_column(array_filter($this->items, fn (array $entry): bool => $switchboard->isOn($entry[1])), 0);
        usort($items, fn (WorkspaceMenuItem $a, WorkspaceMenuItem $b) => $a->order <=> $b->order);

        return $items;
    }
}
