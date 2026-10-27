<?php

namespace App\Mcp\Tools\Traffic;

use App\Actions\Analytics\CountTrafficSearch;
use App\Concerns\DescribesTrafficToClaude;
use App\Enums\ChartInterval;
use App\Enums\TrafficPeriod;
use App\Mcp\Tools\TeamTool;
use App\Models\Domain;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Learn how to search this team's traffic before calling query-traffic. Returns the search language's
    grammar (a WHERE expression in NiceTryBot's rule language, not SQL), every field it can test and the
    values they take, the team's hostnames, the periods and group-bys query-traffic accepts, and worked
    examples that turn questions into searches. Pass the question to put the hostnames it names first.
    Then call query-traffic with a where, a period and, for a breakdown or a chart, a group_by.
    TEXT)]
#[IsReadOnly]
class DescribeTrafficFields extends TeamTool
{
    use DescribesTrafficToClaude;

    /**
     * Questions about traffic, written as query-traffic calls.
     *
     * @var list<array{question: string, where: string, period: string, group_by: ?string, note: string}>
     */
    public const array EXAMPLES = [
        [
            'question' => 'How many spoofed clients were looking for a .env file today?',
            'where' => "client = 'spoofed' AND category = 'probe/creds'",
            'period' => '24h',
            'group_by' => null,
            'note' => "probe/creds is every credentials probe (.env, wp-config.php, .git/config...). For .env alone, use path LIKE '%.env%' instead. Clients are counted as distinct IPs (totals.unique_ips).",
        ],
        [
            'question' => 'How many requests did the shop domains get this week?',
            'where' => "host LIKE '%shop%'",
            'period' => '7d',
            'group_by' => 'host',
            'note' => 'LIKE ignores case. Group by host to see each site of the group.',
        ],
        [
            'question' => 'Where are the blocked requests coming from?',
            'where' => "action = 'blocked'",
            'period' => '24h',
            'group_by' => 'country',
            'note' => 'Countries are two-letter ISO codes. Group by network or asn for who is sending them.',
        ],
        [
            'question' => 'Chart 404s from cloud and datacenter networks over the last two weeks.',
            'where' => "status = 404 AND network.kind = 'hosting'",
            'period' => '14d',
            'group_by' => 'time',
            'note' => 'Set chart to true for a PNG. The interval defaults to one that suits the period.',
        ],
        [
            'question' => 'Which user agents are hitting the WordPress login?',
            'where' => "path LIKE '%/wp-login.php%'",
            'period' => '7d',
            'group_by' => 'ua',
            'note' => 'Categories group paths by what a probe is after, so match one product with LIKE on path.',
        ],
        [
            'question' => 'Which IPs made more than 100 requests in the last hour?',
            'where' => 'GROUP BY ip HAVING COUNT(*) > 100',
            'period' => '1h',
            'group_by' => 'ip',
            'note' => 'HAVING keeps the requests of the clients it picks, counted over the period. GROUP BY in the where only says what one client is (ip or ja4); the group_by input says how to break the counts down.',
        ],
    ];

    /**
     * The search language's grammar.
     */
    public const string GRAMMAR = <<<'GRAMMAR'
        expr      := term {OR term}
        term      := factor {AND factor}
        factor    := NOT factor | ( expr ) | field predicate | 'text'
        predicate := op literal | [NOT] LIKE 'pattern' | [NOT] IN (literal, ...) | IS [NOT] NULL
        op        := = | != | < | <= | > | >=
        search    := [expr] [GROUP BY subject {, subject}] [HAVING having]
        subject   := ip | ja4
        having    := hterm {OR hterm}          hterm := hfactor {AND hfactor}
        hfactor   := NOT hfactor | ( having ) | COUNT ( * | DISTINCT field ) [FILTER ( WHERE expr )] op number

        Keywords ignore case. Strings take single quotes, doubling a quote inside ('O''Brien'). Numbers are
        bare. < and > work only on number fields. LIKE ignores case: % matches any run of characters and _
        one character. AND binds tighter than OR, so wrap OR groups in parentheses when you AND them with
        something else. A quoted string on its own ('digitalocean') is a text term: it matches that text in
        the path, the start of the IP, the edge tag, the user agent, the network name, or a name the team
        gave a client. Use a field when you know which one.

        A client is an IP and a TLS fingerprint together; GROUP BY ip or GROUP BY ja4 makes it one or the
        other. HAVING keeps the requests of the clients whose requests pass it: "more than 50 requests" is
        COUNT(*) > 50, "on more than 3 sites" is COUNT(DISTINCT host) > 3, and "reached /thank-you but never
        the homepage" is COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1 AND COUNT(*) FILTER (WHERE
        path = '/') = 0.
        GRAMMAR;

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'question' => ['nullable', 'string', 'max:2000'],
        ]);

        $domains = array_values($this->team()->domains()->orderBy('hostname')->get(['hostname'])->map(fn (Domain $domain): string => $domain->hostname)->all());
        $hostnames = $this->hostnames((string) ($validated['question'] ?? ''), $domains);

        return Response::structured([
            'grammar' => self::GRAMMAR,
            'fields' => rtrim($this->fieldReference()."\n".$this->fieldNotes()),
            'hostnames' => $hostnames['hostnames'],
            'hostname_total' => $hostnames['total'],
            'hostnames_per_parent_domain' => (object) $hostnames['groups'],
            'periods' => array_map(fn (TrafficPeriod $period) => [
                'value' => $period->value,
                'label' => $period->label(),
                'intervals' => array_map(fn (ChartInterval $interval) => $interval->value, ChartInterval::for($period)),
                'default_interval' => ChartInterval::default($period)->value,
            ], TrafficPeriod::cases()),
            'group_by' => [...CountTrafficSearch::GROUPABLE, 'time'],
            'examples' => self::EXAMPLES,
            'next' => 'Call query-traffic with the where, a period and, for a breakdown, a group_by. Its activity_url opens the same search on the Activity page for the person.',
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
            'question' => $schema->string()->description('Optional: the question being asked, in the person\'s words. Hostnames it names are listed first when the team has more than fit.'),
        ];
    }
}
