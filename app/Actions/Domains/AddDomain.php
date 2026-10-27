<?php

namespace App\Actions\Domains;

use App\Addons\DomainLimit;
use App\Enums\DomainStatus;
use App\Enums\HostHeaderMode;
use App\Enums\ProtectionMode;
use App\Models\Domain;
use App\Models\Team;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AddDomain
{
    public function __construct(private DomainLimit $limit)
    {
        //
    }

    /**
     * Register a domain and its origin for the team. New domains start in observe mode, answer as
     * nginx, and stay inactive until ownership is verified. A www. hostname never serves www. again.
     * The installation's domain limit is checked first.
     *
     * @param  array{hostname: string, origin_url: string, host_header_mode: string, origin_host_header?: ?string, forwarded_host_header?: ?string, serves_www?: bool}  $attributes
     *
     * @throws ValidationException when the installation is at its domain limit
     */
    public function handle(Team $team, array $attributes): Domain
    {
        $this->limit->ensureRoom();

        $hostHeaderMode = HostHeaderMode::from($attributes['host_header_mode']);

        return $team->domains()->create([
            'hostname' => $attributes['hostname'],
            'origin_url' => rtrim($attributes['origin_url'], '/'),
            'host_header_mode' => $hostHeaderMode,
            'origin_host_header' => $hostHeaderMode === HostHeaderMode::Rewrite
                ? ($attributes['origin_host_header'] ?? null) ?: null
                : null,
            'forwarded_host_header' => ($attributes['forwarded_host_header'] ?? null) ?: null,
            'serves_www' => ($attributes['serves_www'] ?? false) && Domain::withoutWww($attributes['hostname']) === null,
            'server_header' => Domain::DEFAULT_SERVER_HEADER,
            'protection_mode' => ProtectionMode::Observe,
            'status' => DomainStatus::PendingVerification,
            'verification_token' => Str::lower(Str::random(32)),
            'origin_secret' => Str::random(48),
        ]);
    }
}
