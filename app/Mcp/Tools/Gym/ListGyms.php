<?php

namespace App\Mcp\Tools\Gym;

use App\Enums\ApiAbility;
use App\Enums\GymSessionStatus;
use App\Mcp\Tools\Gym\Concerns\WorksWithGyms;
use App\Mcp\Tools\TeamTool;
use App\Models\GymRoutine;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Description(<<<'TEXT'
    List the team's gyms (gym routines) in the order they stack, and how the gym did over the last 7 days.
    A gym is who enters (conditions, in a sentence as who_enters), the obstacles they go through in order
    (each with its id, type, settings and escalation), where they go after clearing it (exit_action), and
    how long a cleared visitor skips it (pass). Every enabled gym a visitor matches stacks into one
    pipeline. Each gym has its 7-day entered and cleared counts; last_7_days is the whole team's: entered,
    cleared, turned away, gave up, and the hashes visitors' machines burned. Use it to answer "how many
    people cleared the gym this week?", or to find a gym's id and obstacle ids before update-gym,
    add-obstacle, remove-obstacle or delete-gym.
    TEXT)]
#[IsReadOnly]
class ListGyms extends TeamTool
{
    use WorksWithGyms;

    /**
     * How many days the numbers cover, as on the gym page.
     */
    private const int DAYS = 7;

    protected ApiAbility $ability = ApiAbility::Read;

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->integer()->description('Only this gym, by id. Leave out for every gym.'),
        ];
    }

    /**
     * Handle the tool request.
     */
    public function handle(Request $request): Response|ResponseFactory
    {
        $request->validate(['id' => ['nullable', 'integer']]);

        Gate::forUser($this->user())->authorize('viewAny', [GymRoutine::class, $this->team()]);

        $routines = $this->team()->gymRoutines()
            ->when($request->get('id') !== null, fn ($query) => $query->whereKey((int) $request->get('id')))
            ->get();

        if ($request->get('id') !== null && $routines->isEmpty()) {
            return $this->routineNotFound((int) $request->get('id'));
        }

        $counts = $this->routineStats($routines->modelKeys());

        return Response::structured([
            'gyms' => $routines->map(fn (GymRoutine $routine) => [
                ...$this->routineData($routine),
                'last_7_days' => $counts[$routine->id] ?? ['entered' => 0, 'cleared' => 0],
            ])->values()->all(),
            'last_7_days' => $this->teamStats(),
        ]);
    }

    /**
     * How many real visitors entered and cleared each routine over the last week, by routine id.
     * Routines nobody entered are left out.
     *
     * @param  array<int, int|string>  $routineIds
     * @return array<int, array{entered: int, cleared: int}>
     */
    private function routineStats(array $routineIds): array
    {
        return $this->team()->gymSessions()
            ->real()
            ->where('started_at', '>=', now()->subDays(self::DAYS))
            ->join('gym_routine_gym_session', 'gym_routine_gym_session.gym_session_id', '=', 'gym_sessions.id')
            ->whereIn('gym_routine_gym_session.gym_routine_id', $routineIds)
            ->toBase()
            ->groupBy('gym_routine_gym_session.gym_routine_id')
            ->select('gym_routine_gym_session.gym_routine_id')
            ->selectRaw('count(*) as entered')
            ->selectRaw('sum(case when gym_sessions.status = ? then 1 else 0 end) as cleared', [GymSessionStatus::Cleared->value])
            ->get()
            ->mapWithKeys(fn (object $row) => [(int) $row->gym_routine_id => ['entered' => (int) $row->entered, 'cleared' => (int) $row->cleared]])
            ->all();
    }

    /**
     * The team's gym over the last week, as the gym page counts it.
     *
     * @return array{entered: int, cleared: int, turned_away: int, gave_up: int, hashes: int}
     */
    private function teamStats(): array
    {
        $row = $this->team()->gymSessions()
            ->real()
            ->where('started_at', '>=', now()->subDays(self::DAYS))
            ->toBase()
            ->selectRaw('count(*) as entered')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as cleared', [GymSessionStatus::Cleared->value])
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as turned_away', [GymSessionStatus::TurnedAway->value])
            ->selectRaw('sum(case when status = ? and last_seen_at < ? then 1 else 0 end) as gave_up', [GymSessionStatus::Training->value, now()->subMinutes((int) config('gym.idle_minutes'))])
            ->selectRaw('coalesce(sum(hashes), 0) as hashes')
            ->first();

        return [
            'entered' => (int) $row->entered,
            'cleared' => (int) $row->cleared,
            'turned_away' => (int) $row->turned_away,
            'gave_up' => (int) $row->gave_up,
            'hashes' => (int) $row->hashes,
        ];
    }
}
