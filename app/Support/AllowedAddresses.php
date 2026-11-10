<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Every IP address and CIDR range the installation allows past its enforcements, gathered from every on
 * add-on that fills the list (AllowedAddressSource: the White List add-on's entries, the Nice Bots
 * add-on's good-bot ranges). A visitor whose address is in here is never sent to the gym, held in a
 * tarpit, blocked, or dropped in the blackhole — but is still seen in Activity and reports, so an
 * allowed crawler stays visible. Nothing to do with lockdown's own allow list, which lets an address
 * past one team's maintenance wall.
 *
 * An add-on that's turned off (or paid and unlicensed) drops out of the list on the next request, as
 * its contributor isn't among AddonSwitchboard::active(). The core itself allows nothing.
 *
 * Scoped, so the list is gathered once a request and, under Octane, afresh each request.
 */
#[Scoped]
class AllowedAddresses
{
    /**
     * The container tag contributors register under.
     */
    public const string TAG = 'edge.allowed';

    /**
     * @var list<string>|null
     */
    private ?array $ranges = null;

    public function __construct(private Container $container, private AddonSwitchboard $switchboard)
    {
        //
    }

    /**
     * Every allowed IP and CIDR range, from the on add-ons that fill the list, deduplicated.
     *
     * @return list<string>
     */
    public function ranges(): array
    {
        if ($this->ranges !== null) {
            return $this->ranges;
        }

        $seen = [];

        foreach ($this->switchboard->active($this->container->tagged(self::TAG)) as $source) {
            if ($source instanceof AllowedAddressSource) {
                foreach ($source->allowedRanges() as $range) {
                    $seen[$range] = true;
                }
            }
        }

        // array_keys gives a proper list (first-seen order, deduplicated), so the edge feed is a JSON array.
        return $this->ranges = array_keys($seen);
    }

    /**
     * Determine if an address is allowed: it's a valid IP that falls in one of the ranges.
     */
    public function allows(string $ip): bool
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        $ranges = $this->ranges();

        return $ranges !== [] && IpUtils::checkIp($ip, $ranges);
    }

    /**
     * Forget the gathered list, so the next call reads the sources again (e.g. after an entry changed
     * within the request).
     */
    public function forget(): void
    {
        $this->ranges = null;
    }
}
