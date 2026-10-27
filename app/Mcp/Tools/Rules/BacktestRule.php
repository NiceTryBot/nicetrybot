<?php

namespace App\Mcp\Tools\Rules;

use App\Actions\Rules\BacktestRule as RunBacktest;
use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Enums\ApiAbility;
use App\Mcp\Tools\Rules\Concerns\WorksWithRules;
use App\Mcp\Tools\TeamTool;
use App\Models\EdgeRule;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Dry-run a rule script against the team's logged traffic without saving or changing anything: how many
    clients (IPs and/or TLS fingerprints, the rule's subjects) and requests it would have matched over the
    last `days` days, how many of those clients came from outside hosting networks (likely real people, so
    worth a second look), and the busiest ones with their network, country and domains. Pass `source` for
    a draft, or `id` to backtest one of the team's saved rules. Run it before create-rule or update-rule so
    you can tell the person what the rule would catch.
    TEXT)]
#[IsReadOnly]
class BacktestRule extends TeamTool
{
    use WorksWithRules;

    protected ApiAbility $ability = ApiAbility::Read;

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'source' => $schema->string()->description('The rule script to try, e.g. "TAG ip, ja4 AS \'dutch\' FOR 1 DAY WHERE country = \'NL\'". Give this or id.'),
            'id' => $schema->integer()->description('A saved rule to try instead, by id from list-rules.'),
            'days' => $schema->integer()->min(1)->max(30)->default(7)->description('How many days of traffic to replay, 1 to 30. Defaults to 7.'),
            'limit' => $schema->integer()->min(1)->max(50)->default(12)->description('How many of the busiest matched clients to list, 1 to 50. Defaults to 12.'),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request, RunBacktest $backtest): Response|ResponseFactory
    {
        $request->validate([
            'source' => ['required_without:id', 'prohibits:id', 'nullable', 'string', 'max:5000'],
            'id' => ['nullable', 'integer'],
            'days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ], [
            'source.required_without' => 'Give a rule script as source, or a saved rule\'s id.',
            'source.prohibits' => 'Give a rule script as source or a saved rule\'s id, not both.',
        ]);

        Gate::forUser($this->user())->authorize('viewAny', [EdgeRule::class, $this->team()]);

        $source = $request->get('source');

        if ($source === null) {
            $rule = $this->findRule((int) $request->get('id'));

            if ($rule === null) {
                return $this->ruleNotFound((int) $request->get('id'));
            }

            $source = $rule->source;
        }

        try {
            $script = RuleScript::parse($source);
        } catch (RuleScriptSyntaxError $error) {
            return $this->syntaxError($error);
        }

        $result = $backtest->handle($this->team(), $script, (int) ($request->get('days') ?? 7), (int) ($request->get('limit') ?? 12));

        return Response::structured([
            'days' => $result->days,
            'clients_matched' => $result->subjectCount,
            'requests_matched' => $result->requestCount,
            'clients_outside_hosting' => $result->outsideHostingCount,
            'busiest_clients' => $result->subjects,
        ]);
    }
}
