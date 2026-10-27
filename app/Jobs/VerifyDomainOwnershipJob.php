<?php

namespace App\Jobs;

use App\Actions\Domains\VerifyDomainOwnership;
use App\Models\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Look for a domain's ownership TXT record off the request, leaving the outcome in the cache for the
 * domain page to poll: checking, then found or missing.
 */
class VerifyDomainOwnershipJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * A resolver that hangs shouldn't hold a worker for long.
     */
    public int $timeout = 30;

    public function __construct(public Domain $domain)
    {
        //
    }

    /**
     * Queue a check of the domain's TXT record, marking it in progress.
     */
    public static function start(Domain $domain): void
    {
        self::remember($domain, 'checking');

        self::dispatch($domain);
    }

    /**
     * Get the latest check of the domain's TXT record: its state (checking, found or missing) and when it
     * reached it, or null when there hasn't been one lately.
     *
     * @return array{state: string, at: int}|null
     */
    public static function latest(Domain $domain): ?array
    {
        return Cache::get(self::cacheKey($domain));
    }

    public function handle(VerifyDomainOwnership $verifyDomainOwnership): void
    {
        self::remember($this->domain, $verifyDomainOwnership->handle($this->domain) ? 'found' : 'missing');
    }

    public function failed(?Throwable $exception): void
    {
        self::remember($this->domain, 'missing');
    }

    private static function remember(Domain $domain, string $state): void
    {
        Cache::put(self::cacheKey($domain), ['state' => $state, 'at' => now()->getTimestamp()], now()->addMinutes(10));
    }

    private static function cacheKey(Domain $domain): string
    {
        return 'domains.'.$domain->getKey().'.ownership-check';
    }
}
