<?php

namespace App\Enums;

/**
 * The operating system a client claims, read from its user agent or its Sec-CH-UA-Platform client hint.
 */
enum ClientPlatform: int
{
    case Unknown = 0;
    case Windows = 1;
    case Mac = 2;
    case Linux = 3;
    case ChromeOs = 4;
    case Android = 5;
    case Ios = 6;

    /**
     * Read the platform a user agent claims. iPhones say "like Mac OS X", so iOS is checked first.
     */
    public static function fromUserAgent(string $userAgent): self
    {
        return match (true) {
            preg_match('/iPhone|iPad|iPod/', $userAgent) === 1 => self::Ios,
            str_contains($userAgent, 'Android') => self::Android,
            str_contains($userAgent, 'CrOS') => self::ChromeOs,
            str_contains($userAgent, 'Windows') => self::Windows,
            str_contains($userAgent, 'Macintosh') || str_contains($userAgent, 'Mac OS X') => self::Mac,
            str_contains($userAgent, 'Linux') || str_contains($userAgent, 'X11') => self::Linux,
            default => self::Unknown,
        };
    }

    /**
     * Read the platform from a stored set of client hints, "<Sec-CH-UA> | <Sec-CH-UA-Platform>".
     */
    public static function fromClientHints(string $clientHints): self
    {
        $platform = str_contains($clientHints, '|') ? strtolower(trim(substr($clientHints, strrpos($clientHints, '|') + 1), ' "')) : '';

        return match ($platform) {
            'ios' => self::Ios,
            'android' => self::Android,
            'chrome os', 'chromeos', 'cros' => self::ChromeOs,
            'windows' => self::Windows,
            'macos' => self::Mac,
            'linux' => self::Linux,
            default => self::Unknown,
        };
    }

    /**
     * Get how many major versions behind current Chrome a client on this platform may be before it counts
     * as outdated. Phones update Chrome through their app store and keep up; people leave desktop
     * browsers running for weeks, so a desktop gets more slack. Measured on invited claimants
     * (2026-10-12): no Android client was 3 behind, and 4% of desktops were 6 or more.
     */
    public function outdatedAfter(): int
    {
        return $this === self::Android ? 3 : 6;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => __('an unknown platform'),
            self::Windows => 'Windows',
            self::Mac => 'macOS',
            self::Linux => 'Linux',
            self::ChromeOs => 'ChromeOS',
            self::Android => 'Android',
            self::Ios => 'iOS',
        };
    }
}
