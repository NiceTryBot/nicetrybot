<?php

namespace App\Mcp\Tools\Traffic;

use App\Actions\Ai\TranslateSearch;
use App\Data\RequestQuery;
use App\Enums\AiProvider;
use App\Mcp\Tools\TeamTool;
use App\Models\Domain;
use App\Support\AnthropicException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Turn a person's plain-English question about the team's traffic ("wordpress probes over the last
    36 hours") into a where for query-traffic and list-requests, and the period it names, as the
    Activity page's Ask box does. Claude writes it with the token's user's own Anthropic key (Settings →
    AI), so it fails without one. An agent that can write the rule language should write the where
    itself instead: describe-traffic-fields has the grammar.
    TEXT)]
#[IsReadOnly]
class TranslateQuestion extends TeamTool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, TranslateSearch $translateSearch): Response|ResponseFactory
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:1000'],
        ]);

        $team = $this->team();
        $credential = $this->user()->aiCredential(AiProvider::Anthropic);

        if ($credential === null) {
            return Response::error('Asking in plain English uses your own Anthropic key, and you haven\'t connected one: add it at '.route('ai.edit').'. Or write the where yourself (describe-traffic-fields has the grammar).');
        }

        try {
            $translated = $translateSearch->handle($credential, $validated['question'], domains: array_values($team->domains->map(fn (Domain $domain) => $domain->hostname)->all()));
        } catch (AnthropicException $exception) {
            return Response::error($exception->getMessage());
        }

        if ($translated->search === '') {
            return Response::error($translated->note ?? 'Claude couldn\'t make a search of that. Try naming what to match, like a status, a path or a network.');
        }

        // The page keeps the client filter apart; a where says it with the client field.
        $search = $translated->client === ''
            ? new RequestQuery($translated->search)
            : (new RequestQuery($translated->search))->with('client = '.RequestQuery::quote($translated->client));

        return Response::structured([
            'question' => $validated['question'],
            'where' => $search->source,
            'reads_as' => $search->describe(),
            'period' => $translated->period?->value,
            'note' => $translated->note,
        ]);
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'question' => $schema->string()->required()->description('The question, as the person asked it, like "404s from hosting networks this week".'),
        ];
    }
}
