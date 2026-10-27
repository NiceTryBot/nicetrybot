<?php

namespace App\Enums;

/**
 * How a gym condition compares a visitor's field with its values. Every operator matches when any of
 * the values does; the negated ones match when none does.
 */
enum GymOperator: string
{
    case In = 'in';
    case NotIn = 'not_in';
    case Contains = 'contains';
    case NotContains = 'not_contains';
    case StartsWith = 'starts_with';

    /**
     * Determine if the operator matches visitors the positive test rejects.
     */
    public function isNegated(): bool
    {
        return in_array($this, [self::NotIn, self::NotContains], true);
    }

    /**
     * Get the positive form of the operator.
     */
    public function positive(): self
    {
        return match ($this) {
            self::NotIn => self::In,
            self::NotContains => self::Contains,
            default => $this,
        };
    }
}
