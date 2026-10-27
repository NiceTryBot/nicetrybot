<?php

use App\Data\FlowNode;
use App\Data\SankeyDiagram;

/**
 * @param  list<string>  $keys
 * @return array{nodes: list<FlowNode>, value: int}
 */
function flowPath(array $keys, int $value): array
{
    return ['nodes' => array_map(fn (string $key) => new FlowNode($key, ucfirst($key)), $keys), 'value' => $value];
}

test('values past the per-stage limit fold into one Other node without losing volume', function () {
    $diagram = SankeyDiagram::fromPaths(['Network', 'Agent'], [
        flowPath(['a', 'x'], 50),
        flowPath(['b', 'x'], 30),
        flowPath(['c', 'y'], 5),
        flowPath(['d', 'y'], 2),
    ], nodesPerColumn: 2);

    $firstStage = collect($diagram->nodes)->where('column', 0);

    expect($firstStage->pluck('label')->all())->toBe(['A', 'B', 'Other (2 values)'])
        ->and($firstStage->firstWhere('key', SankeyDiagram::OTHER_KEY)['value'])->toBe(7)
        ->and($diagram->total)->toBe(87);
});

test('the ribbons between two stages carry exactly the volume that reaches the second stage', function () {
    $diagram = SankeyDiagram::fromPaths(['Network', 'Fingerprint', 'Agent'], [
        flowPath(['a', 'f1', 'x'], 10),
        flowPath(['a', 'f2', 'y'], 4),
        flowPath(['b', 'f1', 'x'], 6),
    ]);

    $ribbons = collect($diagram->ribbons);

    expect($ribbons->where('column', 0)->sum('value'))->toBe(20)
        ->and($ribbons->where('column', 1)->sum('value'))->toBe(20)
        ->and($ribbons->where('column', 0)->firstWhere(fn (array $ribbon) => $ribbon['from'] === 'A' && $ribbon['to'] === 'F1')['value'])->toBe(10);
});

test('an origin keeps its color when the busiest origins swap places', function () {
    $quiet = SankeyDiagram::fromPaths(['Network', 'Agent'], [flowPath(['ovh', 'x'], 9), flowPath(['comcast', 'x'], 1)]);
    $busy = SankeyDiagram::fromPaths(['Network', 'Agent'], [flowPath(['ovh', 'x'], 1), flowPath(['comcast', 'x'], 9)]);

    $slot = fn (SankeyDiagram $diagram, string $key) => collect($diagram->nodes)->firstWhere('key', $key)['slot'];

    expect($slot($quiet, 'ovh'))->toBe($slot($busy, 'ovh'))
        ->and($slot($quiet, 'comcast'))->toBe($slot($busy, 'comcast'))
        ->and($slot($quiet, 'ovh'))->not->toBe($slot($quiet, 'comcast'));
});

test('a path that stops early only adds nodes to the stages it reaches', function () {
    $diagram = SankeyDiagram::fromPaths(['Entry', 'Step 2', 'Step 3'], [
        flowPath(['/', '/claim', '/thanks'], 3),
        flowPath(['/', '/claim'], 2),
        flowPath(['/'], 5),
    ]);

    $totals = collect($diagram->nodes)->groupBy('column')->map->sum('value');

    expect($totals->all())->toBe([0 => 10, 1 => 5, 2 => 3]);
});
