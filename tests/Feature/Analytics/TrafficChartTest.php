<?php

/**
 * Bars of a minute each, with the given requests (all human).
 *
 * @param  list<int>  $requests
 * @return list<array<string, mixed>>
 */
function chartBucketsOf(array $requests): array
{
    return array_map(fn (int $count, int $index) => [
        'key' => "bar-{$index}",
        'label' => sprintf('22:%02d', $index),
        'start' => $index * 60,
        'end' => ($index + 1) * 60,
        'humans' => $count,
        'suspicious' => 0,
        'notFound' => 0,
    ], $requests, array_keys($requests));
}

test('a burst far above the usual traffic is cut short, so the usual bars keep their height', function () {
    $view = $this->blade('<x-ntb.traffic-chart :buckets="$buckets" />', [
        'buckets' => chartBucketsOf([800, 750, 820, 790, 810, 760, 800, 780, 5_000, 28_000, 42_000, 6_000]),
    ]);

    $view->assertSee('Cut short to fit')
        ->assertSee('42,000')
        ->assertSeeInOrder(['1K', '750'])
        ->assertDontSee('50K');

    expect(substr_count((string) $view, 'data-test="chart-bar-cut-short"'))->toBe(4);
});

test('traffic without a burst, or too few bars to tell, is drawn to scale', function (array $requests) {
    $view = $this->blade('<x-ntb.traffic-chart :buckets="$buckets" />', ['buckets' => chartBucketsOf($requests)]);

    $view->assertDontSee('Cut short to fit')->assertDontSeeHtml('chart-bar-cut-short');
})->with([
    'steady' => [[800, 750, 820, 790, 810, 760, 800, 780, 900, 1_200]],
    'few bars' => [[10, 0, 0, 12, 40_000]],
]);
