<?php

namespace App\Data;

use App\Enums\GymField;
use App\Enums\GymOperator;
use App\Models\Team;
use Illuminate\Support\Arr;

/**
 * Who enters a gym routine: its conditions, joined with AND (match all) or OR (match any). No
 * conditions means every visitor.
 */
readonly class GymEntry
{
    /**
     * @param  list<GymCondition>  $conditions
     */
    public function __construct(
        public array $conditions = [],
        public bool $matchAll = true,
    ) {
        //
    }

    /**
     * Build an entry from saved or edited condition rows, dropping rows that are incomplete.
     *
     * @param  array<int, array<string, mixed>>  $rows
     */
    public static function fromArray(array $rows, bool $matchAll = true): self
    {
        return new self(array_values(array_filter(array_map(fn (array $row) => GymCondition::fromArray($row), $rows))), $matchAll);
    }

    /**
     * Determine if every visitor enters.
     */
    public function isEveryone(): bool
    {
        return $this->conditions === [];
    }

    /**
     * Determine if a visitor enters.
     */
    public function admits(GymVisitor $visitor): bool
    {
        if ($this->isEveryone()) {
            return true;
        }

        foreach ($this->conditions as $condition) {
            if ($condition->matches($visitor) !== $this->matchAll) {
                return ! $this->matchAll;
            }
        }

        return $this->matchAll;
    }

    /**
     * Get path globs (`*` matches anything, lowercase) that every admitted visitor's path matches, so
     * the edge can ask about only those requests. Null when the entry doesn't pin the path down and the
     * edge must ask about every request.
     *
     * @return list<string>|null
     */
    public function edgePathGlobs(): ?array
    {
        $globs = fn (GymCondition $condition) => array_map(
            fn (string $value) => $condition->operator === GymOperator::StartsWith ? strtolower($value).'*' : '*'.strtolower($value).'*',
            $condition->values,
        );
        $isPathCondition = fn (GymCondition $condition) => $condition->field === GymField::Path
            && in_array($condition->operator, [GymOperator::StartsWith, GymOperator::Contains], true);

        $pathConditions = array_values(array_filter($this->conditions, $isPathCondition));

        return match (true) {
            $pathConditions === [] => null,
            // With AND, any one path condition has to hold, so its values are enough.
            $this->matchAll => $globs($pathConditions[0]),
            // With OR, only an entry made of nothing but path conditions narrows the paths.
            count($pathConditions) === count($this->conditions) => array_values(array_unique(array_merge(...array_map($globs, $pathConditions)))),
            default => null,
        };
    }

    /**
     * Get the rules one of whose verdicts every admitted visitor must hold, so the edge can ask about
     * only those clients' requests. An empty list means a verdict from any rule. Null when the entry
     * doesn't require a verdict.
     *
     * @return list<int>|null
     */
    public function edgeVerdictRuleIds(): ?array
    {
        $isVerdictCondition = fn (GymCondition $condition) => $condition->field === GymField::Verdict && $condition->operator === GymOperator::In;
        $ruleIds = fn (GymCondition $condition) => in_array(GymField::ANY_RULE, $condition->values, true) ? [] : array_map('intval', $condition->values);

        $verdictConditions = array_values(array_filter($this->conditions, $isVerdictCondition));

        return match (true) {
            $verdictConditions === [] => null,
            $this->matchAll => $ruleIds($verdictConditions[0]),
            count($verdictConditions) === count($this->conditions) => in_array([], $ids = array_map($ruleIds, $verdictConditions), true) ? [] : array_values(array_unique(array_merge(...$ids))),
            default => null,
        };
    }

    /**
     * Determine if the entry asks for a rule's verdicts, by naming the rule or "any rule", so the
     * rule's clients enter (when the entry's other conditions, if it needs all, hold too).
     */
    public function takesVerdictsFrom(int $ruleId): bool
    {
        foreach ($this->conditions as $condition) {
            if ($condition->field === GymField::Verdict && $condition->operator === GymOperator::In
                && array_intersect([GymField::ANY_RULE, (string) $ruleId], $condition->values) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the entry with a rule's verdicts added, so the rule's clients enter too and everyone who
     * entered before still does: the rule joins the entry's verdict condition, or becomes another way
     * in. Null when that can't be done without changing who else enters: every visitor enters already,
     * or the entry needs all of several conditions and none is about verdicts.
     */
    public function withVerdictsFrom(int $ruleId): ?self
    {
        if ($this->takesVerdictsFrom($ruleId)) {
            return $this;
        }

        foreach ($this->conditions as $index => $condition) {
            if ($condition->field === GymField::Verdict && $condition->operator === GymOperator::In) {
                $conditions = $this->conditions;
                $conditions[$index] = new GymCondition($condition->field, $condition->operator, [...$condition->values, (string) $ruleId]);

                return new self($conditions, $this->matchAll);
            }
        }

        if ($this->isEveryone() || ($this->matchAll && count($this->conditions) > 1)) {
            return null;
        }

        return new self([...$this->conditions, new GymCondition(GymField::Verdict, GymOperator::In, [(string) $ruleId])], matchAll: false);
    }

    /**
     * Compile the entry into a Rules expression, or null when every visitor enters.
     *
     * @return array<string, mixed>|null
     */
    public function toExpression(): ?array
    {
        if ($this->isEveryone()) {
            return null;
        }

        $nodes = array_map(fn (GymCondition $condition) => $condition->toExpression(), $this->conditions);

        return $this->matchAll ? GymField::all($nodes) : GymField::any($nodes);
    }

    /**
     * Say who enters in one sentence, e.g. "Visitors whose network is DigitalOcean or Hetzner."
     */
    public function describe(Team $team): string
    {
        if ($this->isEveryone()) {
            return __('Every visitor.');
        }

        $clauses = array_map(fn (GymCondition $condition) => $condition->describe($team), $this->conditions);
        $joined = Arr::join($clauses, ', ', ($this->matchAll ? ',' : '').' '.($this->matchAll ? __('and') : __('or')).' ');

        return __('Visitors :clauses.', ['clauses' => $joined]);
    }
}
