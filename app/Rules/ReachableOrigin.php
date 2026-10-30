<?php

namespace App\Rules;

use App\Actions\Domains\LookUpDnsRecords;
use App\Models\Domain;
use App\Support\PreviewHostname;
use App\Support\PublicAddress;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

/**
 * An origin URL the edge may forward visitors to: on the public internet, and not the edge itself.
 *
 * The edge connects to whatever origin a site names, from inside its own network. A private or
 * link-local address would let anyone who can add a domain reach what only the edge can: a cloud's
 * metadata service, the stack's other containers, the host's own services. And an origin the edge
 * serves itself (its own hostname or addresses, a preview, another site here) sends each request round
 * and round until it times out. A self-hosted edge meant to reach a private network sets
 * NICETRY_ALLOW_PRIVATE_ORIGINS (config nicetry.allow_private_origins); loops are refused regardless.
 * A name that doesn't resolve yet passes: the edge refuses private addresses again when it connects.
 */
class ReachableOrigin implements ValidationRule
{
    /**
     * @param  string|null  $hostname  The site the origin is for, which mustn't be its own origin.
     */
    public function __construct(private ?string $hostname = null)
    {
        //
    }

    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $host = strtolower(rtrim(trim((string) parse_url((string) $value, PHP_URL_HOST), '[]'), '.'));

        if ($host === '') {
            return;
        }

        if ($host === $this->hostname) {
            $fail(__('The origin must be a different host than the domain, or requests would loop back to the edge.'));

            return;
        }

        if ($this->servedByTheEdge($host)) {
            $fail(__('The origin must be where the site really lives, not a site or preview this edge serves, or requests would loop back to the edge.'));

            return;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            $addresses = [$host];
        } else {
            $records = app(LookUpDnsRecords::class)->handle($host);
            $addresses = [...$records['ipv4'], ...$records['ipv6']];
        }

        if (array_intersect($addresses, Domain::edgeAddresses()) !== []) {
            $fail(__('The origin resolves to the edge itself, so requests would loop back to it. Use the address where the site really lives.'));

            return;
        }

        if (config('nicetry.allow_private_origins')) {
            return;
        }

        foreach ($addresses as $address) {
            if (! PublicAddress::isPublic($address)) {
                $fail(__('The origin resolves to :address, a private or reserved address the edge won\'t forward visitors to. Use the origin\'s public address.', ['address' => $address]));

                return;
            }
        }
    }

    /**
     * Determine if the edge answers for a hostname itself.
     */
    private function servedByTheEdge(string $host): bool
    {
        return $host === strtolower(Domain::edgeHostname())
            || PreviewHostname::isPreview($host)
            || Domain::query()->servedAs($host)->exists();
    }
}
