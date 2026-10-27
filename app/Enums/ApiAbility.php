<?php

namespace App\Enums;

/**
 * What an API token may do through the MCP server. Every token can read; the rest are opted into
 * when it's made, and the token's user still needs the matching team permission.
 */
enum ApiAbility: string
{
    case Read = 'read';
    case ManageDomains = 'domains';
    case ManageRules = 'rules';
    case ManageGym = 'gym';
    case Lockdown = 'lockdown';

    /**
     * The name shown beside the checkbox when a token is made.
     */
    public function label(): string
    {
        return match ($this) {
            self::Read => __('Read traffic, domains, rules and gyms'),
            self::ManageDomains => __('Add and change domains'),
            self::ManageRules => __('Create and change rules'),
            self::ManageGym => __('Create and change gyms'),
            self::Lockdown => __('Lock down and release domains'),
        };
    }

    /**
     * The team permission the token's user needs as well, if any.
     */
    public function teamPermission(): ?TeamPermission
    {
        return match ($this) {
            self::Read => null,
            self::ManageDomains, self::Lockdown => TeamPermission::ManageDomains,
            self::ManageRules => TeamPermission::ManageRules,
            self::ManageGym => TeamPermission::ManageGym,
        };
    }
}
