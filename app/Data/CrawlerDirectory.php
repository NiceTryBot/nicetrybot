<?php

namespace App\Data;

use App\Enums\VerifiedCrawler;
use App\Models\CrawlerIpRange;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The published crawler ranges, loaded once per request so every IP on a page can be checked without a
 * query each. Bound as a scoped singleton.
 */
class CrawlerDirectory
{
    /**
     * @var array<string, list<string>>|null
     */
    private ?array $ranges = null;

    /**
     * @var array<string, VerifiedCrawler|null>
     */
    private array $resolved = [];

    /**
     * Get the crawler whose published ranges include an IP, if any.
     */
    public function crawlerFor(?string $ip): ?VerifiedCrawler
    {
        if ($ip === null || $ip === '') {
            return null;
        }

        if (array_key_exists($ip, $this->resolved)) {
            return $this->resolved[$ip];
        }

        foreach ($this->ranges() as $crawler => $cidrs) {
            if (IpUtils::checkIp($ip, $cidrs)) {
                return $this->resolved[$ip] = VerifiedCrawler::from($crawler);
            }
        }

        return $this->resolved[$ip] = null;
    }

    /**
     * @return array<string, list<string>>
     */
    private function ranges(): array
    {
        return $this->ranges ??= CrawlerIpRange::query()->toBase()->get(['crawler', 'cidr'])
            ->groupBy('crawler')
            ->map(fn ($ranges) => $ranges->pluck('cidr')->all())
            ->all();
    }
}
