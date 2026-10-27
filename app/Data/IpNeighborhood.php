<?php

namespace App\Data;

use Illuminate\Support\Number;

/**
 * How busy an IPv4 address and the networks around it were: its own requests, and those of its /24,
 * /16 and /8, with how many hosts its /24 holds.
 */
readonly class IpNeighborhood
{
    public function __construct(
        public string $ip,
        public int $count8,
        public int $count16,
        public int $count24,
        public int $count32,
        public int $distinctHosts24,
    ) {
        //
    }

    /**
     * Rebuild one from what {@see toArray()} gave the cache.
     *
     * @param  array{ip: string, count8: int, count16: int, count24: int, count32: int, distinctHosts24: int}  $attributes
     */
    public static function fromArray(array $attributes): self
    {
        return new self(...$attributes);
    }

    /**
     * @return array{ip: string, count8: int, count16: int, count24: int, count32: int, distinctHosts24: int}
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }

    /**
     * Each octet of the IP with the prefix it ends (/8 for the first, /32 for the last) and that prefix's
     * requests, described the way its tooltip reads: "/24 · 412 hits · 14 IPs".
     *
     * @return list<array{octet: string, prefix: int, count: int, label: string}>
     */
    public function rungs(): array
    {
        $counts = [8 => $this->count8, 16 => $this->count16, 24 => $this->count24, 32 => $this->count32];

        return array_map(function (string $octet, int $prefix) use ($counts) {
            $label = '/'.$prefix.' · '.trans_choice(':count hit|:count hits', $counts[$prefix], ['count' => Number::format($counts[$prefix])]);

            if ($prefix === 24) {
                $label .= ' · '.trans_choice(':count IP|:count IPs', $this->distinctHosts24, ['count' => Number::format($this->distinctHosts24)]);
            }

            return ['octet' => $octet, 'prefix' => $prefix, 'count' => $counts[$prefix], 'label' => $label];
        }, explode('.', $this->ip), array_keys($counts));
    }

    /**
     * What stands out about the IP's place in its /24: one of many hosts sharing it, or nearly the
     * whole of it.
     *
     * @return array{label: string, color: string}|null
     */
    public function badge(): ?array
    {
        if ($this->count24 === 0) {
            return null;
        }

        $share = $this->count32 / $this->count24;

        return match (true) {
            $this->distinctHosts24 >= config('activity.neighborhood.noisy_min_hosts') && $share < config('activity.neighborhood.noisy_max_share') => [
                'label' => __('Noisy /24 · :count hosts', ['count' => Number::format($this->distinctHosts24)]),
                'color' => 'amber',
            ],
            $share > config('activity.neighborhood.lone_min_share') => ['label' => __('Lone host'), 'color' => 'sky'],
            default => null,
        };
    }
}
