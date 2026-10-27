<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use App\Models\Team;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\HtmlString;

/**
 * The client-cell add-ons packages have tagged in. Without any, client cells are exactly the core's.
 */
class ClientAddons
{
    /**
     * The container tag add-ons register under.
     */
    public const string TAG = 'activity.client-addons';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Draw every add-on's part of each client cell for a page of clients, keyed like the clients given.
     *
     * @param  array<int, array{ip: string|null, ja4: int|null}>  $clients
     * @return array<int, HtmlString>
     */
    public function render(Team $team, array $clients): array
    {
        $cells = array_map(fn () => '', $clients);

        foreach (app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)) as $addon) {
            if (! $addon instanceof ClientAddon || $clients === []) {
                continue;
            }

            foreach ($addon->render($team, $clients) as $key => $html) {
                if (array_key_exists($key, $cells)) {
                    $cells[$key] .= $html?->toHtml() ?? '';
                }
            }
        }

        return array_map(fn (string $html) => new HtmlString($html), $cells);
    }
}
