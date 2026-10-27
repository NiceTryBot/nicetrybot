<?php

namespace App\Support;

use App\Enums\PathCategory;

/**
 * Geometry and symbols for the live traffic map: the world map is drawn in the Equal Earth projection,
 * fitted to a WIDTH × MAP_HEIGHT box (see components/ntb/world-land), with the edge below it.
 */
final class TrafficMap
{
    public const int WIDTH = 1000;

    public const int MAP_HEIGHT = 440;

    /**
     * The whole canvas: the map plus the band underneath where the edge sits.
     */
    public const int HEIGHT = 560;

    /**
     * Where requests land: the edge node, centred below the map.
     *
     * @var array{0: int, 1: int}
     */
    public const array EDGE = [500, 508];

    /**
     * The projection's scale and centre, matching the generated land path.
     */
    private const float SCALE = 184.3735386650027;

    private const float CENTRE_X = 500.0;

    private const float CENTRE_Y = 246.6864653856072;

    /**
     * Project a latitude and longitude onto the map, as [x, y] in canvas units.
     *
     * @return array{0: float, 1: float}
     */
    public static function project(float $latitude, float $longitude): array
    {
        [$a1, $a2, $a3, $a4] = [1.340264, -0.081106, 0.000893, 0.003796];
        $m = sqrt(3) / 2;

        $l = asin($m * sin(deg2rad($latitude)));
        $l2 = $l * $l;
        $l6 = $l2 * $l2 * $l2;

        $x = deg2rad($longitude) * cos($l) / ($m * ($a1 + 3 * $a2 * $l2 + $l6 * (7 * $a3 + 9 * $a4 * $l2)));
        $y = $l * ($a1 + $a2 * $l2 + $l6 * ($a3 + $a4 * $l2));

        return [round(self::CENTRE_X + self::SCALE * $x, 1), round(self::CENTRE_Y - self::SCALE * $y, 1)];
    }

    /**
     * Get the icon key for a request's path category ("page" for ordinary pages).
     */
    public static function iconFor(?PathCategory $category): string
    {
        return $category === null ? 'page' : str_replace('/', '-', $category->slug());
    }

    /**
     * Get the colour family for a request's path category: human pages, assets, probes or the edge's own.
     */
    public static function familyFor(?PathCategory $category): string
    {
        return $category === null ? 'page' : $category->family();
    }

    /**
     * The legend: each icon key with its label and family, in the order shown.
     *
     * @return list<array{icon: string, label: string, family: string, description: string}>
     */
    public static function legend(): array
    {
        $legend = [['icon' => 'page', 'label' => __('Page'), 'family' => 'page', 'description' => __('An ordinary page on the site.')]];

        foreach (PathCategory::cases() as $category) {
            if ($category === PathCategory::EdgeDiagnostic) {
                continue;
            }

            $legend[] = [
                'icon' => self::iconFor($category),
                'label' => $category->slug(),
                'family' => $category->family(),
                'description' => $category->description(),
            ];
        }

        return $legend;
    }
}
