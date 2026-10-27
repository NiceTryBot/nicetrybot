<?php

namespace App\Enums;

/**
 * A browser family as its user agent claims it. Claims are cheap, so the gym uses these to pick who
 * gets which obstacles, never to trust anyone.
 */
enum BrowserFamily: string
{
    case Chrome = 'chrome';
    case Firefox = 'firefox';
    case Safari = 'safari';
    case None = 'none';

    /**
     * Get the lowercase LIKE patterns a user agent claiming this family contains. None has no
     * patterns: it means the request sent no user agent at all.
     *
     * @return list<string>
     */
    public function likePatterns(): array
    {
        return match ($this) {
            self::Chrome => ['%chrome/%', '%crios/%'],
            self::Firefox => ['%firefox/%', '%fxios/%'],
            self::Safari => ['%version/%safari/%'],
            self::None => [],
        };
    }

    /**
     * Determine if a user agent claims this family.
     */
    public function matches(?string $userAgent): bool
    {
        if ($this === self::None) {
            return blank($userAgent);
        }

        $userAgent = strtolower((string) $userAgent);

        foreach ($this->likePatterns() as $pattern) {
            $regex = '#^'.str_replace('%', '.*', preg_quote($pattern, '#')).'$#s';

            if (preg_match($regex, $userAgent) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the display label for the family.
     */
    public function label(): string
    {
        return match ($this) {
            self::Chrome => __('Chrome'),
            self::Firefox => __('Firefox'),
            self::Safari => __('Safari'),
            self::None => __('No user agent'),
        };
    }

    /**
     * Get a short note on what the family covers.
     */
    public function hint(): string
    {
        return match ($this) {
            self::Chrome => __('Chromium browsers too: Edge, Opera, Brave, Samsung'),
            self::Firefox => __('Desktop and mobile'),
            self::Safari => __('Desktop and iOS Safari'),
            self::None => __('The request sent no User-Agent header'),
        };
    }
}
