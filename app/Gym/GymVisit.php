<?php

namespace App\Gym;

use App\Enums\GymExit;
use App\Enums\GymSessionStatus;
use App\Models\GymSession;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What travels down a gym pipeline: the visitor's session, their plan, and the request. Steps ask it
 * whether the visitor has cleared them, and ask it to present them when not.
 */
class GymVisit
{
    public function __construct(
        public GymSession $session,
        public GymPlan $plan,
        public Request $request,
    ) {
        //
    }

    /**
     * Determine if the visitor has cleared a step.
     */
    public function hasCleared(GymStep $step): bool
    {
        return $this->session->state($step->key)->isCleared();
    }

    /**
     * Draw a step for the visitor, setting the obstacle up the first time they reach it.
     */
    public function present(GymStep $step): Response
    {
        $state = $this->session->state($step->key);

        if ($state->startedAt === null) {
            $step->obstacle->begin($state, $step->config);
            $state->startedAt = now()->getTimestampMs();
        }

        $notice = $state->notice;
        $state->notice = null;

        $this->session->putState($step->key, $state);
        $this->session->last_seen_at = now();
        $this->session->save();

        return self::page('gym.visit', [
            'session' => $this->session,
            'steps' => $this->plan->steps,
            'current' => array_search($step, $this->plan->steps, true),
            'step' => $step,
            'notice' => $notice,
            'obstacle' => $step->obstacle->viewData($state, $step->config),
            'attemptUrl' => route('gym.attempt', $this->session, absolute: false),
            'isDemo' => false,
            'addons' => app(GymAddons::class)->pageHtml($this),
        ]);
    }

    /**
     * Send a visitor who has cleared every obstacle out through the plan's exit: back to where they
     * were going with a pass, or a not-found or forbidden page from the gym itself.
     */
    public function finish(): Response
    {
        if ($this->session->status === GymSessionStatus::Training) {
            $this->session->update([
                'status' => $this->plan->exit === GymExit::Continue ? GymSessionStatus::Cleared : GymSessionStatus::TurnedAway,
                'exit_action' => $this->plan->exit,
                'last_seen_at' => now(),
                'finished_at' => now(),
            ]);
        }

        $exit = $this->session->exit_action ?? $this->plan->exit;

        if ($exit === GymExit::Continue && ! $this->session->is_preview) {
            return redirect()->away($this->session->destination ?? '/')
                ->withCookie($this->session->passCookie($this->plan->passSeconds, $this->request->isSecure()));
        }

        return self::page('gym.exit', [
            'session' => $this->session,
            'plan' => $this->plan,
            'exit' => $exit,
        ], $exit->status() ?? 200);
    }

    /**
     * Render a gym page that no cache or search engine should keep.
     *
     * @param  array<string, mixed>  $data
     */
    public static function page(string $view, array $data, int $status = 200): Response
    {
        return response()->view($view, $data, $status)->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
