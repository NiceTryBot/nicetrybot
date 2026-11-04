<?php

namespace NiceTry\DynamicSubdomains;

use App\Addons\AddonServiceProvider;
use App\Enums\ViewSlot;
use NiceTry\DynamicSubdomains\Actions\ClaimSubdomain;

/**
 * Dynamic subdomains, as an add-on: a domain with them on adds any name one label under it the first
 * time the edge is asked about it (a certificate or a request), as a domain of its own with the
 * domain's settings and its origin under the same name (foo.example.com → https://foo.origin.example).
 * Each domain's page has a panel to turn it on and see what it added.
 */
class DynamicSubdomainsServiceProvider extends AddonServiceProvider
{
    protected array $hostnameClaimers = [ClaimSubdomain::class];

    protected array $slots = [
        ViewSlot::DomainPanels->value => 'dynamic-subdomains::domain-panel',
    ];
}
