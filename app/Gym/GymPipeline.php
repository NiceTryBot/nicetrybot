<?php

namespace App\Gym;

use App\Enums\GymExit;
use App\Enums\GymSessionStatus;
use App\Models\GymRoutine;
use App\Models\GymSession;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Builds and runs a visitor's pipeline. The plan is rebuilt from live routines on every request, so
 * an admin's edits reach visitors already in the gym at their next obstacle.
 */
class GymPipeline
{
    /**
     * The pass length when no routine sets one.
     */
    public const int DEFAULT_PASS_SECONDS = 3600;

    public function __construct(private ObstacleRegistry $registry)
    {
        //
    }

    /**
     * Build the session's plan: from its draft when it's a preview, otherwise from the enabled
     * routines it matched on entry.
     */
    public function plan(GymSession $session): GymPlan
    {
        $blueprints = $session->draft ?? array_values($session->routines()
            ->where('enabled', true)
            ->orderBy('position')
            ->orderBy('gym_routines.id')
            ->get()
            ->map(fn (GymRoutine $routine) => $routine->blueprint())
            ->all());

        return $this->planFor($blueprints, $session->escalations ?? []);
    }

    /**
     * Stack routine blueprints into one plan. Each obstacle type runs once: the first routine to use
     * it decides its config. Escalations go right after the step that raised them.
     *
     * @param  list<array{id: int|null, name: string, obstacles: list<array{id: string, type: string, config: array<string, mixed>, escalate: string|null}>, exit: int, pass_seconds: int}>  $blueprints
     * @param  list<array{key: string, type: string, after: string}>  $escalations
     */
    public function planFor(array $blueprints, array $escalations = []): GymPlan
    {
        $steps = [];
        $seen = [];

        foreach ($blueprints as $blueprint) {
            foreach ($blueprint['obstacles'] as $entry) {
                $obstacle = $this->registry->find($entry['type']);

                if ($obstacle === null || isset($seen[$obstacle->key()])) {
                    continue;
                }

                $seen[$obstacle->key()] = true;
                $escalateTo = $entry['escalate'];

                $steps[] = new GymStep(
                    key: $entry['id'],
                    obstacle: $obstacle,
                    config: $obstacle->configure($entry['config']),
                    escalateTo: $obstacle->flagsWhen() !== null && $escalateTo !== null && $this->registry->find($escalateTo) !== null ? $escalateTo : null,
                    routineName: $blueprint['name'],
                );
            }
        }

        foreach ($escalations as $escalation) {
            $obstacle = $this->registry->find($escalation['type']);

            if ($obstacle === null || isset($seen[$obstacle->key()])) {
                continue;
            }

            $seen[$obstacle->key()] = true;
            $after = collect($steps)->search(fn (GymStep $step) => $step->key === $escalation['after']);

            array_splice($steps, $after === false ? count($steps) : $after + 1, 0, [new GymStep(
                key: $escalation['key'],
                obstacle: $obstacle,
                config: $obstacle->configure([]),
                isEscalation: true,
            )]);
        }

        return new GymPlan(
            steps: $steps,
            exit: GymExit::strictest(array_map(fn (array $blueprint) => GymExit::tryFrom($blueprint['exit']) ?? GymExit::Continue, $blueprints)),
            passSeconds: $blueprints === [] ? self::DEFAULT_PASS_SECONDS : min(array_column($blueprints, 'pass_seconds')),
            routineNames: array_column($blueprints, 'name'),
        );
    }

    /**
     * Send the visitor down their pipeline: the first obstacle they haven't cleared answers, and a
     * visitor who has cleared them all reaches the exit.
     */
    public function run(GymVisit $visit): Response
    {
        if ($visit->session->status === GymSessionStatus::TurnedAway) {
            return $visit->finish();
        }

        return App::make(Pipeline::class)
            ->send($visit)
            ->through($visit->plan->steps)
            ->then(fn (GymVisit $visit) => $visit->finish());
    }
}
