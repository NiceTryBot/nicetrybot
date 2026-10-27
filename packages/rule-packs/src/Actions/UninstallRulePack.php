<?php

namespace NiceTry\RulePacks\Actions;

use App\Models\Team;
use Illuminate\Support\Facades\DB;
use NiceTry\RulePacks\Data\PackChanges;
use NiceTry\RulePacks\Models\RulePackInstall;
use NiceTry\RulePacks\RulePack;

class UninstallRulePack
{
    /**
     * Uninstall a pack from a team: delete the rules and routines it installed that the team hasn't
     * changed (switching one on or off doesn't count), and keep the ones it has, which become the
     * team's own. A deleted rule's verdicts stay until they expire, as when a rule is deleted on the
     * Rules page; a routine of the team's own that names one simply stops matching its verdicts.
     */
    public function handle(Team $team, RulePack $pack): PackChanges
    {
        return DB::transaction(function () use ($team, $pack): PackChanges {
            $removed = [];
            $kept = [];

            $installs = RulePackInstall::query()
                ->where('team_id', $team->id)
                ->where('pack', $pack->key())
                ->with('rule', 'routine')
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->sortBy(fn (RulePackInstall $install): int => $install->routine === null ? 1 : 0);

            foreach ($installs as $install) {
                $item = $install->routine ?? $install->rule;
                $isEdited = $install->isEdited();
                $install->delete();

                if ($item === null) {
                    continue;
                }

                if ($isEdited) {
                    $kept[] = $item->name;
                } else {
                    $item->delete();
                    $removed[] = $item->name;
                }
            }

            return new PackChanges(removed: $removed, kept: $kept);
        });
    }
}
