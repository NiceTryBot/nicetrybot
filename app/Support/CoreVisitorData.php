<?php

namespace App\Support;

use App\Enums\LabelKind;
use App\Enums\TlsFingerprintType;
use App\Models\Team;
use App\Models\VisitorDataSetting;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The visitor data the core keeps: the edge request log (and the Livewire calls beside it), gym
 * sessions, verdicts and client labels. What an add-on keeps beside a request goes with it.
 */
class CoreVisitorData implements VisitorDataHolder
{
    public function retention(): array
    {
        $default = fn (string $field): int => (int) config('visitor_data.retention.'.$field);

        return [
            new RetentionPeriod('request_days', 'Request log', 'Every request the edge saw, and everything kept beside it.', $default('request_days'), order: 10),
            new RetentionPeriod('gym_days', 'Gym visits', 'Gym sessions and the answers given in them.', $default('gym_days'), order: 20),
            new RetentionPeriod('verdict_days', 'Ended verdicts', 'Tags and bans that expired or were lifted.', $default('verdict_days'), order: 60),
        ];
    }

    /**
     * Delete what's past the team's retention: requests, gym sessions and verdicts that ended long
     * enough ago.
     */
    public function prune(Team $team, VisitorDataSetting $retention, CarbonImmutable $now, float $deadline): array
    {
        $verdictCutoff = $retention->cutoff('verdict_days', $now);

        return array_filter([
            'requests' => BatchDelete::run('edge_requests', DB::table('edge_requests')
                ->whereIn('domain_id', $this->domainIds($team))
                ->where('occurred_at', '<', $retention->cutoff('request_days', $now))
                ->select('id'), $deadline),
            'gym_sessions' => BatchDelete::run('gym_sessions', DB::table('gym_sessions')
                ->where('team_id', $team->id)
                ->where('started_at', '<', $retention->cutoff('gym_days', $now))
                ->select('id'), $deadline),
            'verdicts' => BatchDelete::run('verdicts', DB::table('verdicts')
                ->where('team_id', $team->id)
                ->where(fn (Builder $query) => $query->where('expires_at', '<', $verdictCutoff)->orWhere('lifted_at', '<', $verdictCutoff))
                ->select('id'), $deadline),
        ]);
    }

    /**
     * Delete everything about one visitor: its requests (with what's kept beside them), the gym sessions it
     * entered or made attempts in, its verdicts, and the labels the team gave its IP or fingerprint.
     */
    public function forget(Team $team, ?string $ip, ?string $ja4): array
    {
        $ja4Id = $ja4 === null ? null : DB::table('tls_fingerprints')->where('type', TlsFingerprintType::Ja4)->where('value', $ja4)->value('id');
        $matches = fn (string $ipColumn, ?string $ja4Column, mixed $ja4Value) => fn (Builder $query) => $query
            ->whereRaw('1 = 0')
            ->when($ip !== null, fn (Builder $query) => $query->orWhere($ipColumn, $ip))
            ->when($ja4Column !== null && $ja4Value !== null, fn (Builder $query) => $query->orWhere($ja4Column, $ja4Value));

        return array_filter([
            'requests' => BatchDelete::run('edge_requests', DB::table('edge_requests')
                ->whereIn('domain_id', $this->domainIds($team))
                ->where($matches('client_ip', 'ja4_id', $ja4Id))
                ->select('id')),
            'gym_sessions' => BatchDelete::run('gym_sessions', DB::table('gym_sessions')
                ->where('team_id', $team->id)
                ->where(fn (Builder $query) => $query
                    ->where($matches('client_ip', 'ja4', $ja4))
                    ->when($ip !== null, fn (Builder $query) => $query->orWhereLike('attempt_clients', '%"'.$ip.'"%')))
                ->select('id')),
            'verdicts' => BatchDelete::run('verdicts', DB::table('verdicts')
                ->where('team_id', $team->id)
                ->where($matches('client_ip', 'ja4_id', $ja4Id))
                ->select('id')),
            'labels' => DB::table('client_labels')
                ->where('team_id', $team->id)
                ->where(fn (Builder $query) => $query
                    ->whereRaw('1 = 0')
                    ->when($ip !== null, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query->where('kind', LabelKind::Ip->value)->where('value_hash', md5($ip))))
                    ->when($ja4 !== null, fn (Builder $query) => $query->orWhere(fn (Builder $query) => $query->where('kind', LabelKind::Ja4->value)->where('value_hash', md5($ja4)))))
                ->delete(),
        ]);
    }

    private function domainIds(Team $team): Builder
    {
        return DB::table('domains')->where('team_id', $team->id)->select('id');
    }
}
