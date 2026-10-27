<?php

namespace App\Data;

use App\Enums\RuleAction;
use App\Enums\RuleField;
use App\Enums\RuleSubject;
use App\Support\RuleFieldDefinition;
use App\Support\RuleFields;
use InvalidArgumentException;

/**
 * Turns rule script text into a RuleScript. Recursive descent over this grammar (keywords ignore case;
 * `--` starts a comment):
 *
 *     script    := action subjects ['AS' string] 'FOR' duration ['WHERE' expr] ['WITHIN' span 'HAVING' having] ['UNTIL' expr]
 *     action    := 'BAN' | 'TAG'
 *     subjects  := ('ip' | 'ja4') {',' ('ip' | 'ja4')}
 *     duration  := number unit | 'FOREVER'          span := number unit
 *     expr      := term {'OR' term}                  term := factor {'AND' factor}
 *     factor    := 'NOT' factor | '(' expr ')' | field predicate
 *     predicate := op literal | ['NOT'] 'LIKE' string | ['NOT'] 'IN' '(' literal {',' literal} ')' | 'IS' ['NOT'] 'NULL'
 *     having    := hterm {'OR' hterm}               hterm := hfactor {'AND' hfactor}
 *     hfactor   := 'NOT' hfactor | '(' having ')' | count op number
 *     count     := 'COUNT' '(' ('*' | 'DISTINCT' field) ')' ['FILTER' '(' 'WHERE' expr ')']
 *
 * FILTER counts only some of the requests, so HAVING can say what a client did and never did:
 * COUNT(*) FILTER (WHERE path = '/') = 0.
 *
 * An Activity search (parseSearch) is a WHERE expression, where a quoted string may also stand alone as
 * a text term ('digitalocean' AND status = 404), then optionally the clients to count and what they
 * must have done:
 *
 *     search    := ['WHERE'] [expr] ['GROUP' 'BY' subjects] ['HAVING' having]
 */
class RuleScriptParser
{
    /**
     * The longest window HAVING may count over.
     */
    public const int MAX_WINDOW_SECONDS = 86400;

    /**
     * Seconds per duration unit.
     *
     * @var array<string, int>
     */
    private const array UNITS = ['SECOND' => 1, 'MINUTE' => 60, 'HOUR' => 3600, 'DAY' => 86400, 'WEEK' => 604800];

    /**
     * @var list<array{type: string, value: string, offset: int}>
     */
    private array $tokens = [];

    private int $position = 0;

    /**
     * Whether a quoted string can stand alone as a text term, as it can in a search.
     */
    private bool $allowsText = false;

    /**
     * How many FILTER clauses HAVING has had so far, which numbers each one.
     */
    private int $filterCount = 0;

    public function __construct(private string $source)
    {
        //
    }

    /**
     * @throws RuleScriptSyntaxError
     */
    public function parse(): RuleScript
    {
        $this->tokens = $this->tokenize();
        $this->position = 0;
        $this->filterCount = 0;

        if ($this->peek()['type'] === 'eof') {
            $this->fail(__('Start with BAN or TAG, e.g. BAN ip FOR 1 HOUR WHERE status = 404.'));
        }

        $action = match ($this->keyword()) {
            'BAN' => RuleAction::Ban,
            'TAG' => RuleAction::Tag,
            default => $this->fail(__('A rule starts with BAN or TAG.')),
        };
        $this->advance();

        $subjects = $this->subjects();
        $tag = null;

        if ($this->acceptKeyword('AS')) {
            $tag = $this->expect('string', __('AS takes the tag name in quotes, e.g. AS \'ua-tls-mismatch\'.'))['value'];
        } elseif ($action === RuleAction::Tag) {
            $this->fail(__('TAG needs a name: TAG ip AS \'name\' FOR 1 DAY.'));
        }

        $this->expectKeyword('FOR', __('Say how long the :action lasts, e.g. FOR 30 DAYS or FOREVER.', ['action' => strtolower($action->label())]));
        $duration = $this->acceptKeyword('FOREVER') ? null : $this->span();

        $where = $this->acceptKeyword('WHERE') ? $this->expression() : null;
        $window = null;
        $having = null;

        if ($this->acceptKeyword('WITHIN')) {
            $window = $this->span();

            if ($window > self::MAX_WINDOW_SECONDS) {
                $this->fail(__('WITHIN can be at most 24 hours.'), back: 2);
            }

            $this->expectKeyword('HAVING', __('WITHIN is followed by HAVING, e.g. HAVING COUNT(*) >= 5.'));
            $having = $this->having();
        } elseif ($this->keyword() === 'HAVING') {
            $this->fail(__('HAVING needs a window first, e.g. WITHIN 10 MINUTES HAVING COUNT(*) >= 2.'));
        }

        $until = $this->acceptKeyword('UNTIL') ? $this->expression() : null;

        if ($this->peek()['type'] !== 'eof') {
            $this->fail(__('Unexpected “:token”. Clauses go in order: FOR, WHERE, WITHIN … HAVING, UNTIL.', ['token' => $this->peek()['value']]));
        }

        return new RuleScript($action, $subjects, $tag, $duration, $where, $window, $having, $until);
    }

    /**
     * Parse a WHERE expression on its own, as the Activity search writes it. The WHERE keyword is
     * optional.
     *
     * @return array<string, mixed>
     *
     * @throws RuleScriptSyntaxError
     */
    public function parseExpression(): array
    {
        $this->tokens = $this->tokenize();
        $this->position = 0;
        $this->allowsText = true;

        $this->acceptKeyword('WHERE');

        if ($this->peek()['type'] === 'eof') {
            $this->fail(__('Write a condition, e.g. status = 404 AND path LIKE \'/wp-%\'.'));
        }

        $expression = $this->expression();

        if ($this->peek()['type'] !== 'eof') {
            $this->fail(__('Unexpected “:token”. Join conditions with AND or OR.', ['token' => $this->peek()['value']]));
        }

        return $expression;
    }

    /**
     * Parse an Activity search: a WHERE expression (the WHERE keyword is optional), then optionally
     * GROUP BY the clients to count (ip, ja4 or both) and HAVING over each one's requests. Either part
     * may stand without the expression, as in GROUP BY ip HAVING COUNT(*) > 100.
     *
     * @return array{where: ?array<string, mixed>, groupBy: list<RuleSubject>, having: ?array<string, mixed>}
     *
     * @throws RuleScriptSyntaxError
     */
    public function parseSearch(): array
    {
        $this->tokens = $this->tokenize();
        $this->position = 0;
        $this->allowsText = true;
        $this->filterCount = 0;

        $this->acceptKeyword('WHERE');

        if ($this->peek()['type'] === 'eof') {
            $this->fail(__('Write a condition, e.g. status = 404 AND path LIKE \'/wp-%\'.'));
        }

        $where = in_array($this->keyword(), ['GROUP', 'HAVING'], true) ? null : $this->expression();
        $groupBy = [];

        if ($this->acceptKeyword('GROUP')) {
            $this->expectKeyword('BY', __('GROUP is followed by BY, e.g. GROUP BY ip.'));
            $groupBy = $this->subjects(grouping: true);
        }

        $having = $this->acceptKeyword('HAVING') ? $this->having() : null;

        if ($this->peek()['type'] !== 'eof') {
            $this->fail(__('Unexpected “:token”. Join conditions with AND or OR, then GROUP BY and HAVING.', ['token' => $this->peek()['value']]));
        }

        return ['where' => $where, 'groupBy' => $groupBy, 'having' => $having];
    }

    /**
     * Write a rule as the Activity search for the traffic it acts on: its WHERE and HAVING as written,
     * grouped the way the rule picks its subjects (an IP and fingerprint together needs no GROUP BY).
     * What a search can't say is left out: the window HAVING counts over, since a search counts over
     * the page's period, and UNTIL.
     *
     * @throws RuleScriptSyntaxError
     */
    public function activitySearch(): string
    {
        $script = $this->parse();

        $keywordAt = function (string $keyword): ?int {
            foreach ($this->tokens as $index => $token) {
                if ($token['type'] === 'word' && strtoupper($token['value']) === $keyword) {
                    return $index;
                }
            }

            return null;
        };
        $slice = fn (int $after, int $before) => trim(substr($this->source, $this->tokens[$after]['offset'] + strlen($this->tokens[$after]['value']), $this->tokens[$before]['offset'] - $this->tokens[$after]['offset'] - strlen($this->tokens[$after]['value'])));

        $end = count($this->tokens) - 1;
        $until = $keywordAt('UNTIL') ?? $end;
        $parts = [];

        // A FILTER inside HAVING has its own WHERE, but only after the rule's own, so the first is the rule's.
        if ($script->where !== null) {
            $parts[] = $slice($keywordAt('WHERE'), $keywordAt('WITHIN') ?? $until);
        }

        $subjects = array_map(fn (RuleSubject $subject) => $subject->value, $script->subjects);

        if (count(array_unique($subjects)) === 1) {
            $parts[] = 'GROUP BY '.$subjects[0];
        }

        if ($script->having !== null) {
            $parts[] = 'HAVING '.$slice($keywordAt('HAVING'), $until);
        }

        return implode("\n", $parts);
    }

    /**
     * Get the source's tokens, without spaces and comments, ending with an eof token.
     *
     * @return list<array{type: string, value: string, offset: int}>
     *
     * @throws RuleScriptSyntaxError
     */
    public function tokens(): array
    {
        return $this->tokenize();
    }

    /**
     * @return list<RuleSubject>
     */
    private function subjects(bool $grouping = false): array
    {
        $subjects = [];

        do {
            $token = $this->expect('word', $grouping
                ? __('Say what to group by: ip, ja4, or both (ip, ja4).')
                : __('Say what to :action: ip, ja4, or both (ip, ja4).', ['action' => 'ban']));
            $subject = RuleSubject::tryFrom(strtolower($token['value']))
                ?? $this->fail($grouping
                    ? __('You can group by ip or ja4, not “:value”.', ['value' => $token['value']])
                    : __('You can ban or tag ip or ja4, not “:value”.', ['value' => $token['value']]), back: 1);

            $subjects[$subject->value] = $subject;
        } while ($this->accept('comma'));

        return array_values($subjects);
    }

    private function span(): int
    {
        $number = $this->expect('number', __('Expected a number, e.g. 30 DAYS or 10 MINUTES.'));
        $unit = rtrim($this->keyword() ?? '', 'S');

        if (! isset(self::UNITS[$unit]) || (int) $number['value'] < 1) {
            $this->fail(__('Use a unit: SECONDS, MINUTES, HOURS, DAYS or WEEKS.'));
        }

        $this->advance();

        return (int) $number['value'] * self::UNITS[$unit];
    }

    /**
     * @return array<string, mixed>
     */
    private function expression(): array
    {
        $node = $this->term();

        while ($this->acceptKeyword('OR')) {
            $node = ['kind' => 'or', 'left' => $node, 'right' => $this->term()];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function term(): array
    {
        $node = $this->factor();

        while ($this->acceptKeyword('AND')) {
            $node = ['kind' => 'and', 'left' => $node, 'right' => $this->factor()];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function factor(): array
    {
        if ($this->acceptKeyword('NOT')) {
            return ['kind' => 'not', 'expr' => $this->factor()];
        }

        if ($this->accept('lparen')) {
            $node = $this->expression();
            $this->expect('rparen', __('Missing a closing parenthesis.'));

            return $node;
        }

        if ($this->keyword() === 'COUNT') {
            $this->fail(__('COUNT(…) belongs in HAVING, after a WITHIN window.'));
        }

        if ($this->allowsText && $this->peek()['type'] === 'string') {
            $text = $this->peek()['value'];
            $this->advance();

            return ['kind' => 'text', 'text' => $text];
        }

        $field = $this->field();

        if ($this->acceptKeyword('IS')) {
            if ($field === RuleField::Client) {
                $this->fail(__('client is never null; compare it with = or IN, e.g. client = \'spoofed\'.'), back: 1);
            }

            $negated = $this->acceptKeyword('NOT');
            $this->expectKeyword('NULL', __('Use IS NULL or IS NOT NULL.'));

            return ['kind' => 'null', 'field' => $field, 'negated' => $negated];
        }

        $negated = $this->acceptKeyword('NOT');

        if ($this->acceptKeyword('LIKE')) {
            $pattern = $this->expect('string', __('LIKE takes a quoted pattern, e.g. LIKE \'%Chrome/%\'.'));

            if (! $field->isText() && $field !== RuleField::Category) {
                $this->fail(__(':field is not text; compare it with = or IN instead.', ['field' => $field->value]), back: 2);
            }

            return ['kind' => 'like', 'field' => $field, 'pattern' => $pattern['value'], 'negated' => $negated];
        }

        if ($this->acceptKeyword('IN')) {
            $this->expect('lparen', __('IN takes a list in parentheses, e.g. IN (403, 404).'));
            $values = [];

            do {
                $values[] = $this->literal($field);
            } while ($this->accept('comma'));

            $this->expect('rparen', __('Missing a closing parenthesis after the IN list.'));

            return $field === RuleField::Ip
                ? $this->ipNode($values, $negated)
                : ['kind' => 'in', 'field' => $field, 'values' => $values, 'negated' => $negated];
        }

        if ($negated) {
            $this->fail(__('NOT here must be followed by LIKE or IN.'));
        }

        $operator = $this->operator();

        if (in_array($operator, ['<', '<=', '>', '>='], true) && ! $field->isNumeric()) {
            $this->fail(__(':field can only be compared with = or !=.', ['field' => $field->value]), back: 1);
        }

        $value = $this->literal($field);

        if ($field === RuleField::Ip && str_contains((string) $value, '/') && in_array($operator, ['=', '!='], true)) {
            return $this->ipNode([$value], $operator === '!=');
        }

        return ['kind' => 'compare', 'field' => $field, 'operator' => $operator, 'value' => $value];
    }

    /**
     * Build the node for IP values, where any value with a slash is a range ("66.249.64.0/19"): the
     * exact addresses as one IN, each range as its own node, any of them matching.
     *
     * @param  list<int|string>  $values
     * @return array<string, mixed>
     */
    private function ipNode(array $values, bool $negated): array
    {
        $exact = array_values(array_filter($values, fn (int|string $value) => ! str_contains((string) $value, '/')));
        $nodes = array_map(fn (int|string $range) => ['kind' => 'cidr', 'range' => (string) $range], array_values(array_diff($values, $exact)));

        if ($exact !== []) {
            array_unshift($nodes, ['kind' => 'in', 'field' => RuleField::Ip, 'values' => $exact, 'negated' => false]);
        }

        $node = array_reduce(array_slice($nodes, 1), fn (array $left, array $right) => ['kind' => 'or', 'left' => $left, 'right' => $right], $nodes[0]);

        return $negated ? ['kind' => 'not', 'expr' => $node] : $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function having(): array
    {
        $node = $this->havingTerm();

        while ($this->acceptKeyword('OR')) {
            $node = ['kind' => 'or', 'left' => $node, 'right' => $this->havingTerm()];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function havingTerm(): array
    {
        $node = $this->havingFactor();

        while ($this->acceptKeyword('AND')) {
            $node = ['kind' => 'and', 'left' => $node, 'right' => $this->havingFactor()];
        }

        return $node;
    }

    /**
     * @return array<string, mixed>
     */
    private function havingFactor(): array
    {
        if ($this->acceptKeyword('NOT')) {
            return ['kind' => 'not', 'expr' => $this->havingFactor()];
        }

        if ($this->accept('lparen')) {
            $node = $this->having();
            $this->expect('rparen', __('Missing a closing parenthesis.'));

            return $node;
        }

        $this->expectKeyword('COUNT', __('HAVING compares counts: COUNT(*) or COUNT(DISTINCT host).'));
        $this->expect('lparen', __('Write COUNT(*) or COUNT(DISTINCT field).'));
        $distinct = null;

        if (! $this->accept('star')) {
            $this->expectKeyword('DISTINCT', __('Write COUNT(*) or COUNT(DISTINCT field).'));
            $distinct = $this->field();

            if ($distinct === RuleField::Client) {
                $this->fail(__('client can\'t be counted; count ip or ja4 instead.'), back: 1);
            }
        }

        $this->expect('rparen', __('Missing the closing parenthesis of COUNT(…).'));
        $filter = null;

        if ($this->acceptKeyword('FILTER')) {
            $this->expect('lparen', __('FILTER takes a condition in parentheses: FILTER (WHERE path = \'/\').'));
            $this->expectKeyword('WHERE', __('Start the FILTER condition with WHERE: FILTER (WHERE path = \'/\').'));
            $filter = $this->expression();
            $this->expect('rparen', __('Missing the closing parenthesis of FILTER (…).'));
        }

        $operator = $this->operator();
        $value = $this->expect('number', __('Compare the count with a number, e.g. >= 5.'));

        return [
            'kind' => 'aggregate',
            'distinct' => $distinct,
            'filter' => $filter,
            'filter_index' => $filter === null ? null : $this->filterCount++,
            'operator' => $operator,
            'value' => (int) $value['value'],
        ];
    }

    /**
     * Read a field the script names: one of the core's, or one an installed add-on brings. A name
     * close to a field's is taken for a typo; any other is likely an add-on's that isn't installed.
     */
    private function field(): RuleFieldDefinition
    {
        $token = $this->expect('word', __('Expected a field such as status, path, category, ua or ja4.'));
        $name = strtolower($token['value']);
        $fields = app(RuleFields::class);

        if ($field = $fields->find($name)) {
            return $field;
        }

        $suggestion = collect($fields->names())->sortBy(fn (string $field) => levenshtein($name, $field))->first();

        if (levenshtein($name, $suggestion) <= max(2, intdiv(strlen($name), 3))) {
            $this->fail(__('Unknown field “:name”. Did you mean :suggestion?', ['name' => $token['value'], 'suggestion' => $suggestion]), back: 1);
        }

        $this->fail(__('Unknown field “:name”. If an add-on brings it, that add-on isn’t installed.', ['name' => $token['value']]), back: 1);
    }

    private function operator(): string
    {
        $token = $this->expect('operator', __('Expected a comparison: =, !=, <, <=, >, >=, LIKE, IN or IS NULL.'));

        return $token['value'] === '<>' ? '!=' : $token['value'];
    }

    /**
     * Read a literal for a field, checking it's a value the field can hold.
     */
    private function literal(RuleFieldDefinition $field): int|string
    {
        $token = $this->peek();

        if (! in_array($token['type'], ['string', 'number'], true)) {
            $this->fail(__('Expected a value: a number like 404 or text in quotes like \'probe\'.'));
        }

        $this->advance();
        $value = $token['type'] === 'number' ? (int) $token['value'] : $token['value'];

        try {
            $field->storedValues($value);
        } catch (InvalidArgumentException $exception) {
            $this->fail($exception->getMessage(), back: 1);
        }

        return $value;
    }

    /**
     * @return list<array{type: string, value: string, offset: int}>
     */
    private function tokenize(): array
    {
        $patterns = [
            'space' => '\s+',
            'comment' => '--[^\n]*',
            'string' => "'(?:[^'\\\\]|\\\\.|'')*'",
            'number' => '\d+',
            'operator' => '<>|!=|<=|>=|=|<|>',
            'lparen' => '\(',
            'rparen' => '\)',
            'comma' => ',',
            'star' => '\*',
            'word' => '[A-Za-z_][A-Za-z0-9_.]*',
        ];

        $tokens = [];
        $offset = 0;
        $length = strlen($this->source);

        while ($offset < $length) {
            foreach ($patterns as $type => $pattern) {
                if (preg_match('/\G(?:'.$pattern.')/A', $this->source, $match, 0, $offset)) {
                    if ($type === 'string') {
                        $value = str_replace(["''", "\\'"], "'", substr($match[0], 1, -1));
                        $tokens[] = ['type' => 'string', 'value' => $value, 'offset' => $offset];
                    } elseif (! in_array($type, ['space', 'comment'], true)) {
                        $tokens[] = ['type' => $type, 'value' => $match[0], 'offset' => $offset];
                    }

                    $offset += strlen($match[0]);

                    continue 2;
                }
            }

            $this->failAt($offset, $this->source[$offset] === "'"
                ? __('This quote is never closed.')
                : __('Unexpected character “:char”.', ['char' => $this->source[$offset]]));
        }

        $tokens[] = ['type' => 'eof', 'value' => '', 'offset' => $length];

        return $tokens;
    }

    /**
     * @return array{type: string, value: string, offset: int}
     */
    private function peek(): array
    {
        return $this->tokens[$this->position];
    }

    private function advance(): void
    {
        $this->position = min($this->position + 1, count($this->tokens) - 1);
    }

    private function keyword(): ?string
    {
        $token = $this->peek();

        return $token['type'] === 'word' ? strtoupper($token['value']) : null;
    }

    private function accept(string $type): bool
    {
        if ($this->peek()['type'] === $type) {
            $this->advance();

            return true;
        }

        return false;
    }

    private function acceptKeyword(string $keyword): bool
    {
        if ($this->keyword() === $keyword) {
            $this->advance();

            return true;
        }

        return false;
    }

    /**
     * @return array{type: string, value: string, offset: int}
     */
    private function expect(string $type, string $message): array
    {
        $token = $this->peek();

        if ($token['type'] !== $type) {
            $this->fail($message);
        }

        $this->advance();

        return $token;
    }

    private function expectKeyword(string $keyword, string $message): void
    {
        if (! $this->acceptKeyword($keyword)) {
            $this->fail($message);
        }
    }

    /**
     * Report an error at the current token, or one already consumed ($back tokens ago).
     */
    private function fail(string $message, int $back = 0): never
    {
        $this->failAt($this->tokens[max(0, $this->position - $back)]['offset'], $message);
    }

    private function failAt(int $offset, string $message): never
    {
        $before = substr($this->source, 0, $offset);
        $line = substr_count($before, "\n") + 1;
        $column = $offset - (int) strrpos("\n".$before, "\n") + 1;

        throw new RuleScriptSyntaxError($message, $line, $column);
    }
}
