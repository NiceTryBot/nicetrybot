<?php

namespace App\Console\Commands;

use App\Enums\VerifiedCrawler;
use App\Models\CrawlerIpRange;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\IpUtils;

#[Signature('crawlers:refresh')]
#[Description('Download the IP ranges crawler operators publish (e.g. OpenAI GPTBot) so their traffic is recognised')]
class RefreshCrawlerRanges extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $failed = false;

        foreach (VerifiedCrawler::cases() as $crawler) {
            try {
                $cidrs = $this->download($crawler);
            } catch (RequestException $exception) {
                $this->error(sprintf('%s: %s', $crawler->label(), $exception->getMessage()));
                $failed = true;

                continue;
            }

            // An empty or unreadable list keeps the ranges we have rather than forgetting the crawler.
            if ($cidrs === []) {
                $this->warn(sprintf('%s: the published list had no ranges; kept the %d we have.', $crawler->label(), CrawlerIpRange::query()->where('crawler', $crawler)->count()));
                $failed = true;

                continue;
            }

            DB::transaction(function () use ($crawler, $cidrs) {
                CrawlerIpRange::query()->where('crawler', $crawler)->delete();

                foreach ($cidrs as $cidr) {
                    CrawlerIpRange::query()->create(['crawler' => $crawler, 'cidr' => $cidr]);
                }
            });

            $this->info(sprintf('%s: %d range(s).', $crawler->label(), count($cidrs)));
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Download a crawler's ranges, keeping only valid CIDRs.
     *
     * @return list<string>
     *
     * @throws RequestException
     */
    private function download(VerifiedCrawler $crawler): array
    {
        $prefixes = Http::timeout(20)->acceptJson()->get($crawler->rangesUrl())->throw()->json('prefixes');

        return collect(is_array($prefixes) ? $prefixes : [])
            ->map(fn ($prefix) => is_array($prefix) ? ($prefix['ipv4Prefix'] ?? $prefix['ipv6Prefix'] ?? null) : null)
            ->filter(fn ($cidr) => is_string($cidr) && $this->isCidr($cidr))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Determine if a string is an IPv4 or IPv6 range in CIDR notation.
     */
    private function isCidr(string $cidr): bool
    {
        [$address, $bits] = array_pad(explode('/', $cidr, 2), 2, null);

        if (filter_var($address, FILTER_VALIDATE_IP) === false || ! ctype_digit((string) $bits)) {
            return false;
        }

        return (int) $bits <= (str_contains($address, ':') ? 128 : 32) && IpUtils::checkIp($address, $cidr);
    }
}
