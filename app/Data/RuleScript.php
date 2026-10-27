<?php

namespace App\Data;

use App\Enums\RuleAction;
use App\Enums\RuleField;
use App\Enums\RuleSubject;
use App\Support\RuleFieldDefinition;
use Illuminate\Support\Number;

/**
 * A parsed rule script: what to do, to whom, for how long, and when.
 *
 *     BAN ip, ja4 FOR 30 DAYS
 *     WHERE category = 'probe'
 *
 *     BAN ip FOR 1 HOUR
 *     WHERE status = 404
 *     WITHIN 10 MINUTES
 *     HAVING COUNT(*) >= 2 AND COUNT(DISTINCT host) >= 2
 *
 * The subjects are also the grouping key, as in SQL's GROUP BY: HAVING is tested per subject, over
 * the requests that pass WHERE within the window. UNTIL lifts a subject's verdict early, as soon as it
 * makes a request that passes the UNTIL expression, e.g. a successful sign-in:
 *
 *     TAG ip, ja4 AS 'failed-login' FOR 1 DAY
 *     WHERE path = '/login' AND method = 'POST' AND status = 422
 *     WITHIN 10 MINUTES HAVING COUNT(*) >= 3
 *     UNTIL method = 'GET' AND path = '/dashboard' AND status = 200
 * Expressions are kept as small array nodes:
 * and/or/not, compare, like, in and null for WHERE; and/or/not and aggregate for HAVING.
 */
readonly class RuleScript
{
    /**
     * @param  list<RuleSubject>  $subjects
     * @param  int|null  $durationSeconds  Null means forever.
     * @param  array<string, mixed>|null  $where
     * @param  array<string, mixed>|null  $having
     * @param  array<string, mixed>|null  $until
     */
    public function __construct(
        public RuleAction $action,
        public array $subjects,
        public ?string $tag,
        public ?int $durationSeconds,
        public ?array $where,
        public ?int $windowSeconds,
        public ?array $having,
        public ?array $until = null,
    ) {
        //
    }

    /**
     * Parse a script.
     *
     * @throws RuleScriptSyntaxError
     */
    public static function parse(string $source): self
    {
        return (new RuleScriptParser($source))->parse();
    }

    /**
     * Determine if the rule counts requests per subject over a window instead of acting on each one.
     */
    public function isAggregate(): bool
    {
        return $this->having !== null;
    }

    /**
     * Determine if the rule can be decided from one request as it happens: no counting over a window,
     * and nothing in WHERE that's only known once the site has answered. The edge checks such rules
     * live, so their verdict lands on the very request that earns it.
     */
    public function isLive(): bool
    {
        if ($this->having !== null) {
            return false;
        }

        foreach ($this->fields() as $field) {
            if (! $field->isLive()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get the script that finds requests lifting a verdict: the UNTIL expression as its WHERE.
     */
    public function untilScript(): ?self
    {
        return $this->until === null ? null : new self($this->action, $this->subjects, $this->tag, $this->durationSeconds, $this->until, null, null);
    }

    /**
     * Get every field the script reads, so a query can select or join what it needs.
     *
     * @return list<RuleFieldDefinition>
     */
    public function fields(): array
    {
        return self::fieldsIn($this->where, $this->having);
    }

    /**
     * Get every field some expressions read (a WHERE, a HAVING with its FILTERs and DISTINCTs), each
     * once.
     *
     * @param  array<string, mixed>|null  ...$nodes
     * @return list<RuleFieldDefinition>
     */
    public static function fieldsIn(?array ...$nodes): array
    {
        $fields = [];
        $collect = function (?array $node) use (&$collect, &$fields) {
            if ($node === null) {
                return;
            }

            if (isset($node['field'])) {
                $fields[$node['field']->value] = $node['field'];
            }

            if (isset($node['distinct'])) {
                $fields[$node['distinct']->value] = $node['distinct'];
            }

            foreach (['left', 'right', 'expr', 'filter'] as $child) {
                $collect($node[$child] ?? null);
            }
        };

        foreach ($nodes as $node) {
            $collect($node);
        }

        return array_values($fields);
    }

    /**
     * Say what the rule does in one plain-English sentence.
     */
    public function describe(): string
    {
        $subjects = implode(__(' and '), array_map(fn (RuleSubject $subject) => $subject->label(), $this->subjects));
        $verb = $this->action === RuleAction::Ban
            ? __('Ban the :subjects', ['subjects' => $subjects])
            : __('Tag the :subjects as “:tag”', ['subjects' => $subjects, 'tag' => $this->tag]);

        $sentence = $verb.' '.self::describeDuration($this->durationSeconds);

        if ($this->having !== null) {
            $sentence .= ' '.__('when, within :window, its requests:where reach :having', [
                'window' => self::describeSpan($this->windowSeconds),
                'where' => $this->where !== null ? ' '.__('where :where', ['where' => self::describeExpression($this->where)]) : '',
                'having' => self::describeExpression($this->having),
            ]);
        } else {
            $sentence .= $this->where !== null
                ? ' '.__('as soon as it makes a request where :where', ['where' => self::describeExpression($this->where)])
                : ' '.__('as soon as it makes any request');
        }

        $sentence .= '.';

        if ($this->until !== null) {
            $sentence .= ' '.__('It’s lifted as soon as the subject makes a request where :until.', ['until' => self::describeExpression($this->until)]);
        }

        return $sentence;
    }

    /**
     * Say an expression in plain English, e.g. "status is 404 and path starts with “/wp-”".
     *
     * @param  array<string, mixed>  $node
     */
    public static function describeExpression(array $node): string
    {
        return match ($node['kind']) {
            'and' => self::describeExpression($node['left']).' '.__('and').' '.self::describeExpression($node['right']),
            'or' => '('.self::describeExpression($node['left']).' '.__('or').' '.self::describeExpression($node['right']).')',
            'not' => __('not (:expr)', ['expr' => self::describeExpression($node['expr'])]),
            'compare' => self::describeComparison($node),
            'like' => self::describeLike($node),
            'in' => __(':field is :not one of :values', [
                'field' => $node['field']->englishName(),
                'not' => $node['negated'] ? __('not') : '',
                'values' => implode(', ', array_map(fn ($value) => self::quote($value), $node['values'])),
            ]),
            'cidr' => __('IP is in :range', ['range' => self::quote($node['range'])]),
            'text' => __('anything mentions “:text”', ['text' => $node['text']]),
            'null' => __(':field is :state', ['field' => $node['field']->englishName(), 'state' => $node['negated'] ? __('present') : __('missing')]),
            'aggregate' => self::describeAggregate($node),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function describeComparison(array $node): string
    {
        $field = $node['field'];
        $value = $field === RuleField::Category && is_string($node['value']) && ! str_contains($node['value'], '/')
            ? $node['value'].'/*'
            : $node['value'];

        $operator = match ($node['operator']) {
            '=' => __('is'),
            '!=' => __('is not'),
            '<' => __('is under'),
            '<=' => __('is at most'),
            '>' => __('is over'),
            '>=' => __('is at least'),
        };

        return $field->englishName().' '.$operator.' '.self::quote($value);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function describeLike(array $node): string
    {
        $pattern = $node['pattern'];
        $inner = trim($pattern, '%');
        $isContains = str_starts_with($pattern, '%') && str_ends_with($pattern, '%') && ! str_contains($inner, '%');

        return match (true) {
            $isContains => __(':field :verb “:text”', ['field' => $node['field']->englishName(), 'verb' => $node['negated'] ? __('doesn\'t contain') : __('contains'), 'text' => $inner]),
            str_ends_with($pattern, '%') && ! str_contains(rtrim($pattern, '%'), '%') => __(':field :verb “:text”', ['field' => $node['field']->englishName(), 'verb' => $node['negated'] ? __('doesn\'t start with') : __('starts with'), 'text' => rtrim($pattern, '%')]),
            default => __(':field :verb “:pattern”', ['field' => $node['field']->englishName(), 'verb' => $node['negated'] ? __('doesn\'t match') : __('matches'), 'pattern' => $pattern]),
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private static function describeAggregate(array $node): string
    {
        $count = $node['value'];
        $quantity = match ($node['operator']) {
            '>=' => __('at least :count', ['count' => $count]),
            '>' => __('more than :count', ['count' => $count]),
            '<=' => __('at most :count', ['count' => $count]),
            '<' => __('fewer than :count', ['count' => $count]),
            '=' => __('exactly :count', ['count' => $count]),
            '!=' => __('other than :count', ['count' => $count]),
        };

        if ($node['operator'] === '=' && $count === 0) {
            $quantity = __('no');
        }

        $counted = $node['distinct'] === null
            ? trans_choice(':quantity request|:quantity requests', $count, ['quantity' => $quantity])
            : __(':quantity different :things', ['quantity' => $quantity, 'things' => $node['distinct']->englishName().($count === 1 ? '' : 's')]);

        return ($node['filter'] ?? null) === null
            ? $counted
            : __(':counted where :filter', ['counted' => $counted, 'filter' => self::describeExpression($node['filter'])]);
    }

    private static function describeDuration(?int $seconds): string
    {
        return $seconds === null ? __('forever') : __('for :span', ['span' => self::describeSpan($seconds)]);
    }

    private static function describeSpan(?int $seconds): string
    {
        foreach ([604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute', 1 => 'second'] as $size => $unit) {
            if ($seconds !== null && $seconds % $size === 0) {
                $amount = intdiv($seconds, $size);

                return Number::format($amount).' '.($amount === 1 ? $unit : $unit.'s');
            }
        }

        return (string) $seconds;
    }

    private static function quote(int|string $value): string
    {
        return is_int($value) ? (string) $value : '“'.$value.'”';
    }
}
