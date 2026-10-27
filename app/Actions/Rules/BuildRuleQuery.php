<?php

namespace App\Actions\Rules;

use App\Data\BrowserReleases;
use App\Data\LabelDirectory;
use App\Data\RuleScript;
use App\Enums\ClientTrait;
use App\Enums\InspectingProxy;
use App\Enums\LabelKind;
use App\Enums\RuleField;
use App\Enums\RuleSubject;
use App\Models\EdgeRequest;
use App\Models\Team;
use App\Support\IpRange;
use App\Support\RuleFieldDefinition;
use App\Support\WhereCondition;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Compile a rule script into a query over a team's edge requests. Every value goes through bindings;
 * the script never becomes raw SQL.
 */
class BuildRuleQuery
{
    /**
     * Start a query over the team's requests with every lookup the script reads joined in, and the
     * script's WHERE applied. Its HAVING (and its FILTERs) can be added to the query after.
     */
    public function handle(Team $team, RuleScript $script): Builder
    {
        return $this->matching($team, $script->where, $script->having);
    }

    /**
     * Start a query over the team's requests that pass a WHERE expression (all of them when null), as
     * a rule's WHERE or the Activity search writes it. A HAVING the query will count with is given so
     * the fields it reads are joined too.
     *
     * @param  array<string, mixed>|null  $where
     * @param  array<string, mixed>|null  $having
     */
    public function matching(Team $team, ?array $where, ?array $having = null): Builder
    {
        $query = DB::table('edge_requests')
            ->join('domains', 'domains.id', '=', 'edge_requests.domain_id')
            ->where('domains.team_id', $team->id);

        return $this->constrain($query, $team, $where, $having);
    }

    /**
     * Join the lookups a field can read into a query over the team's requests that already joins their
     * domains, and apply a WHERE expression (nothing when null). The core's lookups are always joined;
     * a field that reads another table (an add-on's) is joined only when WHERE or the given HAVING
     * reads it. Searching in place, rather than by the ids of a separate query, lets the database stop
     * at the requests the rest of the query wants.
     *
     * @param  array<string, mixed>|null  $where
     * @param  array<string, mixed>|null  $having
     */
    public function constrain(Builder $query, Team $team, ?array $where, ?array $having = null): Builder
    {
        $query->leftJoin('request_paths', 'request_paths.id', '=', 'edge_requests.path_id')
            ->leftJoin('user_agents', 'user_agents.id', '=', 'edge_requests.user_agent_id')
            ->leftJoin('tls_fingerprints as ja4_fingerprints', 'ja4_fingerprints.id', '=', 'edge_requests.ja4_id')
            ->leftJoin('tls_fingerprints as ja3_fingerprints', 'ja3_fingerprints.id', '=', 'edge_requests.ja3_id')
            ->leftJoin('tls_fingerprints as ja4h_fingerprints', 'ja4h_fingerprints.id', '=', 'edge_requests.ja4h_id')
            ->leftJoin('client_hints', 'client_hints.id', '=', 'edge_requests.client_hints_id')
            ->leftJoin('networks', 'networks.asn', '=', 'edge_requests.asn')
            ->leftJoin('locations', 'locations.id', '=', 'edge_requests.location_id')
            ->leftJoin('bot_tags', 'bot_tags.id', '=', 'edge_requests.bot_tag_id');

        $this->joinFields($query, RuleScript::fieldsIn($where, $having));

        if ($where !== null) {
            $query->where(fn (Builder $query) => $this->apply($query, $where, teamId: $team->id));
        }

        return $query;
    }

    /**
     * Start a query over the team's clients whose requests pass WHERE, one row per client as the subjects
     * group them (an IP, a TLS fingerprint, or the two together), keeping those where HAVING holds. A
     * fingerprint on its own leaves out the requests that had none.
     *
     * @param  array<string, mixed>|null  $where
     * @param  list<RuleSubject>  $subjects
     * @param  array<string, mixed>|null  $having
     */
    public function clients(Team $team, ?array $where, array $subjects, ?array $having): Builder
    {
        $query = $this->matching($team, $where, $having)
            ->groupBy(array_map(fn (RuleSubject $subject) => $subject->column(), $subjects))
            ->when($subjects === [RuleSubject::Ja4], fn (Builder $query) => $query->whereNotNull('edge_requests.ja4_id'));

        if ($having !== null) {
            [$sql, $bindings] = $this->having($having, $team->id);
            $query->havingRaw($sql, $bindings);
        }

        return $query;
    }

    /**
     * Get the SQL expression naming a request's client as the subjects group it, the same text for every
     * request of one client, so requests can be matched to a list of clients.
     *
     * @param  list<RuleSubject>  $subjects
     */
    public static function clientKey(array $subjects): string
    {
        return implode(" || '|' || ", array_map(fn (RuleSubject $subject) => "coalesce(cast({$subject->column()} as text), '')", $subjects));
    }

    /**
     * Compile a HAVING expression into SQL with its bindings. A count's FILTER takes a team for the text
     * terms a search may write in it.
     *
     * @param  array<string, mixed>  $node
     * @return array{0: string, 1: list<mixed>}
     */
    public function having(array $node, ?int $teamId = null): array
    {
        if ($node['kind'] === 'aggregate') {
            $aggregate = $node['distinct'] === null ? 'count(*)' : 'count(distinct '.$node['distinct']->column().')';
            $bindings = [];

            if (($node['filter'] ?? null) !== null) {
                [$condition, $bindings] = $this->condition($node['filter'], $teamId);
                $aggregate .= " filter (where {$condition})";
            }

            return ["{$aggregate} {$node['operator']} ?", [...$bindings, $node['value']]];
        }

        if ($node['kind'] === 'not') {
            [$sql, $bindings] = $this->having($node['expr'], $teamId);

            return ["not ({$sql})", $bindings];
        }

        [$left, $leftBindings] = $this->having($node['left'], $teamId);
        [$right, $rightBindings] = $this->having($node['right'], $teamId);

        return ["({$left} {$node['kind']} {$right})", [...$leftBindings, ...$rightBindings]];
    }

    /**
     * Compile each FILTER condition in a HAVING expression, by its number, so a request can be checked
     * against them one at a time (as a backtest replays a window).
     *
     * @param  array<string, mixed>  $node
     * @return array<int, array{0: string, 1: list<mixed>}>
     */
    public function filters(array $node, ?int $teamId = null): array
    {
        return match ($node['kind']) {
            'aggregate' => ($node['filter'] ?? null) === null ? [] : [$node['filter_index'] => $this->condition($node['filter'], $teamId)],
            'not' => $this->filters($node['expr'], $teamId),
            default => $this->filters($node['left'], $teamId) + $this->filters($node['right'], $teamId),
        };
    }

    /**
     * Compile a WHERE expression into a SQL condition with its bindings, for where a query can't take a
     * closure, like a count's FILTER.
     *
     * @param  array<string, mixed>  $node
     * @return array{0: string, 1: list<mixed>}
     */
    public function condition(array $node, ?int $teamId = null): array
    {
        $query = DB::table('edge_requests');
        $this->apply($query, $node, teamId: $teamId);

        return [Str::after($query->getGrammar()->compileWheres($query), 'where '), array_values($query->getBindings())];
    }

    /**
     * Join what the fields read beyond the core's lookups, each table once however many fields read it.
     *
     * @param  list<RuleFieldDefinition>  $fields
     */
    private function joinFields(Builder $query, array $fields): void
    {
        $joins = [];

        foreach ($fields as $field) {
            $joins += $field->joins();
        }

        foreach ($joins as $join) {
            $join($query);
        }
    }

    /**
     * Add a WHERE expression node to the query.
     *
     * @param  array<string, mixed>  $node
     */
    private function apply(Builder $query, array $node, string $boolean = 'and', ?int $teamId = null): void
    {
        match ($node['kind']) {
            'and' => $query->where(function (Builder $query) use ($node, $teamId) {
                $this->apply($query, $node['left'], teamId: $teamId);
                $this->apply($query, $node['right'], teamId: $teamId);
            }, boolean: $boolean),
            'or' => $query->where(function (Builder $query) use ($node, $teamId) {
                $this->apply($query, $node['left'], teamId: $teamId);
                $this->apply($query, $node['right'], 'or', $teamId);
            }, boolean: $boolean),
            'not' => $this->not($query, $node['expr'], $boolean, $teamId),
            'compare' => $this->compare($query, $node['field'], $node['operator'], $node['value'], $boolean),
            'like' => $this->like($query, $node['field'], $node['pattern'], $node['negated'], $boolean),
            'in' => $this->in($query, $node['field'], $node['values'], $node['negated'], $boolean),
            'null' => $query->whereRaw($node['field']->column().($node['negated'] ? ' is not null' : ' is null'), boolean: $boolean),
            'verdict' => $this->verdict($query, $node['rules'], $boolean, $teamId),
            'cidr' => IpRange::constrain($query, 'edge_requests.client_ip', $node['range'], $boolean),
            'text' => $this->text($query, $node['text'], $teamId, $boolean),
        };
    }

    /**
     * Match requests the expression doesn't hold for. A condition on a value the request doesn't have
     * (a page with no path category, say) is unknown in SQL, and NOT unknown is unknown too, so a
     * plain NOT would drop those requests. Unknown counts as false here, as it does on the edge and
     * in backtests: `NOT category = 'probe'` keeps every request that isn't a probe.
     *
     * @param  array<string, mixed>  $expr
     */
    private function not(Builder $query, array $expr, string $boolean, ?int $teamId): void
    {
        $inner = $query->forNestedWhere();
        $this->apply($inner, $expr, teamId: $teamId);

        $sql = substr($inner->getGrammar()->compileWheres($inner), strlen('where '));

        $query->whereRaw('not coalesce(('.$sql.'), false)', $inner->getRawBindings()['where'], $boolean);
    }

    /**
     * Match a search's free text anywhere people look: the path, the start of the IP, the edge tag,
     * the user agent, the network's name, or the name the team gave a client. Searches write it; rule
     * scripts can't.
     */
    private function text(Builder $query, string $text, ?int $teamId, string $boolean): void
    {
        $contains = '%'.mb_strtolower($text).'%';

        $query->where(function (Builder $query) use ($text, $teamId, $contains) {
            $query->whereIn('edge_requests.path_id', DB::table('request_paths')->whereRaw('lower(path) like ?', [$contains])->select('id'))
                ->orWhereRaw('lower(cast(edge_requests.client_ip as text)) like ?', [mb_strtolower($text).'%'])
                ->orWhereIn('edge_requests.bot_tag_id', DB::table('bot_tags')->whereRaw('lower(name) like ?', [$contains])->select('id'))
                ->orWhereIn('edge_requests.user_agent_id', DB::table('user_agents')->whereRaw('lower(value) like ?', [$contains])->select('id'))
                ->orWhereIn('edge_requests.asn', DB::table('networks')->whereRaw('lower(name) like ?', [$contains])->select('asn'));

            foreach ($teamId === null ? [] : app(LabelDirectory::class)->search($teamId, $text) as $label) {
                match ($label->kind) {
                    LabelKind::Ip => $query->orWhere('edge_requests.client_ip', $label->value),
                    LabelKind::Ja4 => $query->orWhere('ja4_fingerprints.value', $label->value),
                    LabelKind::UserAgent => $query->orWhereIn('edge_requests.user_agent_id', DB::table('user_agents')->where('hash', md5($label->value))->select('id')),
                    LabelKind::ClientHints => $query->orWhereIn('edge_requests.client_hints_id', DB::table('client_hints')->where('hash', md5($label->value))->select('id')),
                };
            }
        }, boolean: $boolean);
    }

    private function compare(Builder $query, RuleFieldDefinition $field, string $operator, int|string $value, string $boolean): void
    {
        $values = $field->storedValues($value);

        if ($field === RuleField::Client) {
            $this->client($query, $values, $operator === '!=', $boolean);

            return;
        }

        if ($field === RuleField::Category) {
            $operator === '='
                ? $query->whereIn(DB::raw($field->column()), $values, $boolean)
                : $query->where(fn (Builder $query) => $query->whereNotIn(DB::raw($field->column()), $values)->orWhereRaw($field->column().' is null'), boolean: $boolean);

            return;
        }

        $query->where(DB::raw($field->column()), $operator === '!=' ? '<>' : $operator, $values[0], $boolean);
    }

    private function like(Builder $query, RuleFieldDefinition $field, string $pattern, bool $negated, string $boolean): void
    {
        if ($field === RuleField::Category) {
            $values = $field->categoryValues($pattern, isPattern: true);

            $values === []
                ? $query->whereRaw($negated ? '1 = 1' : '1 = 0', boolean: $boolean)
                : $query->whereIn(DB::raw($field->column()), $values, $boolean, $negated);

            return;
        }

        $query->whereRaw($field->likeColumn().($negated ? ' not like ?' : ' like ?'), [strtolower($pattern)], $boolean);
    }

    /**
     * Match requests whose IP or TLS fingerprint holds an active verdict from one of the team's rules
     * (any rule when none are named). Gym routines use this node; rule scripts can't write it.
     *
     * Each shape of verdict (on an IP, a fingerprint, both, or neither) is its own set the request is
     * looked up in, so Postgres reads the verdicts once and hashes them. One EXISTS that compared each
     * request with `client_ip is null or client_ip = …` read them again for every request: a week's
     * backtest took 26 seconds.
     *
     * @param  list<int>  $ruleIds
     */
    private function verdict(Builder $query, array $ruleIds, string $boolean, ?int $teamId): void
    {
        $active = fn (): Builder => DB::table('verdicts')
            ->when($teamId !== null, fn (Builder $query) => $query->where('verdicts.team_id', $teamId), fn (Builder $query) => $query->whereColumn('verdicts.team_id', 'domains.team_id'))
            ->whereNull('verdicts.lifted_at')
            ->where(fn (Builder $query) => $query->whereNull('verdicts.expires_at')->orWhere('verdicts.expires_at', '>', now()))
            ->when($ruleIds !== [], fn (Builder $query) => $query->whereIn('verdicts.edge_rule_id', $ruleIds));

        $query->where(fn (Builder $query) => $query
            ->whereIn('edge_requests.client_ip', $active()->whereNotNull('verdicts.client_ip')->whereNull('verdicts.ja4_id')->select('verdicts.client_ip'))
            ->orWhereIn('edge_requests.ja4_id', $active()->whereNull('verdicts.client_ip')->whereNotNull('verdicts.ja4_id')->select('verdicts.ja4_id'))
            ->orWhereIn(DB::raw('(edge_requests.client_ip, edge_requests.ja4_id)'), $active()->whereNotNull('verdicts.client_ip')->whereNotNull('verdicts.ja4_id')->select('verdicts.client_ip', 'verdicts.ja4_id'))
            ->orWhereExists($active()->whereNull('verdicts.client_ip')->whereNull('verdicts.ja4_id')->selectRaw('1')), boolean: $boolean);
    }

    /**
     * @param  list<int|string>  $literals
     */
    private function in(Builder $query, RuleFieldDefinition $field, array $literals, bool $negated, string $boolean): void
    {
        $values = array_values(array_unique(array_merge(...array_map(fn (int|string $literal) => $field->storedValues($literal), $literals))));

        if ($field === RuleField::Client) {
            $this->client($query, $values, $negated, $boolean);

            return;
        }

        $query->whereIn(DB::raw($field->column()), $values, $boolean, $negated);
    }

    /**
     * Match requests from clients that are any of the given kinds: spoofed or outdated (as the Activity
     * page's client filter decides, against the profile side tables), or behind a corporate security
     * gateway. Negated, a request whose parts are unknown counts as not that kind.
     *
     * @param  list<int|string>  $kinds
     */
    private function client(Builder $query, array $kinds, bool $negated, string $boolean): void
    {
        $releases = BrowserReleases::now();
        $conditions = array_map(fn (int|string $kind) => match ($kind) {
            'corporate' => WhereCondition::of(EdgeRequest::query()->whereIn('edge_requests.asn', InspectingProxy::allAsns())),
            default => WhereCondition::of(ClientTrait::constrainAny(EdgeRequest::query(), ClientTrait::forFilter((string) $kind), $releases)),
        }, $kinds);

        $sql = 'coalesce(('.implode(' or ', array_map(fn (WhereCondition $condition) => $condition->sql, $conditions)).'), false)';

        $query->whereRaw($negated ? "not {$sql}" : $sql, array_merge(...array_map(fn (WhereCondition $condition) => $condition->bindings, $conditions)), $boolean);
    }
}
