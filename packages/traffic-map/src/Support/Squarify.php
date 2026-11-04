<?php

namespace NiceTry\TrafficMap\Support;

/**
 * A squarified treemap layout: turn a list of values (largest first) into rectangles that fill a box,
 * each kept as close to a square as the algorithm can manage. Rectangles come back in the same order
 * as the values, as percentages of the box.
 *
 * @see https://www.win.tue.nl/~vanwijk/stm.pdf
 */
class Squarify
{
    /**
     * @param  list<float|int>  $values  positive values, largest first
     * @return list<array{x: float, y: float, w: float, h: float}>
     */
    public static function rects(array $values, float $width = 100.0, float $height = 100.0): array
    {
        $total = array_sum($values);

        if ($total <= 0 || $values === []) {
            return [];
        }

        $scale = ($width * $height) / $total;
        $areas = array_map(static fn (float|int $value): float => (float) $value * $scale, $values);

        $rects = [];
        $rect = ['x' => 0.0, 'y' => 0.0, 'w' => $width, 'h' => $height];
        $index = 0;
        $count = count($areas);

        while ($index < $count) {
            $row = [$areas[$index]];
            $next = $index + 1;
            $side = min($rect['w'], $rect['h']);

            while ($next < $count) {
                $withNext = [...$row, $areas[$next]];

                if (self::worst($row, $side) >= self::worst($withNext, $side)) {
                    $row = $withNext;
                    $next++;
                } else {
                    break;
                }
            }

            self::place($row, $rect, $rects);
            $index = $next;
        }

        return $rects;
    }

    /**
     * The worst (largest) aspect ratio in a row laid along the given side.
     *
     * @param  list<float>  $row
     */
    private static function worst(array $row, float $side): float
    {
        $sum = array_sum($row);
        $max = max($row);
        $min = min($row);
        $sum2 = $sum * $sum;
        $side2 = $side * $side;

        if ($sum2 === 0.0 || $min === 0.0 || $side2 === 0.0) {
            return INF;
        }

        return max($side2 * $max / $sum2, $sum2 / ($side2 * $min));
    }

    /**
     * Lay a finished row along the short side of the rectangle and shrink what's left.
     *
     * @param  list<float>  $row
     * @param  array{x: float, y: float, w: float, h: float}  $rect
     * @param  list<array{x: float, y: float, w: float, h: float}>  $rects
     */
    private static function place(array $row, array &$rect, array &$rects): void
    {
        $sum = array_sum($row);

        if ($rect['w'] <= $rect['h']) {
            $rowHeight = $sum / $rect['w'];
            $cursor = $rect['x'];

            foreach ($row as $area) {
                $cellWidth = $area / $rowHeight;
                $rects[] = ['x' => $cursor, 'y' => $rect['y'], 'w' => $cellWidth, 'h' => $rowHeight];
                $cursor += $cellWidth;
            }

            $rect['y'] += $rowHeight;
            $rect['h'] -= $rowHeight;

            return;
        }

        $rowWidth = $sum / $rect['h'];
        $cursor = $rect['y'];

        foreach ($row as $area) {
            $cellHeight = $area / $rowWidth;
            $rects[] = ['x' => $rect['x'], 'y' => $cursor, 'w' => $rowWidth, 'h' => $cellHeight];
            $cursor += $cellHeight;
        }

        $rect['x'] += $rowWidth;
        $rect['w'] -= $rowWidth;
    }
}
