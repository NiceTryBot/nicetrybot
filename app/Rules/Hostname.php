<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class Hostname implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! self::isValid($value)) {
            $fail(__('Enter a hostname like example.com or www.example.com, without a path.'));
        }
    }

    /**
     * Determine if the value is a lowercase hostname like www.example.com. A repeated www. (www.www.site)
     * is never one: it comes from an origin redirecting to www. of a name that already has it, in a loop.
     */
    public static function isValid(mixed $value): bool
    {
        return is_string($value)
            && ! str_starts_with($value, 'www.www.')
            && preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $value) === 1;
    }

    /**
     * Normalize user input such as "https://WWW.Example.com/claim" to "www.example.com".
     */
    public static function normalize(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#', '', $value);
        $value = (string) preg_replace('#[/?\#].*$#', '', $value);
        $value = (string) preg_replace('#:\d+$#', '', $value);

        return rtrim($value, '.');
    }
}
