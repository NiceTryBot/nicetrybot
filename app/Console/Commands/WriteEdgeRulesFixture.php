<?php

namespace App\Console\Commands;

use App\Data\EdgeExpression;
use App\Data\LiveRequest;
use App\Data\RuleScript;
use App\Enums\HostingProvider;
use App\Enums\NetworkKind;
use App\Enums\PathCategory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rules:edge-fixture {path=edge/caddy/nicetry-capture/testdata/live_rules.json : Where to write it, from the app\'s folder}')]
#[Description('Write the rules the edge judges, and what LiveRequest says of sample requests, for the edge\'s tests')]
class WriteEdgeRulesFixture extends Command
{
    /**
     * Rules covering what the edge judges: fields it reads itself, ones from its IP database, path
     * categories, methods, ranges and missing values.
     *
     * @var array<string, string>
     */
    private const array RULES = [
        'non-us app traffic' => "host = 'sign.example' AND country != 'US' AND NOT path LIKE '/sign/%'",
        'scripts' => "ua LIKE '%curl%' OR lang IS NULL",
        'login posts' => "method IN ('POST', 'PUT') AND path = '/login'",
        'probes' => "category = 'probe'",
        'probe slugs outside a range' => "category LIKE 'probe/%' AND NOT ip = '10.0.0.0/8'",
        'hosting but not one network' => "network.kind = 'hosting' AND asn != 14061",
        'no alpn' => "ja4.alpn = '00'",
        'edge tag' => "tag = 'probe'",
        'a city with a region' => "city = 'Tiranë' AND region IS NOT NULL",
        'network name' => "network LIKE '%cyberzone%'",
        'not an asset' => "category IS NULL OR category != 'asset'",
        'asn range' => 'asn >= 20000 AND asn < 30000',
        'other method' => "method = 'OTHER'",
        'every request' => '',
    ];

    /**
     * Sample requests on sign.example, as the edge describes them.
     *
     * @var list<array<string, string|int|null>>
     */
    private const array REQUESTS = [
        ['method' => 'GET', 'path' => '/phpmyadmin.php', 'ip' => '203.0.113.188', 'ja4' => 't13d1516h2_8daaf6152771_02713d6af862', 'ua' => 'Mozilla/5.0 Chrome/141.0', 'lang' => 'en-US,en;q=0.9', 'country' => 'AL', 'region' => 'Tirana', 'city' => 'Tiranë', 'asn' => 42313, 'network' => 'Cyberzone S.A.', 'tag' => 'probe'],
        ['method' => 'POST', 'path' => '/login', 'ip' => '198.51.100.4', 'ja4' => 't13d151600_8daaf6152771_02713d6af862', 'ua' => 'curl/8.7.1', 'lang' => null, 'country' => 'US', 'region' => 'California', 'city' => 'Santa Clara', 'asn' => 14061, 'network' => 'DigitalOcean, LLC', 'tag' => null],
        ['method' => 'GET', 'path' => '/sign/abc', 'ip' => '10.1.2.3', 'ja4' => null, 'ua' => null, 'lang' => 'de', 'country' => 'DE', 'region' => null, 'city' => null, 'asn' => 24940, 'network' => 'Hetzner Online GmbH', 'tag' => null],
        ['method' => 'GET', 'path' => '/favicon.ico', 'ip' => '203.0.113.9', 'ja4' => 't13d1516h2_8daaf6152771_02713d6af862', 'ua' => 'Mozilla/5.0 Safari/605', 'lang' => 'sq', 'country' => 'AL', 'region' => null, 'city' => null, 'asn' => null, 'network' => null, 'tag' => null],
        ['method' => 'PROPFIND', 'path' => '/.env', 'ip' => '10.9.9.9', 'ja4' => 't13d1516h2_8daaf6152771_02713d6af862', 'ua' => 'python-requests/2.32', 'lang' => null, 'country' => null, 'region' => null, 'city' => null, 'asn' => 16509, 'network' => 'Amazon.com, Inc.', 'tag' => 'probe'],
    ];

    /**
     * Write the fixture the edge's tests hold its rule evaluation to: each rule as EdgeExpression sends
     * it, and what LiveRequest says of each sample request under it. Run it whenever either changes,
     * then run the edge's tests.
     */
    public function handle(): int
    {
        $cases = [];

        foreach (self::RULES as $name => $where) {
            $script = RuleScript::parse("TAG ip AS 'x' FOR 1 DAY".($where === '' ? '' : " WHERE {$where}"));

            $cases[] = [
                'name' => $name,
                'where' => $where,
                'expr' => EdgeExpression::forScript($script),
                'expect' => array_map(fn (array $facts) => $script->where === null ? true : $this->live($facts)->evaluate($script->where), self::REQUESTS),
            ];
        }

        $path = base_path($this->argument('path'));

        file_put_contents($path, json_encode([
            'note' => 'Written by NiceTryBot (php artisan rules:edge-fixture) from App\\Data\\EdgeExpression and App\\Data\\LiveRequest: each case expects what LiveRequest::evaluate says of each request.',
            'categories' => array_map(fn (PathCategory $category) => ['id' => $category->value, 'patterns' => $category->patterns()], PathCategory::cases()),
            'networks' => [['kind' => NetworkKind::Hosting->value, 'asns' => collect(HostingProvider::cases())->flatMap(fn (HostingProvider $provider) => $provider->asns())->unique()->sort()->values()->all()]],
            'host' => 'sign.example',
            'requests' => self::REQUESTS,
            'cases' => $cases,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

        $this->info(sprintf('Wrote %d rules against %d requests to %s.', count($cases), count(self::REQUESTS), $path));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string|int|null>  $facts
     */
    private function live(array $facts): LiveRequest
    {
        return new LiveRequest(
            (string) $facts['method'], 'sign.example', (string) $facts['path'],
            ip: $facts['ip'], ja4: $facts['ja4'], userAgent: $facts['ua'], described: true,
            language: $facts['lang'], countryCode: $facts['country'], region: $facts['region'], city: $facts['city'],
            asn: $facts['asn'], network: $facts['network'], tag: $facts['tag'],
        );
    }
}
