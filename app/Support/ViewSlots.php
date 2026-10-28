<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use App\Enums\ViewSlot;
use Illuminate\Container\Attributes\Singleton;
use Illuminate\Support\HtmlString;

/**
 * The views add-ons put in the core's view slots (App\Enums\ViewSlot), like a script at the end of the
 * page or a button beside the cog. A slot with nothing in it draws nothing, so the core's pages are
 * whole without any add-on.
 *
 * The core's markup also carries hints for an add-on that redacts the page for screen sharing. They
 * do nothing on their own: data-privacy-skip marks what to leave as it is, data-privacy-hide what to
 * take off the page, data-privacy-mask="…" text to show as that instead (an IP's last two octets,
 * drawn one by one where no whole address is there to match), and a page that changes what it shows while redacted reads the privacy-mode
 * class on <html> and listens for the privacy-mode-changed window event.
 */
#[Singleton]
class ViewSlots
{
    /**
     * Each slot's views, with the slug of the add-on that put each there (or null for the core).
     *
     * @var array<string, list<array{view-string, string|null}>>
     */
    private array $views = [];

    /**
     * Put views in a slot, after any already there.
     *
     * @param  view-string  ...$views
     */
    public function add(ViewSlot $slot, string ...$views): void
    {
        foreach ($views as $view) {
            $this->views[$slot->value][] = [$view, null];
        }
    }

    /**
     * Put an add-on's views in a slot, after any already there, drawn only while it's on.
     *
     * @param  view-string  ...$views
     */
    public function addFromAddon(string $addon, ViewSlot $slot, string ...$views): void
    {
        foreach ($views as $view) {
            $this->views[$slot->value][] = [$view, $addon];
        }
    }

    /**
     * @return list<view-string>
     */
    public function views(ViewSlot $slot): array
    {
        $switchboard = app(AddonSwitchboard::class);

        return array_column(array_filter($this->views[$slot->value] ?? [], fn (array $entry): bool => $switchboard->isOn($entry[1])), 0);
    }

    /**
     * Draw a slot's views, in the order they were added, each with the slot's data.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(ViewSlot $slot, array $data = []): HtmlString
    {
        return new HtmlString(implode("\n", array_map(
            fn (string $view): string => view($view, $data)->render(),
            $this->views($slot),
        )));
    }
}
