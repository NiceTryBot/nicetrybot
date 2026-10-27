<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use App\Models\EdgeRequest;
use App\Models\Team;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\HtmlString;

/**
 * The request add-ons packages have tagged in (App\Support\RequestAddon). Without any, Activity's
 * requests and their details are exactly the core's.
 */
class RequestAddons
{
    /**
     * The container tag add-ons register under.
     */
    public const string TAG = 'activity.request-addons';

    public function __construct(private Container $container)
    {
        //
    }

    /**
     * Draw every add-on's marks for a page of requests, keyed like the ids given.
     *
     * @param  array<int, int>  $requestIds
     * @return array<int, HtmlString>
     */
    public function marks(Team $team, array $requestIds): array
    {
        $marks = array_map(fn () => '', $requestIds);

        foreach ($requestIds === [] ? [] : $this->all() as $addon) {
            foreach ($addon->marks($team, $requestIds) as $key => $html) {
                if (array_key_exists($key, $marks)) {
                    $marks[$key] .= $html?->toHtml() ?? '';
                }
            }
        }

        return array_map(fn (string $html) => new HtmlString($html), $marks);
    }

    /**
     * Draw every add-on's part of one request's details.
     */
    public function details(Team $team, EdgeRequest $request): HtmlString
    {
        return new HtmlString(implode('', array_map(fn (RequestAddon $addon) => $addon->details($team, $request)?->toHtml() ?? '', $this->all())));
    }

    /**
     * Draw every add-on's buttons for one request.
     */
    public function actions(Team $team, EdgeRequest $request): HtmlString
    {
        return new HtmlString(implode('', array_map(fn (RequestAddon $addon) => $addon->actions($team, $request)?->toHtml() ?? '', $this->all())));
    }

    /**
     * @return list<RequestAddon>
     */
    private function all(): array
    {
        return array_values(array_filter(
            app(AddonSwitchboard::class)->active($this->container->tagged(self::TAG)),
            fn (mixed $addon) => $addon instanceof RequestAddon,
        ));
    }
}
