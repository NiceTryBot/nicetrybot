<?php

namespace App\Actions\Ai;

use App\Concerns\DescribesTrafficToClaude;
use App\Data\RuleScriptParser;
use App\Data\RuleScriptSyntaxError;
use App\Data\TranslatedSearch;
use App\Enums\TrafficPeriod;
use App\Models\AiCredential;
use App\Support\AnthropicClient;
use App\Support\AnthropicException;
use Illuminate\Support\Facades\Cache;

/**
 * Turn a plain-English question about traffic ("404s from hosting networks this week") into an
 * Activity search: a WHERE expression in the rule language, plus the period it names. Claude writes
 * it with the user's own key; the parser checks it, and Claude gets one chance to fix a mistake.
 *
 * Nothing Claude writes runs as SQL: the expression goes through the same parser and query builder as
 * one typed by hand.
 */
class TranslateSearch
{
    use DescribesTrafficToClaude;

    /**
     * Bumped whenever the instructions change, so translations cached under the old ones aren't reused.
     */
    private const int PROMPT_VERSION = 8;

    /**
     * Worked examples for the instructions. Each must parse; a test holds them to it.
     *
     * @var list<array{question: string, current?: string, current_client?: string, search: string, period: string, client: string, view: string, note: string}>
     */
    public const array EXAMPLES = [
        ['question' => '404s from hosting networks', 'search' => "status = 404 AND network.kind = 'hosting'", 'period' => '', 'client' => '', 'view' => 'requests', 'note' => ''],
        ['question' => 'who was looking for .env files this week', 'search' => "path LIKE '%.env%'", 'period' => '7d', 'client' => '', 'view' => 'requests', 'note' => ''],
        ['question' => 'blocked requests from outside the US in the last hour', 'search' => "action = 'blocked' AND country != 'US'", 'period' => '1h', 'client' => '', 'view' => 'requests', 'note' => ''],
        ['question' => 'anything from digitalocean or ovh', 'search' => "network LIKE '%digitalocean%' OR network LIKE '%ovh%'", 'period' => '', 'client' => '', 'view' => 'requests', 'note' => ''],
        ['question' => 'slow POSTs to the shop', 'search' => "host = 'shop.example.com' AND method = 'POST' AND duration > 1000", 'period' => '', 'client' => '', 'view' => 'requests', 'note' => ''],
        ['question' => 'show me all traffic to shop.example.com from spoofed clients', 'search' => "host = 'shop.example.com'", 'period' => '', 'client' => 'spoofed', 'view' => 'requests', 'note' => ''],
        ['question' => 'bots pretending to be chrome on the preview sites today', 'search' => "host LIKE '%.previews.example.net'", 'period' => '24h', 'client' => 'spoofed', 'view' => 'requests', 'note' => ''],
        ['question' => 'old chrome versions from hosting networks', 'search' => "network.kind = 'hosting' AND ua LIKE '%Chrome/%'", 'period' => '', 'client' => 'outdated', 'view' => 'requests', 'note' => ''],
        ['question' => 'outdated firefox clients on the shop', 'search' => "host = 'shop.example.com' AND ua LIKE '%Firefox/%'", 'period' => '', 'client' => 'outdated', 'view' => 'requests', 'note' => ''],
        ['question' => 'suspicious 404s on the shop that aren\'t wordpress', 'search' => "host = 'shop.example.com' AND action IN ('observed', 'diverted', 'blocked') AND status = 404 AND NOT (path LIKE '%/wp-%' OR path LIKE '%xmlrpc.php%' OR path LIKE '%/wordpress/%')", 'period' => '', 'client' => '', 'view' => 'requests', 'note' => ''],
        ['question' => 'clients that are not browsers', 'search' => "ja4.alpn = '00' OR lang IS NULL", 'period' => '', 'client' => '', 'view' => 'requests', 'note' => 'Guessed from tells: no ALPN in the TLS handshake, or no Accept-Language header.'],
        ['question' => 'only the ones that got through', 'current' => "status = 404 AND network.kind = 'hosting'", 'current_client' => 'spoofed', 'search' => "status = 404 AND network.kind = 'hosting' AND action = 'passed'", 'period' => '', 'client' => 'spoofed', 'view' => 'requests', 'note' => ''],
        ['question' => 'wordpress probes from russia at night', 'search' => "category = 'probe' AND (path LIKE '%/wp-%' OR path LIKE '%xmlrpc.php%' OR path LIKE '%/wordpress/%') AND country = 'RU'", 'period' => '', 'client' => '', 'view' => 'requests', 'note' => 'The search can’t filter by time of day, so “at night” was left out.'],
        ['question' => 'show me all traffic on the shop from visitors who reached /thank-you but never visited the homepage', 'search' => "host = 'shop.example.com' HAVING COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1 AND COUNT(*) FILTER (WHERE path = '/') = 0", 'period' => '', 'client' => '', 'view' => 'requests', 'note' => ''],
        ['question' => 'show me all IPs on the shop that have a */thank-you visit that never visited the homepage', 'search' => "host = 'shop.example.com' GROUP BY ip HAVING COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1 AND COUNT(*) FILTER (WHERE path = '/') = 0", 'period' => '', 'client' => '', 'view' => 'clients', 'note' => ''],
        ['question' => 'which fingerprints posted the claim form more than 20 times this week', 'search' => "method = 'POST' AND path LIKE '/claim%' GROUP BY ja4 HAVING COUNT(*) > 20", 'period' => '7d', 'client' => '', 'view' => 'clients', 'note' => ''],
        ['question' => 'clients that hit more than 3 of our sites today', 'search' => 'HAVING COUNT(DISTINCT host) > 3', 'period' => '24h', 'client' => '', 'view' => 'clients', 'note' => ''],
        ['question' => 'every unique path probed for database dumps this week, with how many times each was asked for', 'search' => "path LIKE '%.sql%' OR path LIKE '%dump%' OR path LIKE '%phpmyadmin%' OR path LIKE '%adminer%'", 'period' => '7d', 'client' => '', 'view' => 'paths', 'note' => ''],
        ['question' => 'top IPs probing for secrets', 'search' => "category = 'probe/creds' GROUP BY ip", 'period' => '', 'client' => '', 'view' => 'clients', 'note' => ''],
        ['question' => 'what is the weather like', 'search' => '', 'period' => '', 'client' => '', 'view' => 'requests', 'note' => 'That isn’t a question about requests, so there’s nothing to search for.'],
    ];

    /**
     * The shape Claude answers in.
     *
     * @var array<string, mixed>
     */
    private const array SCHEMA = [
        'type' => 'object',
        'properties' => [
            'search' => ['type' => 'string'],
            'period' => ['type' => 'string', 'enum' => ['', '1m', '1h', '2h', '4h', '6h', '8h', '16h', '24h', '36h', '48h', '3d', '7d', '14d', '21d', '30d']],
            'client' => ['type' => 'string', 'enum' => ['', 'spoofed', 'outdated']],
            'view' => ['type' => 'string', 'enum' => ['requests', 'clients', 'paths']],
            'note' => ['type' => 'string'],
        ],
        'required' => ['search', 'period', 'client', 'view', 'note'],
        'additionalProperties' => false,
    ];

    public function __construct(private AnthropicClient $anthropic)
    {
        //
    }

    /**
     * Translate a question, refining the current search (and client filter) when the question reads as a
     * change to it.
     *
     * @param  list<string>  $domains  The team's hostnames, so "the shop" can become host = 'shop.example.com'.
     * @param  string  $client  The client filter the page has now: "spoofed", "outdated" or "".
     *
     * @throws AnthropicException
     */
    public function handle(AiCredential $credential, string $question, string $current = '', array $domains = [], string $client = ''): TranslatedSearch
    {
        $question = trim($question);
        $current = trim($current);
        $hostnames = $this->hostnames($question, array_values($domains));
        $cacheKey = 'ai.search.'.sha1((string) json_encode([self::PROMPT_VERSION, config('services.anthropic.search_model'), config('services.anthropic.search_effort'), $question, $current, $client, $hostnames]));

        if (is_array($cached = Cache::get($cacheKey))) {
            return $this->translated($cached);
        }

        $prompt = $this->prompt($question, $current, $client, $hostnames);
        $answer = $this->ask($credential, $prompt);

        if ($error = $this->syntaxError($answer['search'])) {
            $answer = $this->ask($credential, $prompt."\n\n".$this->correction($answer['search'], $error));

            if ($error = $this->syntaxError($answer['search'])) {
                throw new AnthropicException(__('Claude wrote a search that doesn’t parse (:error). Try rewording it, or write the WHERE yourself.', ['error' => $error->getMessage()]));
            }
        }

        Cache::put($cacheKey, $answer, now()->addDay());

        return $this->translated($answer);
    }

    /**
     * The instructions, the same for every question so Anthropic can cache them.
     */
    public function instructions(): string
    {
        $examples = collect(self::EXAMPLES)->map(fn (array $example) => ($example['current'] ?? null ? "Current search: {$example['current']}\n" : '')
            ."Question: {$example['question']}\n"
            .($example['current_client'] ?? null ? "Current client filter: {$example['current_client']}\n" : '')
            .json_encode(['search' => $example['search'], 'period' => $example['period'], 'client' => $example['client'], 'view' => $example['view'], 'note' => $example['note']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
            ->implode("\n\n");

        return <<<PROMPT
            You turn questions about web traffic into searches for NiceTryBot's Activity page. NiceTryBot is an edge proxy in front of its users' sites: it logs every request, and passes, observes, diverts (to a "bot gym") or blocks it. A search is a WHERE expression in NiceTryBot's rule language. It is not SQL: NiceTryBot parses it and builds the query itself, so write only the grammar below.

            Answer with:
            - search: the search on one line: a WHERE expression, then GROUP BY and HAVING when the question is about what clients did across requests. "" when the question can't be written as a search at all.
            - period: the time window the question asks about: "1m", "1h", "2h", "4h", "6h", "8h", "16h", "24h", "36h", "48h", "3d", "7d", "14d", "21d" or "30d", picking the smallest that covers it ("today" is "24h", "this week" is "7d", "the last two weeks" is "14d", "this month" is "30d"). "" when it names none.
            - client: the page's client filter: use it, not the expression's client field, for a question about one kind of client. "spoofed" for clients that aren't the browser they claim to be: a user agent its TLS handshake or client hints contradict, like an iOS user agent on Chrome's TLS or a Chrome user agent on another browser's TLS ("spoofed", "fake browsers", "bots pretending to be Chrome", "lying user agents"). "outdated" for a Chrome or Firefox many versions behind the one people run now ("old browsers", "outdated Chrome", "old Firefox"); a Firefox ESR Mozilla still supports isn't outdated. Safari and other browsers are never outdated. To ask about one browser, add ua LIKE '%Firefox/%' or ua LIKE '%Chrome/%' to the expression. "" for any client.
            - view: "clients" when the question asks for a list of clients, IPs or fingerprints ("show me all IPs…", "which clients…", "who…", "top fingerprints…"); "paths" when it asks for each distinct path or URL once, with how often it was asked for ("unique requests", "distinct paths", "which URLs…", "top paths", "with counts"); "requests" when it asks for traffic or requests, even of clients a HAVING picks. The paths view lists the search's requests grouped by path, so de-duplicating paths needs no GROUP BY: GROUP BY is only ever ip or ja4.
            - note: one short sentence for the person when you left part of the question out because the language can't say it, or had to guess what they meant. "" otherwise. Don't restate the search; the page already shows it.

            # Grammar
            expr      := term {OR term}
            term      := factor {AND factor}
            factor    := NOT factor | ( expr ) | field predicate | 'text'
            predicate := op literal | [NOT] LIKE 'pattern' | [NOT] IN (literal, …) | IS [NOT] NULL
            op        := = | != | < | <= | > | >=
            search    := [expr] [GROUP BY subject {, subject}] [HAVING having]
            subject   := ip | ja4
            having    := hterm {OR hterm}          hterm := hfactor {AND hfactor}
            hfactor   := NOT hfactor | ( having ) | COUNT ( * | DISTINCT field ) [FILTER ( WHERE expr )] op number

            Keywords ignore case. Strings take single quotes, doubling a quote inside ('O''Brien'). Numbers are bare. < and > work only on number fields. LIKE ignores case: % matches any run of characters and _ one character. AND binds tighter than OR, so wrap OR groups in parentheses when you AND them with something else.

            A quoted string on its own ('digitalocean') is a text term: it matches that text in the path, the start of the IP, the edge tag, the user agent, the network name, or a name the team gave a client. Use a field when the question says which one; use a text term for a bare name or word that could be any of those.

            {$this->fieldReference()}
            - "My own traffic" is a separate toggle the language can't name; say so in the note when asked.

            # Hostnames
            The question comes with the team's hostnames. A team with many sends only some: every hostname the question names, then others, with how many hostnames sit under each parent domain. Use the exact hostname when the question names or describes one. When the list is partial, a hostname that isn't in it may still be the team's: use it as written, and don't call it misspelled. For a group of sites ("the preview sites"), match the parent domain: host LIKE '%.previews.example.net'.

            # Clients
            A client is an IP and a TLS fingerprint together. HAVING keeps the clients whose requests, among those the expression keeps over the page's period, pass it; the page then lists their requests, or the clients themselves. GROUP BY ip or GROUP BY ja4 makes a client one IP or one fingerprint instead; use it when the question says IPs or fingerprints. GROUP BY without HAVING just lists clients by how many requests they made.

            Use HAVING for anything a client did across requests: "more than 50 requests" is COUNT(*) > 50; "on more than 3 sites" is COUNT(DISTINCT host) > 3. FILTER counts only some of the requests, so "reached /thank-you but never the homepage" is COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1 AND COUNT(*) FILTER (WHERE path = '/') = 0. "Never" is an = 0 count. The expression before GROUP BY must keep every request HAVING needs to see: put the thank-you and homepage conditions in FILTERs, not in the expression, or the homepage visits are gone before they're counted.

            # The current search
            The question may come with the current search and client filter. When the question reads as a change to them ("only…", "also…", "but not…", "exclude…", "and from…", "without…"), edit them and answer with the whole new expression and the client filter to keep. Otherwise write a fresh expression, answer client "" unless the question asks about clients, and ignore the current ones.

            # Examples
            {$examples}
            PROMPT;
    }

    /**
     * The question, with what Claude needs to place it.
     *
     * @param  array{hostnames: list<string>, total: int, groups: array<string, int>}  $hostnames
     */
    private function prompt(string $question, string $current, string $client, array $hostnames): string
    {
        $parts = $this->hostnameParts($hostnames);

        if ($current !== '') {
            $parts[] = "<current_search>\n{$current}\n</current_search>";
        }

        if ($client !== '') {
            $parts[] = "<current_client_filter>{$client}</current_client_filter>";
        }

        $parts[] = "<question>\n{$question}\n</question>";

        return implode("\n\n", $parts);
    }

    private function correction(string $search, RuleScriptSyntaxError $error): string
    {
        return "Your last answer was:\n<search>\n{$search}\n</search>\nIt doesn't parse: {$error->getMessage()} (line {$error->scriptLine}, column {$error->scriptColumn}). Answer again with a corrected search.";
    }

    /**
     * Ask Claude, and read its answer.
     *
     * @return array{search: string, period: string, client: string, view: string, note: ?string}
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

        if (($message['stop_reason'] ?? null) === 'max_tokens' || ! is_array($answer) || ! is_string($answer['search'] ?? null)) {
            throw new AnthropicException(__('Claude’s answer didn’t come back in the shape a search needs. Try again.'));
        }

        return [
            'search' => trim($answer['search']),
            'period' => is_string($answer['period'] ?? null) ? $answer['period'] : '',
            'client' => in_array($answer['client'] ?? null, ['spoofed', 'outdated'], true) ? $answer['client'] : '',
            'view' => in_array($answer['view'] ?? null, ['clients', 'paths'], true) ? $answer['view'] : 'requests',
            'note' => is_string($answer['note'] ?? null) && trim($answer['note']) !== '' ? trim($answer['note']) : null,
        ];
    }

    /**
     * @param  array{search: string, period: string, client: string, view: string, note: ?string}  $answer
     */
    private function translated(array $answer): TranslatedSearch
    {
        return new TranslatedSearch($answer['search'], TrafficPeriod::tryFrom($answer['period']), $answer['note'], $answer['client'], $answer['view']);
    }

    private function syntaxError(string $search): ?RuleScriptSyntaxError
    {
        if ($search === '') {
            return null;
        }

        try {
            (new RuleScriptParser($search))->parseSearch();

            return null;
        } catch (RuleScriptSyntaxError $error) {
            return $error;
        }
    }
}
