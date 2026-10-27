<?php

namespace App\Rules;

use App\Support\HeaderName;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * A comma-separated list of HTTP header names, like "X-Domain, User-Custom-Domain". Tidy the input with
 * HeaderName::normalizeList() first.
 */
class HeaderNames implements ValidationRule
{
    /**
     * The most header names one list may hold.
     */
    public const int MAX_HEADERS = 4;

    /**
     * The longest a single header name may be.
     */
    public const int MAX_NAME_LENGTH = 64;

    /**
     * The longest the whole list may be, so it fits its column.
     */
    public const int MAX_LIST_LENGTH = 255;

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('Header names may only contain letters, numbers, and dashes.'));

            return;
        }

        $names = HeaderName::split($value);

        foreach ($names as $name) {
            if (preg_match('/^[A-Za-z0-9-]+$/', $name) !== 1) {
                $fail(__('Header names may only contain letters, numbers, and dashes. Separate several with commas.'));

                return;
            }

            if (strlen($name) > self::MAX_NAME_LENGTH) {
                $fail(__('Keep each header name to :max characters or fewer.', ['max' => self::MAX_NAME_LENGTH]));

                return;
            }
        }

        if (count($names) > self::MAX_HEADERS) {
            $fail(__('Send the host in :max headers at most.', ['max' => self::MAX_HEADERS]));

            return;
        }

        if (count(array_unique(array_map(Str::lower(...), $names))) !== count($names)) {
            $fail(__('List each header only once.'));

            return;
        }

        if (strlen($value) > self::MAX_LIST_LENGTH) {
            $fail(__('Keep the whole list to :max characters or fewer.', ['max' => self::MAX_LIST_LENGTH]));
        }
    }
}
