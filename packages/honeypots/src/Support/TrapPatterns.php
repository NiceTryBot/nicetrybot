<?php

namespace NiceTry\Honeypots\Support;

use Closure;

/**
 * A trap's path patterns, one per line as they were typed. A plain pattern is a glob over the whole path,
 * in any case: `*` is anything, slashes too, and one without a leading slash gets one (`api/*` is
 * `/api/*`). A pattern starting `re:` is a regular expression, matched anywhere in the path as written
 * (`re:\.(env|git)` traps /.env and /x/.git/config). The edge runs Go's regular expressions, so
 * lookarounds and backreferences aren't allowed.
 */
final class TrapPatterns
{
    /**
     * The most patterns one trap may have.
     */
    public const int MAX = 50;

    /**
     * The longest one pattern may be.
     */
    public const int MAX_LENGTH = 500;

    private const string REGEX_PREFIX = 're:';

    /**
     * The patterns in what was typed: one per line, trimmed, blank lines and repeats dropped.
     *
     * @return list<string>
     */
    public static function fromText(string $text): array
    {
        return array_values(array_unique(array_filter(
            array_map(trim(...), preg_split('/\R/', $text) ?: []),
            fn (string $line) => $line !== '',
        )));
    }

    /**
     * The globs among the patterns, each with its leading slash.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function globs(array $patterns): array
    {
        $globs = array_filter($patterns, fn (string $pattern) => ! self::isRegex($pattern));

        return array_values(array_map(
            fn (string $glob) => str_starts_with($glob, '/') || str_starts_with($glob, '*') ? $glob : '/'.$glob,
            $globs,
        ));
    }

    /**
     * The regular expressions among the patterns, without their prefix.
     *
     * @param  list<string>  $patterns
     * @return list<string>
     */
    public static function regexes(array $patterns): array
    {
        return array_values(array_map(
            fn (string $pattern) => trim(substr($pattern, strlen(self::REGEX_PREFIX))),
            array_filter($patterns, self::isRegex(...)),
        ));
    }

    /**
     * Determine if a pattern is a regular expression rather than a glob.
     */
    public static function isRegex(string $pattern): bool
    {
        return str_starts_with($pattern, self::REGEX_PREFIX);
    }

    /**
     * The validation rule for the patterns' text: at least one, at most MAX, none too long, and every
     * regular expression one the edge can run.
     *
     * @return Closure(string, mixed, Closure(string): mixed): void
     */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $patterns = self::fromText(is_string($value) ? $value : '');

            if ($patterns === []) {
                $fail(__('Add at least one path pattern.'));

                return;
            }

            if (count($patterns) > self::MAX) {
                $fail(__('At most :max patterns per trap.', ['max' => self::MAX]));

                return;
            }

            foreach ($patterns as $pattern) {
                if (mb_strlen($pattern) > self::MAX_LENGTH) {
                    $fail(__('A pattern can be at most :max characters.', ['max' => self::MAX_LENGTH]));

                    return;
                }

                if (self::isRegex($pattern) && ($problem = self::regexProblem(trim(substr($pattern, strlen(self::REGEX_PREFIX))))) !== null) {
                    $fail(__(':pattern: :problem', ['pattern' => $pattern, 'problem' => $problem]));

                    return;
                }

                if (! self::isRegex($pattern) && trim($pattern, '/*') === '') {
                    $fail(__(':pattern would trap every 404. Name a path.', ['pattern' => $pattern]));

                    return;
                }
            }
        };
    }

    /**
     * Why a regular expression can't be used, or null when it can.
     */
    private static function regexProblem(string $regex): ?string
    {
        if ($regex === '') {
            return __('the regular expression is empty.');
        }

        if (preg_match('/\(\?<?[=!]|\\\\[1-9]/', $regex) === 1) {
            return __('lookarounds and backreferences aren’t supported.');
        }

        if (@preg_match('~'.str_replace('~', '\~', $regex).'~', '') === false) {
            return __('that isn’t a valid regular expression.');
        }

        return null;
    }
}
