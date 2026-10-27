<?php

namespace App\Enums;

/**
 * The request method, stored as a smallint. Anything unusual is kept as Other.
 */
enum HttpMethod: int
{
    case Other = 0;
    case Get = 1;
    case Post = 2;
    case Head = 3;
    case Put = 4;
    case Patch = 5;
    case Delete = 6;
    case Options = 7;
    case Connect = 8;
    case Trace = 9;

    /**
     * Get the method for a request line's verb, e.g. "GET".
     */
    public static function fromVerb(?string $verb): self
    {
        foreach (self::cases() as $case) {
            if ($case !== self::Other && $case->label() === strtoupper((string) $verb)) {
                return $case;
            }
        }

        return self::Other;
    }

    /**
     * Get the verb as it appears in a request line.
     */
    public function label(): string
    {
        return $this === self::Other ? 'OTHER' : strtoupper($this->name);
    }
}
