<?php

namespace App\Support;

use App\Enums\HttpMethod;
use App\Models\EdgeRequest;
use Illuminate\Support\Str;

/**
 * Rebuilds a recorded request as a curl command, to paste into a terminal or import into Postman. It holds
 * only what the edge kept: masked values stay masked, and cookies and most headers were never recorded.
 *
 * Everything in the request came from the visitor and ends up pasted into someone's shell, so every value
 * is single-quoted, and control characters (which a terminal acts on as they're pasted) are dropped.
 */
final class CurlCommand
{
    /**
     * @param  array{content_type: string|null, body: string}|null  $sent  What the visitor sent, if an add-on recorded it.
     */
    public static function for(EdgeRequest $request, ?array $sent = null): string
    {
        // HEAD can't carry a body: curl refuses --head together with --data-raw.
        $sent = $request->method === HttpMethod::Head || blank($sent['body'] ?? null) ? null : $sent;

        // Globbing and path squashing would change what a probe asked for, like /a/../../etc/passwd.
        $parts = [self::quote('https://'.$request->domain->hostname.self::urlSafe($request->path)), '--globoff', '--path-as-is'];

        if ($request->method === HttpMethod::Head) {
            $parts[] = '--head';
        } elseif ($request->method !== HttpMethod::Get || $sent !== null) {
            $parts[] = '-X '.$request->method->label();
        }

        foreach (self::headers($request, $sent['content_type'] ?? null) as $name => $value) {
            $parts[] = '-H '.self::quote($name.': '.$value);
        }

        if ($sent !== null) {
            // Not --data, which reads a local file when the body starts with an @.
            $parts[] = '--data-raw '.self::quote(self::withoutControlCharacters($sent['body']));
        }

        return 'curl '.implode(" \\\n  ", $parts);
    }

    /**
     * Get the headers the edge recorded.
     *
     * @return array<string, string>
     */
    private static function headers(EdgeRequest $request, ?string $contentType): array
    {
        [$brands, $platform] = array_pad(explode(' | ', (string) $request->client_hints, 2), 2, '');

        $headers = array_filter([
            'User-Agent' => $request->user_agent,
            'Accept-Language' => $request->accept_language,
            'Sec-CH-UA' => $brands,
            'Sec-CH-UA-Platform' => $platform,
            'Content-Type' => $contentType,
        ], fn (?string $value) => filled($value));

        return array_map(fn (string $value) => self::withoutControlCharacters($value, keepLineBreaks: false), $headers);
    }

    /**
     * Percent-encode what can't appear in a URL as-is, which curl would otherwise refuse.
     */
    private static function urlSafe(string $path): string
    {
        return (string) preg_replace_callback('/[\x00-\x20\x7F]/', fn (array $match) => rawurlencode($match[0]), $path);
    }

    private static function withoutControlCharacters(string $value, bool $keepLineBreaks = true): string
    {
        $value = Str::of($value)->replaceMatches('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x{9F}]/u', '')->toString();

        return $keepLineBreaks ? $value : str_replace(["\t", "\r", "\n"], ['  ', '', ''], $value);
    }

    /**
     * Quote a value for a POSIX shell. Nothing expands inside single quotes, and a single quote can't appear
     * in them, so it closes the quotes, adds an escaped one and reopens them.
     */
    private static function quote(string $value): string
    {
        return "'".str_replace("'", "'\\''", $value)."'";
    }
}
