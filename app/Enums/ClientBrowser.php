<?php

namespace App\Enums;

/**
 * The browser a user agent claims to be. Chromium covers Chrome and the browsers built on it (Edge,
 * Opera, Brave, Samsung Internet), on iOS too, where Chrome and Edge brand Apple's WebKit.
 */
enum ClientBrowser: int
{
    case Unknown = 0;
    case Chromium = 1;
    case Safari = 2;
    case Firefox = 3;
    case Bot = 4;

    /**
     * A user agent that announces a bot, crawler or HTTP library. It isn't pretending to be a person.
     */
    private const string BOT_PATTERN = '#compatible;|https?://|bot/|crawl|spider|headlesschrome|lighthouse|python|curl/|wget/|go-http-client|okhttp|java/|axios/|node-fetch|scrapy|facebookexternalhit#i';

    /**
     * Chromium browsers that ship their own release train well behind Chrome's, so their Chrome
     * version says nothing about whether they're kept up to date.
     */
    private const string LAGGING_FORK_PATTERN = '#SamsungBrowser/|YaBrowser/|UCBrowser/|MiuiBrowser/|HuaweiBrowser/#';

    /**
     * The highest major version any real browser could claim. Spoofed user agents send versions
     * like "Chrome/83044", which no browser has shipped and which wouldn't fit the profile column.
     */
    private const int MAX_MAJOR_VERSION = 999;

    /**
     * Read the browser a user agent claims. A user agent that names both Chrome and Firefox is no
     * real browser's, so it stays unknown.
     */
    public static function fromUserAgent(string $userAgent): self
    {
        $claimsChromium = preg_match('#(?:Chrome|CriOS|EdgiOS)/\d#', $userAgent) === 1;
        $claimsFirefox = preg_match('#(?:Firefox|FxiOS)/\d#', $userAgent) === 1;

        return match (true) {
            preg_match(self::BOT_PATTERN, $userAgent) === 1 => self::Bot,
            $claimsChromium && $claimsFirefox => self::Unknown,
            $claimsChromium => self::Chromium,
            $claimsFirefox => self::Firefox,
            preg_match('#Version/\d.*Safari/#', $userAgent) === 1 => self::Safari,
            default => self::Unknown,
        };
    }

    /**
     * Read the major version of this browser from a user agent, e.g. 152 from "CriOS/152.0.7977.53".
     * A version no browser could have shipped stays unknown.
     */
    public function majorVersionIn(string $userAgent): ?int
    {
        if ($this === self::Chromium && preg_match(self::LAGGING_FORK_PATTERN, $userAgent) === 1) {
            return null;
        }

        $pattern = match ($this) {
            self::Chromium => '#(?:Chrome|CriOS|EdgiOS)/(\d+)#',
            self::Firefox => '#(?:Firefox|FxiOS)/(\d+)#',
            self::Safari => '#Version/(\d+)#',
            self::Unknown, self::Bot => null,
        };

        if ($pattern === null || preg_match($pattern, $userAgent, $matches) !== 1) {
            return null;
        }

        $majorVersion = (int) $matches[1];

        return $majorVersion <= self::MAX_MAJOR_VERSION ? $majorVersion : null;
    }

    public function label(): string
    {
        return match ($this) {
            self::Unknown => __('an unknown browser'),
            self::Chromium => 'Chrome',
            self::Safari => 'Safari',
            self::Firefox => 'Firefox',
            self::Bot => __('a bot'),
        };
    }
}
