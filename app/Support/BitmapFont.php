<?php

namespace App\Support;

use GdImage;
use RuntimeException;

/**
 * Text from a pre-rendered glyph atlas (resources/fonts/bitmap), because GD on Vapor has no FreeType.
 * Each atlas holds one font at one size: black glyphs on transparent, plus per-glyph metrics. The MCP
 * server's traffic charts draw with it.
 */
final class BitmapFont
{
    /** @var array<string, self> */
    private static array $loaded = [];

    /** @var array<string, GdImage> Colored copies of the atlas, keyed by "r,g,b". */
    private array $colored = [];

    /**
     * @param  array{size: int, ascent: int, descent: int, pad: int, glyphs: array<string, array{0: int, 1: int, 2: int, 3: int, 4: int, 5: float}>}  $meta
     */
    private function __construct(private readonly GdImage $atlas, private readonly array $meta)
    {
        //
    }

    public static function get(string $name): self
    {
        if (isset(self::$loaded[$name])) {
            return self::$loaded[$name];
        }

        $base = resource_path("fonts/bitmap/{$name}");
        $atlas = @imagecreatefrompng("{$base}.png");
        $meta = json_decode((string) @file_get_contents("{$base}.json"), true);

        if (! $atlas instanceof GdImage || ! is_array($meta)) {
            throw new RuntimeException("Missing bitmap font [{$name}].");
        }

        imagealphablending($atlas, false);
        imagesavealpha($atlas, true);

        return self::$loaded[$name] = new self($atlas, $meta);
    }

    public function size(): int
    {
        return $this->meta['size'];
    }

    public function ascent(): int
    {
        return $this->meta['ascent'];
    }

    /**
     * How wide the text is, with the given extra space between letters.
     */
    public function width(string $text, float $tracking = 0): float
    {
        $width = 0.0;
        $characters = mb_str_split($text);

        foreach ($characters as $character) {
            $width += $this->glyph($character)[5] + $tracking;
        }

        return $characters === [] ? 0.0 : $width - $tracking;
    }

    /**
     * Draw the text with its baseline at $baseline, starting at $x. Returns where the text ends.
     *
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    public function draw(GdImage $image, string $text, float $x, float $baseline, array $rgb, float $tracking = 0): float
    {
        $atlas = $this->coloredAtlas($rgb);
        $top = (int) round($baseline - $this->meta['pad'] - $this->meta['ascent']);

        foreach (mb_str_split($text) as $character) {
            [$gx, $gy, $gw, $gh, $offset, $advance] = $this->glyph($character);

            if ($character !== ' ') {
                imagecopy($image, $atlas, (int) round($x - $offset), $top, $gx, $gy, $gw, $gh);
            }

            $x += $advance + $tracking;
        }

        return $x;
    }

    /**
     * Break text into lines no wider than $width, at spaces.
     *
     * @return list<string>
     */
    public function wrap(string $text, float $width, float $tracking = 0): array
    {
        $lines = [];
        $line = '';

        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $candidate = $line === '' ? $word : "{$line} {$word}";

            if ($line !== '' && $this->width($candidate, $tracking) > $width) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * Shorten text with an ellipsis until it fits.
     */
    public function fit(string $text, float $width, float $tracking = 0): string
    {
        if ($this->width($text, $tracking) <= $width) {
            return $text;
        }

        while (mb_strlen($text) > 1 && $this->width($text.'…', $tracking) > $width) {
            $text = mb_substr($text, 0, -1);
        }

        return rtrim($text).'…';
    }

    /**
     * @return array{0: int, 1: int, 2: int, 3: int, 4: int, 5: float}
     */
    private function glyph(string $character): array
    {
        return $this->meta['glyphs'][$character] ?? $this->meta['glyphs']['?'];
    }

    /**
     * @param  array{0: int, 1: int, 2: int}  $rgb
     */
    private function coloredAtlas(array $rgb): GdImage
    {
        $key = implode(',', $rgb);

        if (! isset($this->colored[$key])) {
            $copy = imagecreatetruecolor(imagesx($this->atlas), imagesy($this->atlas));
            imagealphablending($copy, false);
            imagesavealpha($copy, true);
            imagecopy($copy, $this->atlas, 0, 0, 0, 0, imagesx($this->atlas), imagesy($this->atlas));
            // The glyphs are black, so adding the color turns them that color; alpha is untouched.
            imagefilter($copy, IMG_FILTER_COLORIZE, $rgb[0], $rgb[1], $rgb[2]);
            imagealphablending($copy, true);
            $this->colored[$key] = $copy;
        }

        return $this->colored[$key];
    }
}
