<?php

namespace App\Mcp\Tools\Traffic;

use App\Actions\Analytics\CountTrafficSearch;
use App\Data\RequestQuery;
use App\Data\RuleScriptSyntaxError;
use App\Enums\TrafficPeriod;
use App\Mcp\Tools\TeamTool;
use App\Models\EdgeRequest;
use App\Support\WholePeriodQuery;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\QueryException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List the team's requests that match a search over a recent period, newest first, a page at a time:
    when each came, from which IP and network, what it asked for, its status, and what the edge did.
    The where is NiceTryBot's rule language, as query-traffic takes it (describe-traffic-fields has the
    grammar). Use query-traffic for counts; this is for looking at the requests themselves.
    TEXT)]
#[IsReadOnly]
class ListRequests extends TeamTool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, CountTrafficSearch $countTraffic): Response|ResponseFactory
    {
        $validated = $request->validate([
            'where' => ['nullable', 'string', 'max:4000'],
            'period' => ['nullable', Rule::enum(TrafficPeriod::class)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $search = new RequestQuery(trim((string) ($validated['where'] ?? '')));
        $period = TrafficPeriod::from($validated['period'] ?? TrafficPeriod::Day->value);
        $limit = (int) ($validated['limit'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);

        try {
            $query = $countTraffic->requests($this->team(), $search, $period)
                ->select('edge_requests.*')
                ->with('domain:id,hostname')
                ->latest('edge_requests.occurred_at')
                ->latest('edge_requests.id')
                ->offset(($page - 1) * $limit)
                ->limit($limit + 1);

            $requests = WholePeriodQuery::run(fn () => $query->get(), timeoutSeconds: CountTrafficSearch::TIMEOUT_SECONDS);
        } catch (RuleScriptSyntaxError $error) {
            return Response::error("The where doesn't parse: {$error->getMessage()} (line {$error->scriptLine}, column {$error->scriptColumn}). Fix it and call list-requests again; describe-traffic-fields has the grammar and the fields.");
        } catch (QueryException $exception) {
            if (! WholePeriodQuery::timedOut($exception)) {
                throw $exception;
            }

            return Response::error("Finding that search's requests over the {$period->label()} took too long. Try a shorter period, an earlier page or a narrower where (a host, a path or a category).");
        }

        return Response::structured([
            'where' => $search->source,
            'period' => $period->value,
            'since' => $period->start()->toIso8601ZuluString(),
            'page' => $page,
            'limit' => $limit,
            'has_more' => $requests->count() > $limit,
            'requests' => $requests->take($limit)->map(fn (EdgeRequest $edgeRequest) => [
                'id' => $edgeRequest->id,
                'occurred_at' => $edgeRequest->occurred_at->toIso8601ZuluString(),
                'ip' => $edgeRequest->client_ip,
                'method' => $edgeRequest->method->label(),
                'host' => $edgeRequest->domain->hostname,
                'path' => $edgeRequest->path,
                'status' => $edgeRequest->status_code,
                'action' => $edgeRequest->action->slug(),
                'category' => $edgeRequest->requestPath?->category?->slug(),
                'country' => $edgeRequest->country_code,
                'network' => $edgeRequest->network?->name,
                'asn' => $edgeRequest->asn,
                'user_agent' => $edgeRequest->user_agent,
                'ja4' => $edgeRequest->ja4,
                'tag' => $edgeRequest->bot_tag,
                'duration_ms' => $edgeRequest->duration_ms,
            ])->values()->all(),
            'activity_url' => route('activity.index', array_filter([
                'current_team' => $this->team()->slug,
                'q' => $search->isBlank() ? null : $search->source,
                'period' => $period === TrafficPeriod::Day ? null : $period->value,
            ])),
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
            'where' => $schema->string()->description('The search, in NiceTryBot\'s rule language (see describe-traffic-fields), like category = \'probe/cms\' AND status = 404. Leave it out for every request.'),
            'period' => $schema->string()->enum(array_column(TrafficPeriod::cases(), 'value'))->description('How far back to look, from now. Default 24h.'),
            'limit' => $schema->integer()->min(1)->max(100)->description('How many requests a page holds. Default 10, at most 100.'),
            'page' => $schema->integer()->min(1)->description('Which page, newest first. Default 1; has_more says whether there\'s another.'),
        ];
    }
}
