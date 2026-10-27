<?php

namespace App\Data;

use App\Enums\GymField;
use App\Enums\GymOperator;
use App\Models\Team;
use Illuminate\Support\Arr;

/**
 * One entry condition of a gym routine, as built in the visual editor: a field, how to compare it,
 * and the values to compare with, e.g. "Network is one of DigitalOcean, Hetzner".
 */
readonly class GymCondition
{
    /**
     * @param  list<string>  $values
     */
    public function __construct(
        public GymField $field,
        public GymOperator $operator,
        public array $values,
    ) {
        //
    }

    /**
     * Rebuild a condition saved with toArray(), or return null when it no longer makes sense (an
     * unknown field, an operator the field doesn't take, or no values left).
     *
     * @param  array<string, mixed>  $data  Saved or edited input, not trusted to be well formed.
     */
    public static function fromArray(array $data): ?self
    {
        $field = is_string($data['field'] ?? null) ? GymField::tryFrom($data['field']) : null;
        $operator = is_string($data['operator'] ?? null) ? GymOperator::tryFrom($data['operator']) : null;

        if ($field === null || $operator === null || ! in_array($operator, $field->operators(), true)) {
            return null;
        }

        $values = array_values(array_unique(array_filter(array_map(
            fn (mixed $value) => is_scalar($value) ? $field->normalize((string) $value) : null,
            is_array($data['values'] ?? null) ? $data['values'] : [],
        ))));

        return $values === [] ? null : new self($field, $operator, $values);
    }

    /**
     * @return array{field: string, operator: string, values: list<string>}
     */
    public function toArray(): array
    {
        return ['field' => $this->field->value, 'operator' => $this->operator->value, 'values' => $this->values];
    }

    /**
     * Determine if a visitor meets the condition.
     */
    public function matches(GymVisitor $visitor): bool
    {
        $matches = $this->field->test($visitor, $this->operator->positive(), $this->values);

        return $this->operator->isNegated() ? ! $matches : $matches;
    }

    /**
     * Compile the condition into a Rules expression node, so the Rules engine can backtest it. A
     * negated condition also matches requests where the field is unknown, as matches() does.
     *
     * @return array<string, mixed>
     */
    public function toExpression(): array
    {
        $positive = $this->field->expression($this->operator->positive(), $this->values);

        if (! $this->operator->isNegated()) {
            return $positive;
        }

        $unknown = $this->field->unknownExpression();
        $negated = ['kind' => 'not', 'expr' => $positive];

        if ($unknown === null) {
            return $negated;
        }

        // "Browser is not one of Chrome, No user agent": the unknown case was named, so leave it out.
        return in_array('none', $this->values, true) && $this->field === GymField::Browser
            ? GymField::all([['kind' => 'null', 'field' => $unknown['field'], 'negated' => true], $negated])
            : GymField::any([$unknown, $negated]);
    }

    /**
     * Say the condition as a relative clause, e.g. "whose network is DigitalOcean or Hetzner".
     */
    public function describe(Team $team): string
    {
        $options = $this->field->isPicked() ? $this->field->options($team) : [];
        $values = array_map(fn (string $value) => match (true) {
            $this->field === GymField::Category => str_contains($value, '/') ? $value : $value.'/*',
            $this->field === GymField::Verdict => $value === GymField::ANY_RULE ? __('any rule') : '“'.($options[$value] ?? __('a deleted rule')).'”',
            $this->field->isPicked() => $options[$value] ?? $value,
            default => '“'.$value.'”',
        }, $this->values);
        $list = Arr::join($values, ', ', ' '.__('or').' ');

        $verb = match ($this->operator) {
            GymOperator::In => $this->field === GymField::Ip ? __('is in') : __('is'),
            GymOperator::NotIn => $this->field === GymField::Ip ? __('is not in') : __('is not'),
            default => $this->field->operatorLabel($this->operator),
        };

        return $this->field === GymField::Verdict
            ? __('who :has', ['has' => $this->field->operatorLabel($this->operator)]).' '.$list
            : $this->field->phrase().' '.$verb.' '.$list;
    }
}
