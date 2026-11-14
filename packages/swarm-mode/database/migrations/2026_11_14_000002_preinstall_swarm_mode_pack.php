<?php

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Auth;
use NiceTry\RulePacks\Actions\InstallRulePack;
use NiceTry\SwarmMode\SwarmModePack;

return new class extends Migration
{
    /**
     * Run the migrations: install the Swarm Mode pack for every team there is, as its owner (linking a
     * rule's SWARM and BOUNCER clauses takes someone allowed to). Its swarm rules start on, its Bouncer
     * rule off. A team with no owner is left to install it from the Rules page, as a new team does.
     */
    public function up(): void
    {
        $install = app(InstallRulePack::class);
        $pack = new SwarmModePack;

        Team::query()->orderBy('id')->each(function (Team $team) use ($install, $pack) {
            $owner = $team->owner();

            if (! $owner instanceof User) {
                return;
            }

            Auth::setUser($owner);
            $install->handle($team, $pack);
        });

        Auth::forgetUser();
    }

    /**
     * Reverse the migrations. The pack's rules stay: they're the team's rules now.
     */
    public function down(): void
    {
        //
    }
};
