<?php

namespace NiceTry\TrafficMap\Enums;

/**
 * What the Traffic Map groups edge requests into tiles by.
 */
enum MapDimension: string
{
    case Domain = 'domain';
    case Team = 'team';
    case Ip = 'ip';
    case BotTag = 'bottag';
    case Country = 'country';
    case Path = 'path';
    case Client = 'client';
    case NetworkCategory = 'category';

    public function label(): string
    {
        return match ($this) {
            self::Domain => __('Domain'),
            self::Team => __('Team'),
            self::Ip => __('IP'),
            self::BotTag => __('Bot tag'),
            self::Country => __('Country'),
            self::Path => __('Path'),
            self::Client => __('Client'),
            self::NetworkCategory => __('ASN category'),
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Domain => __('One tile per site taking traffic.'),
            self::Team => __('One tile per team (across the fleet).'),
            self::Ip => __('One tile per client IP address.'),
            self::BotTag => __('One tile per rule that tagged the traffic.'),
            self::Country => __('One tile per country the traffic came from.'),
            self::Path => __('One tile per path category (probes, assets, pages).'),
            self::Client => __('One tile per client profile (clean, spoofed, outdated).'),
            self::NetworkCategory => __('One tile per kind of network the traffic came from (hosting, ISP, business).'),
        };
    }

    /**
     * The dimensions offered on a single team's Activity and Traffic Map pages (Team only makes sense
     * across the whole fleet, so it's left out here).
     *
     * @return list<self>
     */
    public static function forTeam(): array
    {
        return [self::Domain, self::Country, self::Ip, self::NetworkCategory, self::Path, self::BotTag, self::Client];
    }
}
