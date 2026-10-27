<?php

namespace App\Enums;

/**
 * A cloud or hosting provider whose traffic is almost never a person at a browser, recognised by the
 * ASNs it announces. This curated list is what makes a network count as NetworkKind::Hosting.
 */
enum HostingProvider: string
{
    case Aws = 'aws';
    case Google = 'google';
    case Azure = 'azure';
    case DigitalOcean = 'digitalocean';
    case Ovh = 'ovh';
    case Hetzner = 'hetzner';
    case Vultr = 'vultr';
    case Linode = 'linode';
    case M247 = 'm247';
    case Hostinger = 'hostinger';
    case ColocationAmerica = 'colocation-america';
    case EgiHosting = 'egihosting';
    case Contabo = 'contabo';
    case Scaleway = 'scaleway';
    case Leaseweb = 'leaseweb';
    case Tzulo = 'tzulo';
    case Oracle = 'oracle';
    case Alibaba = 'alibaba';
    case Tencent = 'tencent';
    case ColoCrossing = 'colocrossing';
    case FranTech = 'frantech';
    case Clouvider = 'clouvider';
    case Datacamp = 'datacamp';
    case Cloudflare = 'cloudflare';

    /**
     * Find the provider that announces an autonomous system, if it's a known hosting network.
     */
    public static function forAsn(int $asn): ?self
    {
        foreach (self::cases() as $provider) {
            if (in_array($asn, $provider->asns(), true)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * Get the autonomous system numbers the provider announces.
     *
     * @return list<int>
     */
    public function asns(): array
    {
        return match ($this) {
            self::Aws => [16509, 14618, 8987],
            self::Google => [15169, 396982, 19527],
            self::Azure => [8075, 8068],
            self::DigitalOcean => [14061],
            self::Ovh => [16276],
            self::Hetzner => [24940, 213230],
            self::Vultr => [20473],
            self::Linode => [63949],
            self::M247 => [9009],
            self::Hostinger => [47583],
            self::ColocationAmerica => [47007],
            self::EgiHosting => [18779],
            self::Contabo => [51167],
            self::Scaleway => [12876],
            self::Leaseweb => [60781, 16265, 28753, 32613],
            self::Tzulo => [11878],
            self::Oracle => [31898],
            self::Alibaba => [45102],
            self::Tencent => [132203],
            self::ColoCrossing => [36352],
            self::FranTech => [53667],
            self::Clouvider => [62240],
            self::Datacamp => [212238],
            self::Cloudflare => [13335],
        };
    }

    /**
     * Get the display label for the provider.
     */
    public function label(): string
    {
        return match ($this) {
            self::Aws => 'Amazon AWS',
            self::Google => 'Google Cloud',
            self::Azure => 'Microsoft Azure',
            self::DigitalOcean => 'DigitalOcean',
            self::Ovh => 'OVH',
            self::Hetzner => 'Hetzner',
            self::Vultr => 'Vultr',
            self::Linode => 'Akamai (Linode)',
            self::M247 => 'M247',
            self::Hostinger => 'Hostinger',
            self::ColocationAmerica => 'Colocation America',
            self::EgiHosting => 'EGIHosting',
            self::Contabo => 'Contabo',
            self::Scaleway => 'Scaleway',
            self::Leaseweb => 'Leaseweb',
            self::Tzulo => 'tzulo',
            self::Oracle => 'Oracle Cloud',
            self::Alibaba => 'Alibaba Cloud',
            self::Tencent => 'Tencent Cloud',
            self::ColoCrossing => 'ColoCrossing',
            self::FranTech => 'FranTech (BuyVM)',
            self::Clouvider => 'Clouvider',
            self::Datacamp => 'Datacamp (CDN77)',
            self::Cloudflare => 'Cloudflare',
        };
    }
}
