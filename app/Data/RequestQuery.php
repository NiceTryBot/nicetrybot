<?php

namespace App\Data;

use App\Enums\RuleSubject;

/**
 * An Activity search: a WHERE expression in the rule language, e.g.
 *
 *     host = 'claims.example.com'
 *     AND status = 404
 *     AND category != 'asset'
 *
 * Its top-level AND clauses are what the page's filters switch on and off, so picking "404 only"
 * adds `status = 404` and picking "Any status" takes it out again. Plain text that isn't an expression
 * ("digitalocean", or a pasted IP) searches as a quoted text term would.
 *
 * It may go on to find clients by what they did across requests, the IP and TLS fingerprint together
 * unless GROUP BY says one of them, which the filters leave alone:
 *
 *     host = 'claims.example.com'
 *     GROUP BY ip
 *     HAVING COUNT(*) FILTER (WHERE path LIKE '%/thank-you') >= 1
 *        AND COUNT(*) FILTER (WHERE path = '/') = 0
 */
readonly class RequestQuery
{
    public function __construct(public string $source)
    {
        //
    }

    /**
     * Quote a value as a rule-language string, doubling any quote inside it.
     */
    public static function quote(string $value): string
    {
        return "'".str_replace("'", "''", $value)."'";
    }

    /**
     * Determine if there's nothing to search for.
     */
    public function isBlank(): bool
    {
        return trim($this->source) === '';
    }

    /**
     * Determine if the search parses as written, without falling back to plain text.
     */
    public function parses(): bool
    {
        try {
            (new RuleScriptParser($this->source))->parseSearch();

            return true;
        } catch (RuleScriptSyntaxError) {
            return false;
        }
    }

    /**
     * Parse the search: its WHERE expression (null when there's none), the subjects it groups clients
     * by (as written; empty when it doesn't say) and its HAVING.
     *
     * @return array{where: ?array<string, mixed>, groupBy: list<RuleSubject>, having: ?array<string, mixed>}
     *
     * @throws RuleScriptSyntaxError
     */
    public function parse(): array
    {
        if ($this->isBlank()) {
            return ['where' => null, 'groupBy' => [], 'having' => null];
        }

        try {
            return (new RuleScriptParser($this->source))->parseSearch();
        } catch (RuleScriptSyntaxError $error) {
            if ($this->isPlainText()) {
                return ['where' => ['kind' => 'text', 'text' => trim($this->source)], 'groupBy' => [], 'having' => null];
            }

            throw $error;
        }
    }

    /**
     * Parse the search's WHERE expression, or null when it has none.
     *
     * @return array<string, mixed>|null
     *
     * @throws RuleScriptSyntaxError
     */
    public function where(): ?array
    {
        return $this->parse()['where'];
    }

    /**
     * Get what makes one client of the search: the subjects GROUP BY names, or the IP and TLS
     * fingerprint together.
     *
     * @return list<RuleSubject>
     *
     * @throws RuleScriptSyntaxError
     */
    public function subjects(): array
    {
        return $this->parse()['groupBy'] ?: [RuleSubject::Ip, RuleSubject::Ja4];
    }

    /**
     * Say what the search matches in one plain-English sentence.
     *
     * @throws RuleScriptSyntaxError
     */
    public function describe(): string
    {
        $search = $this->parse();
        $where = $search['where'] === null ? null : RuleScript::describeExpression($search['where']);
        $having = $search['having'] === null ? null : RuleScript::describeExpression($search['having']);
        $clients = self::describeSubjects($this->subjects());

        return match (true) {
            $having !== null && $where !== null => __('Requests where :where, from :clients with :having.', ['where' => $where, 'clients' => $clients, 'having' => $having]),
            $having !== null => __('Requests from :clients with :having.', ['clients' => $clients, 'having' => $having]),
            $search['groupBy'] !== [] && $where !== null => __('Requests where :where, by :clients.', ['where' => $where, 'clients' => $clients]),
            $search['groupBy'] !== [] => __('Every request, by :clients.', ['clients' => $clients]),
            $where !== null => __('Requests where :where.', ['where' => $where]),
            default => __('Every request.'),
        };
    }

    /**
     * Say what one client is when grouped by these subjects, in the plural.
     *
     * @param  list<RuleSubject>  $subjects
     */
    public static function describeSubjects(array $subjects): string
    {
        return match ($subjects) {
            [RuleSubject::Ip] => __('IPs'),
            [RuleSubject::Ja4] => __('TLS fingerprints'),
            default => __('clients (an IP and TLS fingerprint together)'),
        };
    }

    /**
     * Get the GROUP BY and HAVING that follow the search's WHERE, as written, or ''. The filters keep it
     * as it is.
     */
    public function tail(): string
    {
        $offset = $this->tailOffset();

        return $offset === null ? '' : trim(substr($this->source, $offset));
    }

    /**
     * Write the search as a rule that tags each client it finds, for the Rules editor to start from.
     * HAVING counts over a window, which can be at most a day long.
     *
     * @param  list<string>  $comments  Lines to explain the rule with, put first as comments.
     *
     * @throws RuleScriptSyntaxError
     */
    public function toRule(string $tag, int $windowSeconds, array $comments = []): string
    {
        $search = $this->parse();
        $where = trim((string) preg_replace('/^\s*WHERE\b/i', '', $this->head()));
        $having = $search['having'] === null ? '' : trim((string) preg_replace('/^.*?\bHAVING\b/is', '', $this->tail()));
        $window = min(max($windowSeconds, 60), RuleScriptParser::MAX_WINDOW_SECONDS);
        $span = $window % 3600 === 0 ? ($window / 3600).' '.($window === 3600 ? 'HOUR' : 'HOURS') : intdiv($window, 60).' '.($window === 60 ? 'MINUTE' : 'MINUTES');

        return implode("\n", array_filter([
            ...array_map(fn (string $comment) => '-- '.$comment, $comments),
            'TAG '.implode(', ', array_map(fn (RuleSubject $subject) => $subject->value, $this->subjects())).' AS '.self::quote($tag).' FOR 1 DAY',
            $where === '' ? null : 'WHERE '.$where,
            $having === '' ? null : 'WITHIN '.$span,
            $having === '' ? null : 'HAVING '.$having,
        ]));
    }

    /**
     * Get the search's top-level AND clauses, each as written. A search joined by a top-level OR is
     * one clause, in parentheses so another can be added beside it. GROUP BY and HAVING aren't clauses.
     *
     * @return list<string>
     */
    public function clauses(): array
    {
        if ($this->isBlank()) {
            return [];
        }

        if ($this->isPlainText() && ! $this->parses()) {
            return [self::quote(trim($this->source))];
        }

        return self::whereClauses($this->head());
    }

    /**
     * Split a WHERE expression into its top-level AND clauses.
     *
     * @return list<string>
     */
    private static function whereClauses(string $source): array
    {
        if (trim($source) === '') {
            return [];
        }

        try {
            $tokens = (new RuleScriptParser($source))->tokens();
        } catch (RuleScriptSyntaxError) {
            return [trim($source)];
        }

        $clauses = [];
        $depth = 0;
        $start = null;
        $end = 0;

        foreach ($tokens as $token) {
            $keyword = $token['type'] === 'word' ? strtoupper($token['value']) : null;

            if ($depth === 0 && $keyword === 'OR') {
                return ['('.trim($source).')'];
            }

            if ($token['type'] === 'eof' || ($depth === 0 && $keyword === 'AND')) {
                if ($start !== null) {
                    $clauses[] = substr($source, $start, $end - $start);
                }

                $start = null;

                continue;
            }

            if ($depth === 0 && $start === null && $keyword === 'WHERE') {
                continue;
            }

            $depth += match ($token['type']) {
                'lparen' => 1,
                'rparen' => -1,
                default => 0,
            };
            $start ??= $token['offset'];
            $end = $token['offset'] + self::tokenLength($source, $token);
        }

        return $clauses;
    }

    /**
     * Determine if the search has a clause, however it's spaced or capitalized.
     */
    public function has(string $clause): bool
    {
        $signature = self::signature($clause);

        return collect($this->clauses())->contains(fn (string $existing) => self::signature($existing) === $signature);
    }

    /**
     * Get the search with a clause added at the end, unless it's already there.
     */
    public function with(string $clause): self
    {
        return $this->has($clause) ? $this : self::fromClauses([...$this->clauses(), $clause], $this->tail());
    }

    /**
     * Get the search without any of these clauses.
     *
     * @param  list<string>  $clauses
     */
    public function without(array $clauses): self
    {
        $signatures = array_map(fn (string $clause) => self::signature($clause), $clauses);
        $kept = array_values(array_filter($this->clauses(), fn (string $existing) => ! in_array(self::signature($existing), $signatures, true)));

        return $kept === $this->clauses() ? $this : self::fromClauses($kept, $this->tail());
    }

    /**
     * Get the search with a clause switched on or off.
     */
    public function toggled(string $clause): self
    {
        return $this->has($clause) ? $this->without([$clause]) : $this->with($clause);
    }

    /**
     * Get the search with one of a set of choices picked (the others taken out), or none of them.
     *
     * @param  list<string>  $choices
     */
    public function choosing(array $choices, ?string $clause): self
    {
        $search = $this->without(array_values(array_diff($choices, $clause === null ? [] : [$clause])));

        return $clause === null ? $search : $search->with($clause);
    }

    /**
     * Get the first of these choices the search has, by key.
     *
     * @param  array<string, string>  $choices
     */
    public function chosen(array $choices): ?string
    {
        foreach ($choices as $key => $clause) {
            if ($this->has($clause)) {
                return (string) $key;
            }
        }

        return null;
    }

    /**
     * Join clauses into a search, one per line, followed by any GROUP BY and HAVING.
     *
     * @param  list<string>  $clauses
     */
    public static function fromClauses(array $clauses, string $tail = ''): self
    {
        return new self(trim(implode("\nAND ", $clauses)."\n".$tail));
    }

    /**
     * Reduce a clause to what it means for comparison: tokens with spacing, letter case and wrapping
     * parentheses dropped.
     */
    private static function signature(string $clause): string
    {
        try {
            $tokens = array_slice((new RuleScriptParser($clause))->tokens(), 0, -1);
        } catch (RuleScriptSyntaxError) {
            return trim($clause);
        }

        while (count($tokens) > 2 && $tokens[0]['type'] === 'lparen' && end($tokens)['type'] === 'rparen' && self::closesAtEnd($tokens)) {
            $tokens = array_slice($tokens, 1, -1);
        }

        return implode(' ', array_map(fn (array $token) => match ($token['type']) {
            'word' => strtolower($token['value']),
            'string' => self::quote($token['value']),
            'operator' => $token['value'] === '<>' ? '!=' : $token['value'],
            default => $token['value'],
        }, $tokens));
    }

    /**
     * Determine if the opening parenthesis of these tokens closes only at the last one.
     *
     * @param  list<array{type: string, value: string, offset: int}>  $tokens
     */
    private static function closesAtEnd(array $tokens): bool
    {
        $depth = 0;

        foreach ($tokens as $index => $token) {
            $depth += match ($token['type']) {
                'lparen' => 1,
                'rparen' => -1,
                default => 0,
            };

            if ($depth === 0 && $index < count($tokens) - 1) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get how much of the source a token covers. A string's value has its quotes and escapes removed,
     * so its length is measured from the source instead.
     *
     * @param  array{type: string, value: string, offset: int}  $token
     */
    private static function tokenLength(string $source, array $token): int
    {
        if ($token['type'] !== 'string') {
            return strlen($token['value']);
        }

        preg_match("/\\G'(?:[^'\\\\]|\\\\.|'')*'/", $source, $match, 0, $token['offset']);

        return strlen($match[0] ?? $token['value']);
    }

    /**
     * Get the search's WHERE part, before any GROUP BY or HAVING.
     */
    private function head(): string
    {
        $offset = $this->tailOffset();

        return $offset === null ? $this->source : substr($this->source, 0, $offset);
    }

    /**
     * Find where the search's GROUP BY or HAVING starts, outside any parentheses. Plain words that only
     * look like them ("having trouble") search as text instead.
     */
    private function tailOffset(): ?int
    {
        if ($this->isBlank() || ($this->isPlainText() && ! $this->parses())) {
            return null;
        }

        try {
            $tokens = (new RuleScriptParser($this->source))->tokens();
        } catch (RuleScriptSyntaxError) {
            return null;
        }

        $depth = 0;

        foreach ($tokens as $index => $token) {
            $keyword = $token['type'] === 'word' ? strtoupper($token['value']) : null;
            $next = $tokens[$index + 1] ?? null;

            if ($depth === 0 && ($keyword === 'HAVING' || ($keyword === 'GROUP' && $next['type'] === 'word' && strtoupper($next['value']) === 'BY'))) {
                return $token['offset'];
            }

            $depth += match ($token['type']) {
                'lparen' => 1,
                'rparen' => -1,
                default => 0,
            };
        }

        return null;
    }

    /**
     * Determine if the search reads as plain text rather than an expression: no quotes, comparisons
     * or parentheses.
     */
    private function isPlainText(): bool
    {
        return preg_match('/[\'"=<>!()]/', $this->source) === 0;
    }
}
