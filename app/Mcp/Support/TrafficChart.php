<?php

namespace App\Mcp\Support;

use App\Support\BitmapFont;
use GdImage;

/**
 * Draws a traffic count as a PNG an agent can show: horizontal bars for requests grouped by a field,
 * columns for requests over time. Text comes from pre-rendered glyph atlases (BitmapFont), since GD
 * on Vapor has no FreeType, in NiceTryBot's ink and coral on white.
 */
class TrafficChart
{
    public const int WIDTH = 1200;

    /**
     * The most bars a grouping chart draws; the rest are counted under them.
     */
    public const int MAX_BARS = 25;

    private const array INK = [7, 28, 39];

    private const array CORAL = [255, 100, 117];

    private const array WHITE = [255, 255, 255];

    private const array LINE = [226, 232, 235];

    private const array QUIET = [122, 135, 144];

    private const int LEFT = 40;

    private const int HEADER_H = 140;

    private const int ROW_H = 34;

    private const int LABEL_W = 360;

    private const int BAR_X = 420;

    private const int BAR_MAX_W = 620;

    private const int COLUMNS_H = 560;

    private const int PLOT_LEFT = 90;

    private const int PLOT_RIGHT = 1160;

    private const int PLOT_TOP = 160;

    private const int PLOT_BOTTOM = 490;

    /**
     * Draw requests grouped by a field, busiest first, one horizontal bar each.
     *
     * @param  list<array{label: string, value: int}>  $rows
     */
    public function bars(string $title, string $subtitle, array $rows): string
    {
        $shown = array_slice($rows, 0, self::MAX_BARS);
        $hidden = count($rows) - count($shown);
        $height = self::HEADER_H + max(1, count($shown)) * self::ROW_H + ($hidden > 0 ? 36 : 0) + 30;
        $image = $this->canvas($height, $title, $subtitle);

        if ($shown === []) {
            $this->nothing($image, self::HEADER_H + 23);

            return $this->png($image);
        }

        $label = BitmapFont::get('row');
        $number = BitmapFont::get('rowb');
        $largest = max(1, ...array_map(fn (array $row) => $row['value'], $shown));

        foreach ($shown as $index => $row) {
            $top = self::HEADER_H + $index * self::ROW_H;
            $width = (int) round(self::BAR_MAX_W * $row['value'] / $largest);

            $label->draw($image, $label->fit($row['label'], self::LABEL_W), self::LEFT, $top + 23, self::INK);
            imagefilledrectangle($image, self::BAR_X, $top + 7, self::BAR_X + max(2, $width), $top + self::ROW_H - 7, $this->color($image, self::CORAL));
            $number->draw($image, number_format($row['value']), self::BAR_X + max(2, $width) + 10, $top + 23, self::INK);
        }

        if ($hidden > 0) {
            BitmapFont::get('small')->draw($image, $hidden === 1 ? '1 more not shown' : number_format($hidden).' more not shown', self::LEFT, self::HEADER_H + count($shown) * self::ROW_H + 26, self::QUIET);
        }

        return $this->png($image);
    }

    /**
     * Draw requests over time, one column per bar of the interval, oldest on the left.
     *
     * @param  list<array{label: string, value: int}>  $columns
     */
    public function columns(string $title, string $subtitle, array $columns): string
    {
        $image = $this->canvas(self::COLUMNS_H, $title, $subtitle);
        $small = BitmapFont::get('small');
        $largest = max(0, ...array_map(fn (array $column) => $column['value'], $columns ?: [['value' => 0]]));

        if ($largest === 0) {
            $this->nothing($image, self::PLOT_TOP + 40);

            return $this->png($image);
        }

        $top = $this->niceCeiling($largest);
        $plotHeight = self::PLOT_BOTTOM - self::PLOT_TOP;

        for ($step = 0; $step <= 4; $step++) {
            $y = (int) round(self::PLOT_BOTTOM - $plotHeight * $step / 4);
            imagefilledrectangle($image, self::PLOT_LEFT, $y, self::PLOT_RIGHT, $y, $this->color($image, self::LINE));
            $text = $this->compact((int) round($top * $step / 4));
            $small->draw($image, $text, self::PLOT_LEFT - 12 - $small->width($text), $y + 5, self::QUIET);
        }

        $count = count($columns);
        $slot = (self::PLOT_RIGHT - self::PLOT_LEFT) / $count;
        $columnWidth = $slot >= 4 ? $slot * 0.72 : $slot;
        $coral = $this->color($image, self::CORAL);

        foreach ($columns as $index => $column) {
            if ($column['value'] === 0) {
                continue;
            }

            $left = self::PLOT_LEFT + $index * $slot + ($slot - $columnWidth) / 2;
            $columnTop = self::PLOT_BOTTOM - max(1, (int) round($plotHeight * $column['value'] / $top));
            imagefilledrectangle($image, (int) round($left), $columnTop, max((int) round($left), (int) round($left + $columnWidth) - 1), self::PLOT_BOTTOM - 1, $coral);
        }

        $widest = max([0, ...array_map(fn (array $column) => $small->width($column['label']), $columns)]);
        $every = max(1, (int) ceil($count / 8), (int) ceil(($widest + 24) / $slot));

        for ($index = 0; $index < $count; $index += $every) {
            $text = $columns[$index]['label'];
            $center = self::PLOT_LEFT + ($index + 0.5) * $slot;
            $x = min(max($center - $small->width($text) / 2, 20), self::WIDTH - 20 - $small->width($text));
            $small->draw($image, $text, $x, self::PLOT_BOTTOM + 26, self::QUIET);
        }

        return $this->png($image);
    }

    /**
     * A white canvas with the chart's title and what it counts.
     *
     * @param  positive-int  $height
     */
    private function canvas(int $height, string $title, string $subtitle): GdImage
    {
        $image = imagecreatetruecolor(self::WIDTH, $height);
        imagealphablending($image, true);
        imagefill($image, 0, 0, $this->color($image, self::WHITE));

        BitmapFont::get('eyebrow')->draw($image, 'NICETRYBOT', self::LEFT, 46, self::CORAL, 2.1);
        $lede = BitmapFont::get('lede');
        $lede->draw($image, $lede->fit($title, self::WIDTH - 2 * self::LEFT), self::LEFT, 84, self::INK);
        $small = BitmapFont::get('small');
        $small->draw($image, $small->fit($subtitle, self::WIDTH - 2 * self::LEFT), self::LEFT, 112, self::QUIET);

        return $image;
    }

    /**
     * Say there's nothing to draw.
     */
    private function nothing(GdImage $image, int $baseline): void
    {
        BitmapFont::get('row')->draw($image, 'No requests matched.', self::LEFT, $baseline, self::QUIET);
    }

    /**
     * Round a count up to a tidy axis maximum: four steps of 1, 2 or 5 times a power of ten.
     */
    private function niceCeiling(int $value): int
    {
        $quarter = max(1, $value / 4);
        $magnitude = 10 ** (int) floor(log10($quarter));

        foreach ([1, 2, 5, 10] as $multiple) {
            if ($quarter <= $multiple * $magnitude) {
                return 4 * $multiple * $magnitude;
            }
        }

        return 4 * (int) ceil($quarter);
    }

    /**
     * Write a count short, like 950, 12k or 3.4M.
     */
    private function compact(int $value): string
    {
        return match (true) {
            $value >= 1_000_000 => rtrim(rtrim(number_format($value / 1_000_000, 1), '0'), '.').'M',
            $value >= 1_000 => rtrim(rtrim(number_format($value / 1_000, 1), '0'), '.').'k',
            default => (string) $value,
        };
    }

    /**
     * @param  array{0: int<0, 255>, 1: int<0, 255>, 2: int<0, 255>}  $rgb
     */
    private function color(GdImage $image, array $rgb): int
    {
        return (int) imagecolorallocate($image, ...$rgb);
    }

    private function png(GdImage $image): string
    {
        ob_start();
        imagepng($image, null, 9);

        return (string) ob_get_clean();
    }
}
