<?php

namespace App\Data;

/**
 * A Sankey diagram of traffic flowing through a fixed sequence of stages, laid out for SVG.
 *
 * Each path is one combination of stage values (say network → fingerprint → user agent) and how many
 * requests or visits followed it. Every stage keeps its busiest values and folds the rest into a
 * single "Other" node, so no column holds more nodes than can be read. Ribbons take the color slot
 * of their path's first-stage value, so one origin can be traced through every stage; origins past
 * the palette share the neutral color. Slots are assigned by key, not by rank, so an origin keeps
 * its color when the busiest values reorder.
 */
readonly class SankeyDiagram
{
    /**
     * The width of the drawing in SVG user units.
     */
    public const int WIDTH = 1000;

    /**
     * The width of a node bar.
     */
    public const int NODE_WIDTH = 10;

    /**
     * The space kept right of the last column for its labels. Every label sits right of its node.
     */
    public const int LABEL_GUTTER = 290;

    /**
     * The approximate width of one label character, used to fit labels between columns.
     */
    private const float LABEL_CHAR_WIDTH = 6.2;

    /**
     * The vertical gap between nodes in a column; wide enough that even a hairline node can carry a label.
     */
    private const int NODE_GAP = 14;

    /**
     * The drawing height allowed per node in the fullest column.
     */
    private const int ROW_HEIGHT = 48;

    /**
     * The smallest drawing height.
     */
    private const int MIN_HEIGHT = 300;

    /**
     * The key of the node that folds a stage's less busy values.
     */
    public const string OTHER_KEY = '__other';

    /**
     * @param  list<string>  $columns  Stage titles, left to right.
     * @param  list<array{key: string, column: int, label: string, detail: ?string, value: int, x: float, y: float, height: float, slot: int, filter: array<string, int|string>|null}>  $nodes
     * @param  list<array{column: int, d: string, slot: int, value: int, from: string, to: string}>  $ribbons
     * @param  list<array{labels: list<string>, value: int}>  $paths  Every folded path, busiest first.
     * @param  list<float>  $columnX  Each column's left edge.
     * @param  int  $labelLength  How many characters of a label fit before the next column.
     * @param  int  $lastLabelLength  How many characters of a last-column label fit in the gutter.
     */
    public function __construct(
        public array $columns,
        public array $nodes,
        public array $ribbons,
        public array $paths,
        public int $total,
        public int $height,
        public array $columnX,
        public int $labelLength,
        public int $lastLabelLength,
    ) {
        //
    }

    /**
     * Fold and lay out paths through the given stages. A path may stop before the last stage.
     *
     * @param  list<string>  $columns
     * @param  iterable<array{nodes: list<FlowNode>, value: int}>  $paths
     */
    public static function fromPaths(array $columns, iterable $paths, int $nodesPerColumn = 8, int $colorSlots = 7): self
    {
        $columnCount = count($columns);
        $totals = array_fill(0, $columnCount, []);
        $descriptors = array_fill(0, $columnCount, []);
        $input = [];

        foreach ($paths as $path) {
            $nodes = array_slice($path['nodes'], 0, $columnCount);

            if ($path['value'] <= 0 || $nodes === []) {
                continue;
            }

            foreach ($nodes as $column => $node) {
                $totals[$column][$node->key] = ($totals[$column][$node->key] ?? 0) + $path['value'];
                $descriptors[$column][$node->key] = $node;
            }

            $input[] = ['keys' => array_map(fn (FlowNode $node) => $node->key, $nodes), 'value' => $path['value']];
        }

        // Keep each stage's busiest values; everything else becomes that stage's "Other" node. The first
        // stage keeps no more values than there are colors, so every named origin can be traced.
        $kept = [];

        foreach ($totals as $column => $columnTotals) {
            arsort($columnTotals);
            $limit = $column === 0 ? min($nodesPerColumn, $colorSlots) : $nodesPerColumn;
            $kept[$column] = array_flip(array_slice(array_map('strval', array_keys($columnTotals)), 0, $limit));
        }

        $merged = [];

        foreach ($input as $path) {
            $keys = [];

            foreach ($path['keys'] as $column => $key) {
                $keys[] = isset($kept[$column][$key]) ? $key : self::OTHER_KEY;
            }

            $signature = implode("\0", $keys);
            $merged[$signature] ??= ['keys' => $keys, 'value' => 0];
            $merged[$signature]['value'] += $path['value'];
        }

        // Node totals after folding, busiest first with "Other" last.
        $nodeTotals = array_fill(0, $columnCount, []);

        foreach ($merged as $path) {
            foreach ($path['keys'] as $column => $key) {
                $nodeTotals[$column][$key] = ($nodeTotals[$column][$key] ?? 0) + $path['value'];
            }
        }

        foreach ($nodeTotals as $column => $columnTotals) {
            uksort($columnTotals, function (string $a, string $b) use ($columnTotals) {
                if (($a === self::OTHER_KEY) !== ($b === self::OTHER_KEY)) {
                    return $a === self::OTHER_KEY ? 1 : -1;
                }

                return $columnTotals[$b] <=> $columnTotals[$a] ?: strcmp($a, $b);
            });
            $nodeTotals[$column] = $columnTotals;
        }

        // Color slots follow the first-stage value, assigned in key order so they don't track rank.
        $originKeys = array_values(array_filter(array_map('strval', array_keys($nodeTotals[0] ?? [])), fn (string $key) => $key !== self::OTHER_KEY));
        sort($originKeys, SORT_STRING);
        $slots = array_flip(array_slice($originKeys, 0, $colorSlots));
        $slotFor = fn (string $key): int => isset($slots[$key]) ? $slots[$key] + 1 : 0;

        // Vertical scale shared by every column, so a ribbon's thickness means the same everywhere.
        $height = max(self::MIN_HEIGHT, max(array_map('count', $nodeTotals ?: [[]])) * self::ROW_HEIGHT);
        $scale = INF;

        foreach ($nodeTotals as $columnTotals) {
            if ($columnTotals !== []) {
                $scale = min($scale, ($height - (count($columnTotals) - 1) * self::NODE_GAP) / array_sum($columnTotals));
            }
        }

        $scale = is_finite($scale) ? $scale : 0;
        $plotWidth = self::WIDTH - self::LABEL_GUTTER;
        $columnX = fn (int $column): float => $columnCount > 1 ? $column * $plotWidth / ($columnCount - 1) : 0.0;
        $spacing = $columnCount > 1 ? $plotWidth / ($columnCount - 1) : $plotWidth;

        $nodes = [];
        $layout = [];

        foreach ($nodeTotals as $column => $columnTotals) {
            $y = 0.0;
            $otherCount = count($totals[$column]) - count($kept[$column]);

            foreach ($columnTotals as $key => $value) {
                $key = (string) $key;
                $nodeHeight = max(1.0, $value * $scale);
                $descriptor = $descriptors[$column][$key] ?? null;

                $layout[$column][$key] = ['y' => $y, 'height' => $nodeHeight, 'order' => count($layout[$column] ?? [])];
                $nodes[] = [
                    'key' => $key,
                    'column' => $column,
                    'label' => $key === self::OTHER_KEY ? trans_choice('Other (:count value)|Other (:count values)', $otherCount) : $descriptor->label,
                    'detail' => $key === self::OTHER_KEY ? null : $descriptor->detail,
                    'value' => $value,
                    'x' => $columnX($column),
                    'y' => $y,
                    'height' => $nodeHeight,
                    'slot' => $column === 0 ? $slotFor($key) : 0,
                    'filter' => $key === self::OTHER_KEY ? null : $descriptor->filter,
                ];

                $y += $nodeHeight + self::NODE_GAP;
            }
        }

        return new self(
            columns: $columns,
            nodes: $nodes,
            ribbons: self::ribbons($merged, $layout, $columnCount, $scale, $columnX, $slotFor, $nodes),
            paths: self::tablePaths($merged, $nodes),
            total: array_sum(array_column($merged, 'value')),
            height: (int) ceil($height),
            columnX: array_map($columnX, range(0, max(0, $columnCount - 1))),
            labelLength: max(12, (int) floor(($spacing - self::NODE_WIDTH - 24) / self::LABEL_CHAR_WIDTH)),
            lastLabelLength: max(12, (int) floor((self::LABEL_GUTTER - self::NODE_WIDTH - 24) / self::LABEL_CHAR_WIDTH)),
        );
    }

    /**
     * Stack each pair of adjacent stages' ribbons inside their source and target nodes.
     *
     * @param  array<string, array{keys: list<string>, value: int}>  $merged
     * @param  array<int, array<string, array{y: float, height: float, order: int}>>  $layout
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array{column: int, d: string, slot: int, value: int, from: string, to: string}>
     */
    private static function ribbons(array $merged, array $layout, int $columnCount, float $scale, callable $columnX, callable $slotFor, array $nodes): array
    {
        $labels = [];

        foreach ($nodes as $node) {
            $labels[$node['column']][$node['key']] = $node['label'];
        }

        $ribbons = [];

        for ($column = 0; $column < $columnCount - 1; $column++) {
            $groups = [];

            foreach ($merged as $path) {
                if (count($path['keys']) <= $column + 1) {
                    continue;
                }

                [$from, $to] = [$path['keys'][$column], $path['keys'][$column + 1]];
                $slot = $slotFor($path['keys'][0]);
                $group = "{$from}\0{$to}\0{$slot}";

                $groups[$group] ??= ['from' => $from, 'to' => $to, 'slot' => $slot, 'value' => 0];
                $groups[$group]['value'] += $path['value'];
            }

            $sourceOffset = [];
            $targetOffset = [];
            $sourceY = [];
            $targetY = [];

            $bySource = $groups;
            uasort($bySource, fn (array $a, array $b) => [$layout[$column][$a['from']]['order'], $layout[$column + 1][$a['to']]['order'], $a['slot']]
                <=> [$layout[$column][$b['from']]['order'], $layout[$column + 1][$b['to']]['order'], $b['slot']]);

            foreach ($bySource as $id => $group) {
                $sourceY[$id] = $layout[$column][$group['from']]['y'] + ($sourceOffset[$group['from']] ?? 0);
                $sourceOffset[$group['from']] = ($sourceOffset[$group['from']] ?? 0) + $group['value'] * $scale;
            }

            $byTarget = $groups;
            uasort($byTarget, fn (array $a, array $b) => [$layout[$column + 1][$a['to']]['order'], $layout[$column][$a['from']]['order'], $a['slot']]
                <=> [$layout[$column + 1][$b['to']]['order'], $layout[$column][$b['from']]['order'], $b['slot']]);

            foreach ($byTarget as $id => $group) {
                $targetY[$id] = $layout[$column + 1][$group['to']]['y'] + ($targetOffset[$group['to']] ?? 0);
                $targetOffset[$group['to']] = ($targetOffset[$group['to']] ?? 0) + $group['value'] * $scale;
            }

            $x0 = $columnX($column) + self::NODE_WIDTH;
            $x1 = $columnX($column + 1);
            $xm = ($x0 + $x1) / 2;

            foreach ($bySource as $id => $group) {
                $thickness = max(0.5, $group['value'] * $scale);
                [$y0, $y1] = [$sourceY[$id], $targetY[$id]];

                $ribbons[] = [
                    'column' => $column,
                    'd' => sprintf(
                        'M%.1f,%.1f C%.1f,%.1f %.1f,%.1f %.1f,%.1f L%.1f,%.1f C%.1f,%.1f %.1f,%.1f %.1f,%.1f Z',
                        $x0, $y0, $xm, $y0, $xm, $y1, $x1, $y1,
                        $x1, $y1 + $thickness, $xm, $y1 + $thickness, $xm, $y0 + $thickness, $x0, $y0 + $thickness,
                    ),
                    'slot' => $group['slot'],
                    'value' => $group['value'],
                    'from' => $labels[$column][$group['from']],
                    'to' => $labels[$column + 1][$group['to']],
                ];
            }
        }

        return $ribbons;
    }

    /**
     * @param  array<string, array{keys: list<string>, value: int}>  $merged
     * @param  list<array<string, mixed>>  $nodes
     * @return list<array{labels: list<string>, value: int}>
     */
    private static function tablePaths(array $merged, array $nodes): array
    {
        $labels = [];

        foreach ($nodes as $node) {
            $labels[$node['column']][$node['key']] = $node['label'];
        }

        $paths = array_map(fn (array $path) => [
            'labels' => array_map(fn (string $key, int $column) => $labels[$column][$key], $path['keys'], array_keys($path['keys'])),
            'value' => $path['value'],
        ], array_values($merged));

        usort($paths, fn (array $a, array $b) => $b['value'] <=> $a['value']);

        return $paths;
    }

    /**
     * Determine if there is nothing to draw.
     */
    public function isEmpty(): bool
    {
        return $this->total === 0;
    }
}
