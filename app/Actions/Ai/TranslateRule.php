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
    private const int PROMPT_VERSION = 5;

    /**
     * The most gym routines sent along.
     */
    private const int MAX_ROUTINES = 100;

    /**
     * Worked examples for the instructions. Each script must parse; a test holds them to it.
     *
     * @var list<array{routines: list<array{id: int, name: string, entry: string}>, current?: string, current_routine?: string, request: string, name: string, source: string, routine_id: int, routine_name: string, note: string}>
     */
    public const array EXAMPLES = [
        [
            'routines' => [],
            'request' => 'create a rule to send everyone on shop.example.com who goes for phpmyadmin to the PhpMyAdmin Gym',
            'name' => 'phpMyAdmin probes on the shop',
            'source' => "-- Anyone on shop.example.com looking for phpMyAdmin.\nTAG ip, ja4 AS 'phpmyadmin-probe' FOR 1 DAY\nWHERE host = 'shop.example.com'\n  AND path LIKE '%phpmyadmin%'",
            'routine_id' => 0,
            'routine_name' => 'PhpMyAdmin Gym',
            'note' => '',
        ],
        [
            'routines' => [],
            'current' => "-- Anyone on shop.example.com looking for phpMyAdmin.\nTAG ip, ja4 AS 'phpmyadmin-probe' FOR 1 DAY\nWHERE host = 'shop.example.com'\n  AND path LIKE '%phpmyadmin%'",
            'current_routine' => 'PhpMyAdmin Gym (new)',
            'request' => 'make it a week, and do the blog too',
            'name' => 'phpMyAdmin probes on the shop and blog',
            'source' => "-- Anyone on the shop or the blog looking for phpMyAdmin.\nTAG ip, ja4 AS 'phpmyadmin-probe' FOR 7 DAYS\nWHERE host IN ('shop.example.com', 'blog.example.com')\n  AND path LIKE '%phpmyadmin%'",
            'routine_id' => 0,
            'routine_name' => 'PhpMyAdmin Gym',
            'note' => '',
        ],
        [
            'routines' => [['id' => 7, 'name' => 'Login wall', 'entry' => 'Visitors whose path starts with “/login”.']],
            'request' => 'tag anyone failing to sign in 5 times in 10 minutes and put them through the login wall',
            'name' => 'Repeated failed sign-ins',
            'source' => "-- One client failing to sign in, again and again.\nTAG ip, ja4 AS 'failed-login' FOR 1 DAY\nWHERE method = 'POST' AND path = '/login' AND status >= 400\nWITHIN 10 MINUTES\nHAVING COUNT(*) >= 5",
            'routine_id' => 7,
            'routine_name' => '',
            'note' => 'Assumed a failed sign-in answers with an error status.',
        ],
        [
            'routines' => [],
            'request' => 'ban datacenter IPs that hit more than 3 of our sites in an hour, forever',
            'name' => 'Datacenter site sweeps',
            'source' => "-- One datacenter IP sweeping several of your sites.\nBAN ip FOR FOREVER\nWHERE network.kind = 'hosting'\nWITHIN 1 HOUR\nHAVING COUNT(DISTINCT host) > 3",
            'routine_id' => 0,
            'routine_name' => '',
            'note' => '',
        ],
        [
            'routines' => [['id' => 3, 'name' => 'Datacenter waiting room', 'entry' => 'Visitors whose network is DigitalOcean or Hetzner.']],
            'request' => 'send wordpress scanners to the gym',
            'name' => 'WordPress scanners',
            'source' => "-- Anyone scanning for WordPress.\nTAG ip, ja4 AS 'wordpress-probe' FOR 1 DAY\nWHERE path LIKE '%/wp-%' OR path LIKE '%xmlrpc.php%' OR path LIKE '%/wordpress/%'",
            'routine_id' => 0,
            'routine_name' => 'WordPress scanners',
            'note' => '',
        ],
        [
            'routines' => [],
            'request' => 'what is the weather like',
            'name' => '',
            'source' => '',
            'routine_id' => 0,
            'routine_name' => '',
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
            'routine_id' => ['type' => 'integer'],
            'routine_name' => ['type' => 'string'],
            'note' => ['type' => 'string'],
        ],
        'required' => ['name', 'source', 'routine_id', 'routine_name', 'note'],
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
     * @param  string  $currentRoutine  The routine the draft's clients go to, e.g. "PhpMyAdmin Gym (new)", or "".
     *
     * @throws AnthropicException
     */
    public function handle(AiCredential $credential, string $request, array $domains = [], array $routines = [], string $current = '', string $currentRoutine = ''): TranslatedRule
    {
        $request = trim($request);
        $current = trim($current);
        $hostnames = $this->hostnames($request, array_values($domains));
        $routines = array_slice($routines, 0, self::MAX_ROUTINES);
        $cacheKey = 'ai.rule.'.sha1((string) json_encode([self::PROMPT_VERSION, config('services.anthropic.search_model'), config('services.anthropic.search_effort'), $request, $current, $currentRoutine, $hostnames, $routines]));

        if (is_array($cached = Cache::get($cacheKey))) {
            return $this->translated($cached);
        }

        $prompt = $this->prompt($request, $current, $currentRoutine, $hostnames, $routines);
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
            .(isset($example['current']) ? "Current rule:\n{$example['current']}\n" : '')
            .(isset($example['current_routine']) ? "Current gym routine: {$example['current_routine']}\n" : '')
            ."Request: {$example['request']}\n"
            .json_encode(Arr::only($example, ['name', 'source', 'routine_id', 'routine_name', 'note']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->implode("\n\n");
        $fields = rtrim($this->fieldReference()."\n".$this->fieldNotes());

        return <<<PROMPT
            You turn requests for rules into rule scripts for NiceTryBot's Rules page. NiceTryBot is an edge proxy in front of its users' sites: it logs every request, and passes, observes, diverts (to a "bot gym") or blocks it. A rule runs every minute over the requests logged since it last ran, and reaches a verdict on each client it matches: it tags or bans the client for a while. A gym routine can take the clients holding a rule's verdict, so "send them to the gym" means a rule plus the routine its clients go through. A rule script is not SQL: NiceTryBot parses it itself, so write only the grammar below.

            Answer with:
            - name: a short name for the rule, like "phpMyAdmin probes on the shop". "" when there's no rule.
            - source: the rule script, one clause per line, starting with a "-- " comment line that says who it catches. "" when the request can't be written as a rule at all.
            - routine_id: the id of the team's gym routine to send the rule's clients through, when the request names one of the listed routines, even loosely ("the PhpMyAdmin gym" for a routine called "PhpMyAdmin"). 0 otherwise.
            - routine_name: when routine_id is 0 and the request asks to send the clients to the gym, the name of a new routine for them: the name the request gives, as written ("PhpMyAdmin Gym"), or the rule's name when it gives none. "" when the request doesn't ask for the gym, or routine_id isn't 0.
            - note: one short sentence for the person when you left part of the request out because the language can't say it, or had to guess what they meant. "" otherwise. Don't restate the rule; the page already shows it.

            # Grammar
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

            Clauses go in that order. Keywords ignore case. Strings take single quotes, doubling a quote inside ('O''Brien'). Numbers are bare. < and > work only on number fields. LIKE ignores case: % matches any run of characters and _ one character. AND binds tighter than OR, so wrap OR groups in parentheses when you AND them with something else. A line starting with -- is a comment. Every condition names a field: a quoted string can't stand on its own.

            # What each clause does
            - TAG names what the rule found: TAG ip, ja4 AS 'phpmyadmin-probe', in lowercase words joined by hyphens. Use TAG unless the request says ban or block; then BAN, which takes no AS.
            - The subjects are who the verdict lands on: ip, ja4 (an IP and its TLS fingerprint together, which is one client) unless the request says IPs or fingerprints alone.
            - FOR is how long the verdict lasts: what the request says, otherwise 1 DAY. A verdict with no end is FOR FOREVER.
            - WHERE picks the requests that count. Without WITHIN, one matching request is enough.
            - WITHIN … HAVING counts each subject's matching requests over a window of at most 24 HOURS: "more than 5 failed sign-ins in 10 minutes" is WITHIN 10 MINUTES HAVING COUNT(*) > 5. FILTER counts only some of them: COUNT(*) FILTER (WHERE path = '/') = 0 is "never visited the homepage".
            - UNTIL lifts a verdict early, as soon as its subject makes a request that passes it, like a successful sign-in: UNTIL method = 'GET' AND path = '/dashboard' AND status = 200.

            {$fields}

            # Hostnames
            The request comes with the team's hostnames. A team with many sends only some: every hostname the request names, then others, with how many hostnames sit under each parent domain. Use the exact hostname when the request names or describes one ("mysite.com", "the shop"), with host = or host IN. When the list is partial, a hostname that isn't in it may still be the team's: use it as written. A request that names no site covers them all: leave host out.

            # Gym routines
            The request comes with the team's gym routines: each one's id, name, and who enters it now. Pick one only when the request names it. Don't pick a routine just because who enters it sounds related.

            # The current rule
            The request may come with the draft in the editor and the routine its clients go to. When the request reads as a change to them ("make it a week", "also the blog", "only POSTs", "send them to … instead"), edit them: answer with the whole new script, a name that still fits, and the same routine unless the request changes it. Otherwise write a fresh rule and ignore them.

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
    private function prompt(string $request, string $current, string $currentRoutine, array $hostnames, array $routines): string
    {
        $parts = $this->hostnameParts($hostnames);

        $parts[] = $routines === [] ? '<gym_routines>none</gym_routines>' : "<gym_routines>\n".$this->routineLines($routines)."\n</gym_routines>";

        if ($current !== '') {
            $parts[] = "<current_rule>\n{$current}\n</current_rule>";
        }

        if ($currentRoutine !== '') {
            $parts[] = "<current_gym_routine>{$currentRoutine}</current_gym_routine>";
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
     * @return array{name: string, source: string, routine_id: int, routine_name: string, note: ?string}
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
            'routine_id' => is_int($answer['routine_id'] ?? null) ? $answer['routine_id'] : 0,
            'routine_name' => Str::limit(trim(is_string($answer['routine_name'] ?? null) ? $answer['routine_name'] : ''), 100, ''),
            'note' => is_string($answer['note'] ?? null) && trim($answer['note']) !== '' ? trim($answer['note']) : null,
        ];
    }

    /**
     * @param  array{name: string, source: string, routine_id: int, routine_name: string, note: ?string}  $answer
     */
    private function translated(array $answer): TranslatedRule
    {
        return new TranslatedRule(
            $answer['name'],
            $answer['source'],
            $answer['routine_id'] > 0 ? $answer['routine_id'] : null,
            $answer['routine_id'] > 0 || $answer['routine_name'] === '' ? null : $answer['routine_name'],
            $answer['note'],
        );
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
