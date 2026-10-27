<?php

namespace App\Gym;

use App\Gym\Contracts\Obstacle;
use Closure;
use Symfony\Component\HttpFoundation\Response;

/**
 * One handler in a visitor's gym pipeline: an obstacle with its config. Like middleware, it waves
 * the visitor through to the next step once they've cleared it, and otherwise stops the pipeline and
 * presents itself.
 */
readonly class GymStep
{
    /**
     * @param  string  $key  The routine's id for this obstacle entry, so progress survives edits.
     * @param  array<string, int|string|bool>  $config
     * @param  string|null  $escalateTo  The obstacle to add after this one when it flags the visitor.
     */
    public function __construct(
        public string $key,
        public Obstacle $obstacle,
        public array $config,
        public ?string $escalateTo = null,
        public ?string $routineName = null,
        public bool $isEscalation = false,
    ) {
        //
    }

    /**
     * @param  Closure(GymVisit): Response  $next
     */
    public function handle(GymVisit $visit, Closure $next): Response
    {
        return $visit->hasCleared($this) ? $next($visit) : $visit->present($this);
    }
}
