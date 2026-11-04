<?php

namespace App\Mcp\Tools\Traffic;

use App\Data\RequestQuery;
use App\Enums\ActivityNoise;
use App\Enums\RuleField;
use App\Enums\TrafficPeriod;
use App\Mcp\Tools\TeamTool;
use App\Models\SavedSearch;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List the searches the team saved on the Activity page, by name: each one's where (its client filter
    written in, as client = 'spoofed'), period and chart interval, ready for query-traffic and
    list-requests, the question it was asked as, and its Activity link. Use it when the person names a
    saved search, like "the speed audit test".
    TEXT)]
#[IsReadOnly]
class ListSavedSearches extends TeamTool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        return Response::structured([
            'saved_searches' => $this->team()->savedSearches()->with('user:id,name')->orderBy('name')->get()
                ->map(fn (SavedSearch $savedSearch) => $this->searchData($savedSearch))
                ->values()
                ->all(),
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    /**
     * A saved search as the traffic tools take it.
     *
     * @return array{id: int, name: string, where: string, period: string, interval: string|null, question: string|null, hides_own_traffic: bool, saved_by: string|null, activity_url: string}
     */
    private function searchData(SavedSearch $savedSearch): array
    {
        $parameters = $savedSearch->parameters;
        $search = new RequestQuery((string) ($parameters['q'] ?? ''));
        $client = (string) ($parameters['client'] ?? '');

        if (in_array($client, RuleField::CLIENT_VALUES, true)) {
            $search = $search->with('client = '.RequestQuery::quote($client));
        }

        return [
            'id' => $savedSearch->id,
            'name' => $savedSearch->name,
            'where' => $search->source,
            'period' => TrafficPeriod::tryFrom((string) ($parameters['period'] ?? ''))->value ?? TrafficPeriod::Day->value,
            'interval' => filled($parameters['by'] ?? null) ? (string) $parameters['by'] : null,
            'question' => filled($parameters['ask'] ?? null) ? (string) $parameters['ask'] : null,
            'hides_own_traffic' => ($parameters['hide'] ?? '') === ActivityNoise::Mine->value,
            'saved_by' => $savedSearch->user?->name,
            'activity_url' => route('activity.index', ['current_team' => $this->team()->slug, ...$parameters]),
        ];
    }
}
