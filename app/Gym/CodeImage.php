<?php

namespace App\Gym;

use GdImage;

/**
 * Draws a screen code as a PNG: GD's built-in bitmap font scaled up, each glyph tilted and nudged,
 * crossed by noise and bent by a wave. No font files, so it renders the same on Lambda.
 */
class CodeImage
{
    private const int SCALE = 3;

    private const int HEIGHT = 76;

    /**
     * The size of a character in GD's built-in font 5.
     */
    private const int FONT = 5;

    private const int FONT_WIDTH = 9;

    private const int FONT_HEIGHT = 15;

    /**
     * Draw a code and return it as a data URI.
     *
     * @param  'light'|'medium'|'heavy'|string  $noise
     */
    public static function dataUri(string $code, string $noise = 'medium'): string
    {
        $level = match ($noise) {
            'light' => 1,
            'heavy' => 3,
            default => 2,
        };

        $glyphWidth = self::FONT_WIDTH * self::SCALE;
        $width = strlen($code) * ($glyphWidth + 8) + 36;
        $image = self::canvas($width, self::HEIGHT, [246, 248, 250]);

        imagealphablending($image, true);
        self::speckle($image, $width, $level * 140);

        $x = 18;

        foreach (str_split($code) as $character) {
            $glyph = self::glyph($character, random_int(-18, 18));
            imagecopy($image, $glyph, $x + random_int(-2, 2), (int) ((self::HEIGHT - imagesy($glyph)) / 2) + random_int(-6, 6), 0, 0, imagesx($glyph), imagesy($glyph));
            $x += $glyphWidth + 8;
        }

        self::scribble($image, $width, $level * 3);

        if ($level > 1) {
            $image = self::wave($image, $width, $level * 2.2);
        }

        ob_start();
        imagepng($image);

        return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
    }

    /**
     * @param  int<1, max>  $width
     * @param  int<1, max>  $height
     * @param  array{int<0, 255>, int<0, 255>, int<0, 255>}|null  $color  Null for a transparent canvas.
     */
    private static function canvas(int $width, int $height, ?array $color = null): GdImage
    {
        $image = imagecreatetruecolor($width, $height);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, $color === null
            ? (int) imagecolorallocatealpha($image, 0, 0, 0, 127)
            : (int) imagecolorallocate($image, ...$color));

        return $image;
    }

    /**
     * Draw one character big and tilted on a transparent tile.
     */
    private static function glyph(string $character, int $angle): GdImage
    {
        $small = self::canvas(self::FONT_WIDTH, self::FONT_HEIGHT);
        imagealphablending($small, true);
        imagestring($small, self::FONT, 0, 0, $character, (int) imagecolorallocate($small, random_int(7, 50), random_int(28, 54), random_int(39, 80)));

        $big = self::canvas(self::FONT_WIDTH * self::SCALE, self::FONT_HEIGHT * self::SCALE);
        imagecopyresampled($big, $small, 0, 0, 0, 0, imagesx($big), imagesy($big), imagesx($small), imagesy($small));

        $rotated = imagerotate($big, $angle, (int) imagecolorallocatealpha($big, 0, 0, 0, 127));

        if ($rotated === false) {
            return $big;
        }

        imagesavealpha($rotated, true);

        return $rotated;
    }

    private static function speckle(GdImage $image, int $width, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            imagesetpixel($image, random_int(0, $width - 1), random_int(0, self::HEIGHT - 1), (int) imagecolorallocatealpha($image, random_int(60, 160), random_int(70, 170), random_int(80, 180), random_int(30, 80)));
        }
    }

    private static function scribble(GdImage $image, int $width, int $count): void
    {
        imagesetthickness($image, 2);

        for ($i = 0; $i < $count; $i++) {
            $color = (int) imagecolorallocatealpha($image, random_int(20, 120), random_int(30, 90), random_int(40, 110), random_int(55, 85));

            $i % 2 === 0
                ? imageline($image, random_int(0, (int) ($width / 3)), random_int(8, self::HEIGHT - 8), random_int((int) ($width * 2 / 3), $width), random_int(8, self::HEIGHT - 8), $color)
                : imagearc($image, random_int(0, $width), random_int(0, self::HEIGHT), random_int(60, $width), random_int(30, 120), random_int(0, 180), random_int(181, 360), $color);
        }
    }

    /**
     * Bend the image along a sine wave, column by column.
     *
     * @param  int<1, max>  $width
     */
    private static function wave(GdImage $image, int $width, float $amplitude): GdImage
    {
        $bent = self::canvas($width, self::HEIGHT, [246, 248, 250]);
        $period = random_int(18, 30);
        $phase = random_int(0, 628) / 100;

        for ($x = 0; $x < $width; $x++) {
            imagecopy($bent, $image, $x, (int) round(sin($x / $period + $phase) * $amplitude), $x, 0, 1, self::HEIGHT);
        }

        return $bent;
    }
}
