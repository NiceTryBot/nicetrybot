<?php

namespace App\Enums;

use App\Data\LabelDirectory;
use App\Models\ClientHint;
use App\Models\ClientLabel;
use App\Models\TlsFingerprint;
use App\Models\UserAgent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * Kinds of request a view can hide to cut through the noise.
 */
enum ActivityNoise: string
{
    case Mine = 'mine';
    case Assets = 'assets';
    case Probes = 'probes';
    case Homepage = 'home';

    /**
     * Get the label shown on the toggle.
     */
    public function label(): string
    {
        return match ($this) {
            self::Mine => __('My traffic'),
            self::Assets => __('Assets'),
            self::Probes => __('Probes'),
            self::Homepage => __('GET /'),
        };
    }

    /**
     * Get a short explanation of what the toggle hides.
     */
    public function description(): string
    {
        return match ($this) {
            self::Mine => __('Requests from the IPs, fingerprints and devices you named and marked as your own traffic.'),
            self::Assets => __('Scripts, styles, images, fonts, icons and robots.txt.'),
            self::Probes => __('Scanners looking for secrets, admin panels and known exploits.'),
            self::Homepage => __('Plain visits to a site\'s home page.'),
        };
    }

    /**
     * Get the search clause that hides this kind of request. My traffic hangs on the team's labels,
     * which the rule language can't name, so it has none and is hidden by exclude() instead.
     */
    public function clause(): ?string
    {
        return match ($this) {
            self::Mine => null,
            self::Assets => "category != 'asset'",
            self::Probes => "category != 'probe'",
            self::Homepage => "NOT (method = 'GET' AND path = '/')",
        };
    }

    /**
     * Leave this kind of request out of an edge request query, when it has no search clause. My
     * traffic needs the team whose labels say what's theirs.
     *
     * @template TQuery of Builder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function exclude(Builder|Relation $query, ?int $teamId = null): Builder|Relation
    {
        return $this === self::Mine ? $this->excludeMine($query, $teamId) : $query;
    }

    /**
     * Parse a comma-separated list like "assets,probes", ignoring anything unknown.
     *
     * @return list<self>
     */
    public static function fromList(string $list): array
    {
        return array_values(array_filter(array_map(
            fn (string $value) => self::tryFrom(trim($value)),
            explode(',', $list),
        )));
    }

    /**
     * Turn a set of kinds back into the list kept in the URL, in a stable order.
     *
     * @param  list<self>  $kinds
     */
    public static function toList(array $kinds): string
    {
        return implode(',', array_map(
            fn (self $kind) => $kind->value,
            array_values(array_filter(self::cases(), fn (self $kind) => in_array($kind, $kinds, true))),
        ));
    }

    /**
     * Leave out requests from any value the team marked as its own. Each match is null-safe, so a request
     * without a fingerprint or hints isn't dropped by accident.
     *
     * @template TQuery of Builder|Relation
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    private function excludeMine(Builder|Relation $query, ?int $teamId): Builder|Relation
    {
        $mine = $teamId === null ? collect() : app(LabelDirectory::class)->mine($teamId);

        if ($mine->isEmpty()) {
            return $query;
        }

        return $query->whereNot(function ($query) use ($mine) {
            $mine->each(fn (ClientLabel $label) => $query->orWhere(fn ($query) => match ($label->kind) {
                LabelKind::Ip => $query->where('client_ip', $label->value),
                LabelKind::Ja4 => $query->whereNotNull('ja4_id')->whereIn('ja4_id', TlsFingerprint::query()->where('type', TlsFingerprintType::Ja4)->where('value', $label->value)->select('id')),
                LabelKind::UserAgent => $query->whereNotNull('user_agent_id')->whereIn('user_agent_id', UserAgent::query()->where('hash', md5($label->value))->select('id')),
                LabelKind::ClientHints => $query->whereNotNull('client_hints_id')->whereIn('client_hints_id', ClientHint::query()->where('hash', md5($label->value))->select('id')),
            }));
        });
    }
}
