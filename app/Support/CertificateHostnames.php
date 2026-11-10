<?php

namespace App\Support;

use App\Addons\AddonSwitchboard;
use Illuminate\Contracts\Container\Container;

/**
 * Every source tagged in under TAG whose add-on is on (App\Support\CertificateHostnameSource), asked in
 * turn whether the edge may get a certificate for a hostname no domain has. With none, it may not.
 */
class CertificateHostnames
{
    /**
     * The container tag sources register under.
     */
    public const string TAG = 'edge.certificate-hostnames';

    public function __construct(private Container $container, private AddonSwitchboard $switchboard)
    {
        //
    }

    /**
     * Determine if a source vouches for the hostname.
     */
    public function vouchFor(string $hostname): bool
    {
        foreach ($this->switchboard->active($this->container->tagged(self::TAG)) as $source) {
            if ($source instanceof CertificateHostnameSource && $source->vouchesFor($hostname)) {
                return true;
            }
        }

        return false;
    }
}
