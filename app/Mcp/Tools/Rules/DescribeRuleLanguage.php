<?php

namespace App\Mcp\Tools\Rules;

use App\Concerns\DescribesTrafficToClaude;
use App\Mcp\Tools\TeamTool;
use App\Support\RuleTemplates;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    Get the rule language: its grammar, the fields a condition can test with the values they take, and
    example rules. Call it before writing a script for create-rule, update-rule or backtest-rule. It is
    not SQL, so write only what the grammar allows.
    TEXT)]
#[IsReadOnly]
class DescribeRuleLanguage extends TeamTool
{
    use DescribesTrafficToClaude;

    /**
     * The rule language's grammar, as the Rules page's "Ask" teaches it to Claude.
     */
    private const string GRAMMAR = <<<'GRAMMAR'
        script    := action subjects [AS 'tag'] FOR duration [WHERE expr] [WITHIN span HAVING having] [UNTIL expr]
        action    := BAN | TAG
        subjects  := subject {, subject}       subject := ip | ja4
        duration  := number unit | FOREVER     span := number unit
        unit      := SECOND | MINUTE | HOUR | DAY | WEEK, or the plural
        expr      := term {OR term}            term := factor {AND factor}
        factor    := NOT factor | ( expr ) | field predicate
        predicate := op literal | [NOT] LIKE 'pattern' | [NOT] IN (literal, …) | IS [NOT] NULL
        op        := = | != | < | <= | > | >=
        having    := hterm {OR hterm}          hterm := hfactor {AND hfactor}
        hfactor   := NOT hfactor | ( having ) | COUNT ( * | DISTINCT field ) [FILTER ( WHERE expr )] op number

        Clauses go in that order. Keywords ignore case. Strings take single quotes, doubling a quote inside ('O''Brien'). Numbers are bare. < and > work only on number fields. LIKE ignores case: % matches any run of characters and _ one character. AND binds tighter than OR, so wrap OR groups in parentheses when you AND them with something else. A line starting with -- is a comment.

        - TAG names what the rule found (TAG ip, ja4 AS 'phpmyadmin-probe'); BAN takes no AS. Use TAG unless the person asks to ban or block.
        - The subjects are who the verdict lands on: ip, ja4 (an IP and its TLS fingerprint together, which is one client) unless only IPs or only fingerprints are meant.
        - FOR is how long the verdict lasts. A verdict with no end is FOR FOREVER.
        - WHERE picks the requests that count. Without WITHIN, one matching request is enough.
        - WITHIN … HAVING counts each subject's matching requests over a window of at most 24 HOURS.
        - UNTIL lifts a verdict early, as soon as its subject makes a request that passes it.
        - To send a rule's clients to a gym, give create-rule a gym; the rule itself only tags or bans.
        GRAMMAR;

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
     * Handle the tool request.
     */
    public function handle(Request $request, RuleTemplates $templates): Response|ResponseFactory
    {
        return Response::structured([
            'grammar' => self::GRAMMAR,
            'fields' => rtrim($this->fieldReference()."\n".$this->fieldNotes()),
            'examples' => collect($templates->all())
                ->map(fn (array $template) => ['name' => $template['name'], 'source' => $template['source']])
                ->values()
                ->all(),
        ]);
    }
}
