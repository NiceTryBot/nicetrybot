<?php

namespace App\Actions\Domains;

use App\Actions\Edge\ResolveLookupIds;
use App\Enums\DomainStatus;
use App\Enums\HttpMethod;
use App\Enums\ProtectionMode;
use App\Enums\RequestAction;
use App\Models\Domain;
use App\Models\Location;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Fills a domain with believable, fictional edge traffic so dashboards have something to show
 * before a real integration ships logs. Every address comes from the documentation ranges.
 */
class GenerateSampleTraffic
{
    /**
     * Human page views and how often each is requested, with the status the origin returns.
     *
     * @var array<int, array{0: string, 1: int, 2: int}>
     */
    private const array HUMAN_PATHS = [
        ['/', 30, 200],
        ['/claim', 14, 200],
        ['/claim/status', 8, 200],
        ['/faq', 8, 200],
        ['/documents/notice.pdf', 6, 200],
        ['/contact', 4, 200],
        ['/build/assets/app.js', 10, 200],
        ['/build/assets/app.css', 10, 200],
        ['/robots.txt', 2, 200],
        ['/claim/submit', 3, 302],
        ['/notice', 2, 301],
        ['/favicon.ico', 3, 404],
        ['/apple-touch-icon.png', 3, 404],
        ['/apple-touch-icon-precomposed.png', 2, 404],
        ['/old-notice', 1, 404],
        ['/claim/form-2025', 1, 404],
    ];

    /**
     * Scanner paths and the tag the edge's static rules give them.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const array PROBE_PATHS = [
        ['/.env', 'dotfile-probe'],
        ['/.git/config', 'dotfile-probe'],
        ['/dashboard/.env', 'dotfile-probe'],
        ['/.aws/credentials', 'dotfile-probe'],
        ['/config.json', 'dotfile-probe'],
        ['/wp-admin/install.php', 'cms-probe'],
        ['/wp-login.php', 'cms-probe'],
        ['/xmlrpc.php', 'cms-probe'],
        ['/_ignition/execute-solution', 'framework-probe'],
        ['/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', 'framework-probe'],
        ['/phpmyadmin/', 'admin-probe'],
        ['/server-status', 'admin-probe'],
        ['/login', 'credential-probe'],
        ['/dashboard', 'credential-probe'],
        ['/cgi-bin/luci', 'router-exploit'],
    ];

    /**
     * @var array<int, string>
     */
    private const array HUMAN_AGENTS = [
        'Mozilla/5.0 (iPhone; CPU iPhone OS 18_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.6 Mobile/15E148 Safari/604.1',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.5 Safari/605.1.15',
        'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Mobile Safari/537.36',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:143.0) Gecko/20100101 Firefox/143.0',
    ];

    /**
     * @var array<int, string>
     */
    private const array BOT_AGENTS = [
        'python-requests/2.32.3',
        'curl/8.5.0',
        'Go-http-client/1.1',
        'Mozilla/5.0 zgrab/0.x',
        'Mozilla/5.0 (compatible; Nmap Scripting Engine; https://nmap.org/book/nse.html)',
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36',
    ];

    /**
     * @var array<int, string>
     */
    private const array BOT_COUNTRIES = ['NL', 'DE', 'SG', 'US', 'BR', 'VN', 'FR', 'IN'];

    private Randomizer $random;

    public function __construct(private ResolveLookupIds $resolveLookupIds)
    {
        //
    }

    /**
     * Generate sample traffic for the domain and return the number of requests created.
     */
    public function handle(Domain $domain, int $days = 7, ?CarbonImmutable $until = null): int
    {
        $this->random = new Randomizer(new Mt19937($domain->id));

        $until ??= CarbonImmutable::now();
        $hour = $until->startOfHour()->subDays($days);
        $rows = [];

        while ($hour->lessThanOrEqualTo($until)) {
            array_push($rows, ...$this->humanRequestsFor($domain, $hour, $until));
            array_push($rows, ...$this->botRequestsFor($domain, $hour, $until));

            $hour = $hour->addHour();
        }

        DB::transaction(function () use ($rows) {
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('edge_requests')->insert($this->withLookupIds($chunk));
            }
        });

        return count($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function humanRequestsFor(Domain $domain, CarbonImmutable $hour, CarbonImmutable $until): array
    {
        $hourOfDay = $hour->hour;
        $daytime = $hourOfDay >= 6 && $hourOfDay <= 22 ? sin(M_PI * ($hourOfDay - 6) / 16) : 0;
        $count = (int) round(4 + 18 * $daytime + $this->random->getInt(0, 4));
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            [$path, , $status] = $this->weightedHumanPath();

            if ($status === 200 && $this->random->getInt(1, 500) === 1) {
                $status = 500;
            }

            $isAsset = str_starts_with($path, '/build/') || $status === 404;

            $rows[] = $this->row($domain, $hour, $until, [
                'client_ip' => $this->humanIp(),
                'country_code' => $this->random->getInt(1, 10) <= 8 ? 'US' : ($this->random->getInt(0, 1) ? 'CA' : 'GB'),
                'method' => $path === '/claim/submit' ? 'POST' : 'GET',
                'path' => $path,
                'status_code' => $status,
                'duration_ms' => $isAsset ? $this->random->getInt(4, 40) : $this->random->getInt(45, 380),
                'user_agent' => self::HUMAN_AGENTS[$this->random->getInt(0, count(self::HUMAN_AGENTS) - 1)],
                'action' => RequestAction::Passed->value,
                'bot_tag' => null,
            ]);
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function botRequestsFor(Domain $domain, CarbonImmutable $hour, CarbonImmutable $until): array
    {
        $isSweep = $this->random->getInt(1, 100) <= 7;
        $count = $isSweep ? $this->random->getInt(25, 70) : $this->random->getInt(1, 6);
        $sweepIp = $this->botIp();
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            [$path, $tag] = self::PROBE_PATHS[$this->random->getInt(0, count(self::PROBE_PATHS) - 1)];
            $ip = $isSweep ? $sweepIp : $this->botIp();

            $rows[] = $this->row($domain, $hour, $until, [
                'client_ip' => $ip,
                'country_code' => self::BOT_COUNTRIES[crc32($ip) % count(self::BOT_COUNTRIES)],
                'method' => $tag === 'credential-probe' && $this->random->getInt(0, 1) ? 'POST' : 'GET',
                'path' => $path,
                'user_agent' => self::BOT_AGENTS[crc32($ip) % count(self::BOT_AGENTS)],
                ...$this->botOutcome($domain, $tag, $ip),
            ]);
        }

        return $rows;
    }

    /**
     * Decide what the edge did with a scanner request, based on the domain's protection mode.
     *
     * @return array{status_code: int, duration_ms: int, action: int, bot_tag: ?string}
     */
    private function botOutcome(Domain $domain, string $tag, string $ip): array
    {
        $isProtected = $domain->status === DomainStatus::Active;

        if (! $isProtected || $domain->protection_mode === ProtectionMode::Off) {
            return ['status_code' => 404, 'duration_ms' => $this->random->getInt(30, 220), 'action' => RequestAction::Passed->value, 'bot_tag' => null];
        }

        if ($domain->protection_mode === ProtectionMode::Observe) {
            return ['status_code' => 404, 'duration_ms' => $this->random->getInt(30, 220), 'action' => RequestAction::Observed->value, 'bot_tag' => $tag];
        }

        if (crc32($ip) % 4 === 0) {
            return ['status_code' => 403, 'duration_ms' => $this->random->getInt(1, 6), 'action' => RequestAction::Blocked->value, 'bot_tag' => 'ban-list'];
        }

        return ['status_code' => 200, 'duration_ms' => $this->random->getInt(30_000, 480_000), 'action' => RequestAction::Diverted->value, 'bot_tag' => $tag];
    }

    /**
     * Swap the generated strings for lookup-table ids and enum codes, as the edge importer does.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withLookupIds(array $rows): array
    {
        $paths = $this->resolveLookupIds->paths(array_column($rows, 'path'));
        $userAgents = $this->resolveLookupIds->userAgents(array_column($rows, 'user_agent'));
        $botTags = $this->resolveLookupIds->botTags(array_filter(array_column($rows, 'bot_tag')));
        $locations = $this->resolveLookupIds->locations(array_map(fn (string $countryCode) => [$countryCode, '', ''], array_unique(array_column($rows, 'country_code'))));

        return array_map(fn (array $row) => [
            'domain_id' => $row['domain_id'],
            'occurred_at' => $row['occurred_at'],
            'client_ip' => $row['client_ip'],
            'method' => HttpMethod::fromVerb($row['method'])->value,
            'path_id' => $paths[$row['path']],
            'status_code' => $row['status_code'],
            'duration_ms' => $row['duration_ms'],
            'user_agent_id' => $userAgents[$row['user_agent']],
            'action' => $row['action'],
            'bot_tag_id' => $row['bot_tag'] === null ? null : $botTags[$row['bot_tag']],
            'location_id' => $locations[Location::key($row['country_code'], '', '')],
        ], $rows);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function row(Domain $domain, CarbonImmutable $hour, CarbonImmutable $until, array $attributes): array
    {
        $occurredAt = $hour->addSeconds($this->random->getInt(0, 3599));

        if ($occurredAt->greaterThan($until)) {
            $occurredAt = $until->subSeconds($this->random->getInt(0, 59));
        }

        return ['domain_id' => $domain->id, 'occurred_at' => $occurredAt->toDateTimeString(), ...$attributes];
    }

    /**
     * @return array{0: string, 1: int, 2: int}
     */
    private function weightedHumanPath(): array
    {
        $total = array_sum(array_column(self::HUMAN_PATHS, 1));
        $pick = $this->random->getInt(1, $total);

        foreach (self::HUMAN_PATHS as $path) {
            $pick -= $path[1];

            if ($pick <= 0) {
                return $path;
            }
        }

        return self::HUMAN_PATHS[0];
    }

    private function humanIp(): string
    {
        return $this->random->getInt(1, 5) === 1
            ? sprintf('2001:db8:%x::%x', $this->random->getInt(1, 40), $this->random->getInt(1, 255))
            : '198.51.100.'.$this->random->getInt(1, 120);
    }

    private function botIp(): string
    {
        return $this->random->getInt(1, 6) === 1
            ? sprintf('2001:db8:bad::%x', $this->random->getInt(1, 30))
            : '203.0.113.'.$this->random->getInt(1, 60);
    }
}
