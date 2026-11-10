<?php

namespace App\Actions\Ai;

use App\Concerns\DescribesTrafficToClaude;
use App\Data\RuleScript;
use App\Data\RuleScriptSyntaxError;
use App\Data\TranslatedRule;
use App\Models\AiCredential;
use App\Support\AnthropicClient;
use App\Support\AnthropicException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Turn a plain-English request for a rule ("send everyone on the shop who goes for phpmyadmin to the
 * PhpMyAdmin Gym") into a rule script, a name for it, and the gym routine its clients should go
 * through: one of the team's, or a new one by name. Claude writes it with the user's own key; the
 * parser checks the script, and Claude gets one chance to fix a mistake.
 *
 * Nothing Claude writes runs as SQL or is saved: the script lands in the editor, with its backtest, for
 * the person to save.
 */
class TranslateRule
{
    use DescribesTrafficToClaude;

    /**
     * Bumped whenever the instructions change, so translations cached under the old ones aren't reused.
     */
    private const int PROMPT_VERSION = 8;

    /**
     * The most gym routines sent along.
     */
    private const int MAX_ROUTINES = 100;

    /**
     * Worked examples for the instructions. Each script must parse; a test holds them to it.
     *
     * @var list<array{routines: list<array{id: int, name: string, entry: string}>, destinations?: string, current?: string, request: string, name: string, source: string, note: string}>
     */
    public const array EXAMPLES = [
        [
            'routines' => [],
            'destinations' => 'GYM, TARPIT, BLACKHOLE',
            'request' => 'create a rule to send everyone on shop.example.com who goes for phpmyadmin to the PhpMyAdmin Gym',
            'name' => 'phpMyAdmin probes on the shop',
            'source' => "-- Anyone on shop.example.com looking for phpMyAdmin.\nTAG ip, ja4 AS 'phpmyadmin-probe' FOR 1 DAY\nGYM 'PhpMyAdmin Gym'\nWHERE host = 'shop.example.com'\n  AND path LIKE '%phpmyadmin%'",
            'note' => '',
        ],
        [
            'routines' => [],
            'destinations' => 'GYM, TARPIT, BLACKHOLE',
            'current' => "-- Anyone on shop.example.com looking for phpMyAdmin.\nTAG ip, ja4 AS 'phpmyadmin-probe' FOR 1 DAY\nGYM 'PhpMyAdmin Gym'\nWHERE host = 'shop.example.com'\n  AND path LIKE '%phpmyadmin%'",
            'request' => 'make it a week, and do the blog too',
            'name' => 'phpMyAdmin probes on the shop and blog',
            'source' => "-- Anyone on the shop or the blog looking for phpMyAdmin.\nTAG ip, ja4 AS 'phpmyadmin-probe' FOR 7 DAYS\nGYM 'PhpMyAdmin Gym'\nWHERE host IN ('shop.example.com', 'blog.example.com')\n  AND path LIKE '%phpmyadmin%'",
            'note' => '',
        ],
        [
            'routines' => [['id' => 7, 'name' => 'Login wall', 'entry' => 'Visitors whose path starts with “/login”.']],
            'destinations' => 'GYM, TARPIT, BLACKHOLE',
            'request' => 'tag anyone failing to sign in 5 times in 10 minutes and put them through the login wall',
            'name' => 'Repeated failed sign-ins',
            'source' => "-- One client failing to sign in, again and again.\nTAG ip, ja4 AS 'failed-login' FOR 1 DAY\nGYM 'Login wall'\nWHERE method = 'POST' AND path = '/login' AND status >= 400\nWITHIN 10 MINUTES\nHAVING COUNT(*) >= 5",
            'note' => 'Assumed a failed sign-in answers with an error status.',
        ],
        [
            'routines' => [],
            'destinations' => 'GYM, TARPIT',
            'request' => 'ban datacenter IPs that hit more than 3 of our sites in an hour, forever',
            'name' => 'Datacenter site sweeps',
            'source' => "-- One datacenter IP sweeping several of your sites.\nBLOCK ip FOR FOREVER\nWHERE network.kind = 'hosting'\nWITHIN 1 HOUR\nHAVING COUNT(DISTINCT host) > 3",
            'note' => 'Left the clients tagged only; add GYM, TARPIT or BLACKHOLE to actually stop them.',
        ],
        [
            'routines' => [],
            'destinations' => 'GYM, TARPIT',
            'request' => 'make curl probes wait in a tarpit called curl-probes',
            'name' => 'Curl probes',
            'source' => "-- Command-line tools probing the site.\nTAG ip AS 'curl-probe' FOR 1 DAY\nTARPIT 'curl-probes'\nWHERE category = 'probe' AND ua LIKE 'curl/%'",
            'note' => '',
        ],
        [
            'routines' => [],
            'destinations' => 'GYM, TARPIT, BLACKHOLE',
            'request' => 'drop IPs that probe credential paths on two or more of our sites in a day into the blackhole for a month',
            'name' => 'Multi-host credential probes',
            'source' => "-- Probing credential paths across several sites: a scanner, not a visitor.\nTAG ip AS 'multi-host-probe' FOR 30 DAYS\nBLACKHOLE\nWHERE category = 'probe/creds'\nWITHIN 1 DAY HAVING COUNT(DISTINCT host) >= 2",
            'note' => '',
        ],
        [
            'routines' => [],
            'destinations' => 'GYM',
            'request' => 'what is the weather like',
            'name' => '',
            'source' => '',
            'note' => 'That isn’t a rule about traffic, so there’s nothing to write.',
        ],
    ];

    /**
     * The shape Claude answers in.
     *
     * @var array<string, mixed>
     */
    private const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'source' => ['type' => 'string'],
            'note' => ['type' => 'string'],
        ],
        'required' => ['name', 'source', 'note'],
        'additionalProperties' => false,
    ];

    public function __construct(private AnthropicClient $anthropic)
    {
        //
    }

    /**
     * Translate a request, refining the current draft (and the gym routine it goes to) when the request
     * reads as a change to it.
     *
     * @param  list<string>  $domains  The team's hostnames, so "the shop" can become host = 'shop.example.com'.
     * @param  list<array{id: int, name: string, entry: string}>  $routines  The team's gym routines, and who enters each.
     * @param  string  $current  The draft in the editor, or "".
     * @param  list<string>  $destinations  The destination keywords the team can use (GYM, and TARPIT/BLACKHOLE when their add-on is on).
     *
     * @throws AnthropicException
     */
    public function handle(AiCredential $credential, string $request, array $domains = [], array $routines = [], string $current = '', array $destinations = ['GYM']): TranslatedRule
    {
        $request = trim($request);
        $current = trim($current);
        $hostnames = $this->hostnames($request, array_values($domains));
        $routines = array_slice($routines, 0, self::MAX_ROUTINES);
        $cacheKey = 'ai.rule.'.sha1((string) json_encode([self::PROMPT_VERSION, config('services.anthropic.search_model'), config('services.anthropic.search_effort'), $request, $current, $destinations, $hostnames, $routines]));

        $cached = Cache::get($cacheKey);

        if (is_array($cached) && is_string($cached['name'] ?? null) && is_string($cached['source'] ?? null)) {
            return new TranslatedRule($cached['name'], $cached['source'], is_string($cached['note'] ?? null) ? $cached['note'] : null);
        }

        $prompt = $this->prompt($request, $current, $destinations, $hostnames, $routines);
        $answer = $this->ask($credential, $prompt);

        if ($error = $this->syntaxError($answer['source'])) {
            $answer = $this->ask($credential, $prompt."\n\n".$this->correction($answer['source'], $error));

            if ($error = $this->syntaxError($answer['source'])) {
                throw new AnthropicException(__('Claude wrote a rule that doesn’t parse (:error). Try rewording it, or write the rule yourself.', ['error' => $error->getMessage()]));
            }
        }

        Cache::put($cacheKey, $answer, now()->addDay());

        return $this->translated($answer);
    }

    /**
     * The instructions, the same for every request so Anthropic can cache them.
     */
    public function instructions(): string
    {
        $examples = collect(self::EXAMPLES)->map(fn (array $example) => 'Gym routines: '.($example['routines'] === [] ? 'none' : $this->routineLines($example['routines']))."\n"
            .'Destinations: '.$example['destinations']."\n"
            .(isset($example['current']) ? "Current rule:\n{$example['current']}\n" : '')
            ."Request: {$example['request']}\n"
            .json_encode(Arr::only($example, ['name', 'source', 'note']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->implode("\n\n");
        $fields = rtrim($this->fieldReference()."\n".$this->fieldNotes());

        return <<<PROMPT
            You turn requests for rules into rule scripts for NiceTryBot's Rules page. NiceTryBot is an edge proxy in front of its users' sites: it logs every request, and passes, observes, diverts (to a "bot gym") or blocks it. A rule runs every minute over the requests logged since it last ran, and reaches a verdict on each client it matches: it tags or blocks the client for a while. A gym routine can take the clients holding a rule's verdict, so "send them to the gym" means a rule plus the routine its clients go through. A rule script is not SQL: NiceTryBot parses it itself, so write only the grammar below.

            Answer with:
            - name: a short name for the rule, like "phpMyAdmin probes on the shop". "" when there's no rule.
            - source: the rule script, one clause per line, starting with a "-- " comment line that says who it catches. Where the clients go is part of the script (see Destinations). "" when the request can't be written as a rule at all.
            - note: one short sentence for the person when you left part of the request out because the language can't say it, or had to guess what they meant. "" otherwise. Don't restate the rule; the page already shows it.

            # Grammar
            script    := action subjects [AS 'tag'] FOR duration [EVERY span] [destination …] [WHERE expr] [WITHIN span HAVING having] [UNTIL expr]
            action    := BLOCK | TAG | FINGERPRINT
            destination := GYM 'routine name' | TARPIT 'tarpit name' | BLACKHOLE
            subjects  := subject {, subject}       subject := ip | ja4
            duration  := number unit | FOREVER     span := number unit
            unit      := SECOND | MINUTE | HOUR | DAY | WEEK, or the plural
            expr      := term {OR term}            term := factor {AND factor}
            factor    := NOT factor | ( expr ) | field predicate
            predicate := op literal | [NOT] LIKE 'pattern' | [NOT] IN (literal, …) | IS [NOT] NULL
            op        := = | != | < | <= | > | >=
            having    := hterm {OR hterm}          hterm := hfactor {AND hfactor}
            hfactor   := NOT hfactor | ( having ) | COUNT ( * | DISTINCT field ) [FILTER ( WHERE expr )] op number

            Clauses go in that order. Keywords ignore case. Strings take single quotes, doubling a quote inside ('O''Brien'). Numbers are bare. < and > work only on number fields. LIKE ignores case: % matches any run of characters and _ one character. AND binds tighter than OR, so wrap OR groups in parentheses when you AND them with something else. A line starting with -- is a comment. Every condition names a field: a quoted string can't stand on its own.

            # What each clause does
            - TAG names what the rule found: TAG ip, ja4 AS 'phpmyadmin-probe', in lowercase words joined by hyphens. Use TAG unless the request says ban or block; then BLOCK, which takes no AS.
            - The subjects are who the verdict lands on: ip, ja4 (an IP and its TLS fingerprint together, which is one client) unless the request says IPs or fingerprints alone.
            - FOR is how long the verdict lasts: what the request says, otherwise 1 DAY. A verdict with no end is FOR FOREVER.
            - FINGERPRINT tags too, and fingerprints the browser on the request itself, then lets it carry on; its AS name is also its Known Faces bucket. Use it only when asked to fingerprint. One request has to decide it, so no WITHIN. EVERY says how soon the same subject can be fingerprinted again, at least 10 MINUTES; without it, FOR decides.
            - WHERE picks the requests that count. Without WITHIN, one matching request is enough.
            - WITHIN … HAVING counts each subject's matching requests over a window of at most 24 HOURS: "more than 5 failed sign-ins in 10 minutes" is WITHIN 10 MINUTES HAVING COUNT(*) > 5. FILTER counts only some of them: COUNT(*) FILTER (WHERE path = '/') = 0 is "never visited the homepage".
            - UNTIL lifts a verdict early, as soon as its subject makes a request that passes it, like a successful sign-in: UNTIL method = 'GET' AND path = '/dashboard' AND status = 200.

            {$fields}

            # Hostnames
            The request comes with the team's hostnames. A team with many sends only some: every hostname the request names, then others, with how many hostnames sit under each parent domain. Use the exact hostname when the request names or describes one ("mysite.com", "the shop"), with host = or host IN. When the list is partial, a hostname that isn't in it may still be the team's: use it as written. A request that names no site covers them all: leave host out.

            # Destinations
            A destination clause goes after the duration and before WHERE, and says where the rule's clients go. The request comes with the destinations this team can use. Use only those; leave a destination out and the rule only tags or blocks, without diverting anyone.
            - GYM 'routine name' sends the clients through a gym routine (a browser check, then proof of work). Use the name of a listed gym routine when the request names one, even loosely ("the PhpMyAdmin gym" → GYM 'PhpMyAdmin'); otherwise invent a short name (the rule's name does). A routine by that name is made if the team doesn't have it.
            - TARPIT 'name' makes the clients wait at the edge, a little longer each time. Use it when the request asks for a tarpit or to slow someone down.
            - BLACKHOLE drops the clients at the kernel on every site. Use it only when the request clearly asks to blackhole or hard-drop them.
            You can stack them (GYM '…' then TARPIT '…'). "send them to the gym" means GYM; it is no longer a separate field.

            # Gym routines
            The request comes with the team's gym routines: each one's id, name, and who enters it now. Use a routine's name in a GYM clause only when the request names it. Don't pick one just because who enters it sounds related.

            # The current rule
            The request may come with the draft in the editor. When the request reads as a change to it ("make it a week", "also the blog", "only POSTs", "send them to … instead"), edit it: answer with the whole new script, keeping its destination clauses unless the request changes them, and a name that still fits. Otherwise write a fresh rule and ignore it.

            # Examples
            {$examples}
            PROMPT;
    }

    /**
     * The request, with what Claude needs to place it.
     *
     * @param  array{hostnames: list<string>, total: int, groups: array<string, int>}  $hostnames
     * @param  list<array{id: int, name: string, entry: string}>  $routines
     */
    /**
     * @param  list<string>  $destinations
     * @param  array{hostnames: list<string>, total: int, groups: array<string, int>}  $hostnames
     * @param  list<array{id: int, name: string, entry: string}>  $routines
     */
    private function prompt(string $request, string $current, array $destinations, array $hostnames, array $routines): string
    {
        $parts = $this->hostnameParts($hostnames);

        $parts[] = $routines === [] ? '<gym_routines>none</gym_routines>' : "<gym_routines>\n".$this->routineLines($routines)."\n</gym_routines>";
        $parts[] = '<destinations>'.($destinations === [] ? 'none' : implode(', ', $destinations)).'</destinations>';

        if ($current !== '') {
            $parts[] = "<current_rule>\n{$current}\n</current_rule>";
        }

        $parts[] = "<request>\n{$request}\n</request>";

        return implode("\n\n", $parts);
    }

    /**
     * @param  list<array{id: int, name: string, entry: string}>  $routines
     */
    private function routineLines(array $routines): string
    {
        return collect($routines)->map(fn (array $routine) => "{$routine['id']}: {$routine['name']}. {$routine['entry']}")->implode("\n");
    }

    private function correction(string $source, RuleScriptSyntaxError $error): string
    {
        return "Your last answer was:\n<source>\n{$source}\n</source>\nIt doesn't parse: {$error->getMessage()} (line {$error->scriptLine}, column {$error->scriptColumn}). Answer again with a corrected rule.";
    }

    /**
     * Ask Claude, and read its answer.
     *
     * @return array{name: string, source: string, note: ?string}
     *
     * @throws AnthropicException
     */
    private function ask(AiCredential $credential, string $prompt): array
    {
        $message = $this->anthropic->message($credential->secret, [
            'model' => config('services.anthropic.search_model'),
            'max_tokens' => 16000,
            'output_config' => array_filter([
                'effort' => config('services.anthropic.search_effort') ?: null,
                'format' => ['type' => 'json_schema', 'schema' => self::SCHEMA],
            ]),
            'system' => [['type' => 'text', 'text' => $this->instructions(), 'cache_control' => ['type' => 'ephemeral']]],
            'messages' => [['role' => 'user', 'content' => $prompt]],
        ], $credential->workspace_id);

        $credential->forceFill(['last_used_at' => now()])->save();

        $answer = json_decode(AnthropicClient::text($message), true);

        if (($message['stop_reason'] ?? null) === 'max_tokens' || ! is_array($answer) || ! is_string($answer['source'] ?? null)) {
            throw new AnthropicException(__('Claude’s answer didn’t come back in the shape a rule needs. Try again.'));
        }

        return [
            'name' => Str::limit(trim(is_string($answer['name'] ?? null) ? $answer['name'] : ''), 100, ''),
            'source' => trim($answer['source']),
            'note' => is_string($answer['note'] ?? null) && trim($answer['note']) !== '' ? trim($answer['note']) : null,
        ];
    }

    /**
     * @param  array{name: string, source: string, note: ?string}  $answer
     */
    private function translated(array $answer): TranslatedRule
    {
        return new TranslatedRule($answer['name'], $answer['source'], $answer['note']);
    }

    private function syntaxError(string $source): ?RuleScriptSyntaxError
    {
        if ($source === '') {
            return null;
        }

        try {
            RuleScript::parse($source);

            return null;
        } catch (RuleScriptSyntaxError $error) {
            return $error;
        }
    }
}
