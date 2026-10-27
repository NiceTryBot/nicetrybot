<?php

namespace App\Http\Controllers\Gym;

use App\Enums\GymExit;
use App\Enums\GymSessionStatus;
use App\Gym\AttemptListeners;
use App\Gym\GymPipeline;
use App\Gym\GymVisit;
use App\Gym\ObstacleAttempt;
use App\Gym\ObstacleRegistry;
use App\Gym\ObstacleState;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateEdge;
use App\Models\ClientHint;
use App\Models\GymSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The visitor's side of the gym. These routes answer on customers' domains (the edge sends visitors
 * here), so they carry no app session, CSRF or Livewire: the session token in the URL is the key.
 */
class GymController extends Controller
{
    public function __construct(private GymPipeline $pipeline)
    {
        //
    }

    /**
     * Show the visitor their next obstacle, or send them out through the exit.
     */
    public function show(Request $request, GymSession $gymSession): Response
    {
        if (AuthenticateEdge::isFromEdge($request)) {
            $gymSession->rememberClientHints($request->header('X-NiceTry-Client-Ip') ?: null, $this->clientHints($request));

            if ($gymSession->isDirty('client_hints_id')) {
                $gymSession->save();
            }
        }

        return $this->pipeline->run(new GymVisit($gymSession, $this->pipeline->plan($gymSession), $request));
    }

    /**
     * Judge an attempt at the visitor's current obstacle. Scripted obstacles post JSON and get JSON
     * back; plain forms are redirected back to the gym (post/redirect/get). Once it's saved, the
     * add-ons listening hear about it.
     */
    public function attempt(Request $request, GymSession $gymSession, AttemptListeners $attemptListeners): JsonResponse|RedirectResponse
    {
        $plan = $this->pipeline->plan($gymSession);
        $step = $plan->currentStep($gymSession);
        $state = $step === null ? null : $gymSession->state($step->key);

        // The pipeline moved on (cleared elsewhere, or an admin edited the routine): start over from the top.
        if ($step === null || $state?->startedAt === null || $step->key !== $request->input('step') || $gymSession->status !== GymSessionStatus::Training) {
            return $this->respond($request, $gymSession, ['status' => 'moved']);
        }

        $before = $state->data;
        $outcome = $step->obstacle->attempt($state, $step->config, $request->except('step'));
        $clientIp = AuthenticateEdge::isFromEdge($request) ? ($request->header('X-NiceTry-Client-Ip') ?: null) : null;

        $state->attempts++;
        $state->hashes += $outcome->hashes;
        $gymSession->hashes += $outcome->hashes;

        if ($outcome->isCleared()) {
            $state->clearedAt = now()->getTimestampMs();

            if ($outcome->flag !== null) {
                $state->flags[] = $outcome->flag;

                if ($step->escalateTo !== null && ! $plan->has($step->escalateTo)) {
                    $gymSession->escalations = [...$gymSession->escalations, ['key' => $step->key.'~'.$step->escalateTo, 'type' => $step->escalateTo, 'after' => $step->key]];
                }
            }
        } elseif (! $request->expectsJson()) {
            $state->notice = $outcome->message;
        }

        if ($outcome->isFailed()) {
            $gymSession->status = GymSessionStatus::TurnedAway;
            $gymSession->exit_action = GymExit::Block;
            $gymSession->finished_at = now();
        }

        if (AuthenticateEdge::isFromEdge($request)) {
            $gymSession->recordAttemptClient($clientIp, $request->header('X-NiceTry-JA4') ?: null, $this->clientHints($request));
            $gymSession->rememberClientHints($clientIp, $this->clientHints($request));
        }

        $gymSession->putState($step->key, $state);
        $gymSession->last_seen_at = now();
        $gymSession->save();

        $attemptListeners->notify(new ObstacleAttempt($gymSession, $step, $before, $state->data, $outcome, $clientIp ?? $gymSession->client_ip));

        return $this->respond($request, $gymSession, [
            'status' => $outcome->status,
            'message' => $outcome->message,
            ...$outcome->payload,
        ]);
    }

    /**
     * Draw an obstacle with a config but no visitor, for the routine editor's live preview. Nothing is
     * stored: the page works the puzzle in the browser and stops short of submitting.
     */
    public function demo(Request $request, ObstacleRegistry $registry, string $obstacle): Response
    {
        $found = $registry->find($obstacle);
        abort_if($found === null, 404);

        $config = $found->configure((array) json_decode((string) base64_decode(strtr((string) $request->query('config', ''), '-_', '+/')), true));
        $state = new ObstacleState(startedAt: now()->getTimestampMs());
        $found->begin($state, $config);

        return GymVisit::page('gym.visit', [
            'session' => null,
            'steps' => [],
            'current' => 0,
            'step' => null,
            'demoObstacle' => $found,
            'notice' => null,
            'obstacle' => $found->viewData($state, $config),
            'attemptUrl' => null,
            'isDemo' => true,
        ]);
    }

    /**
     * Answer an attempt. A visitor the edge sent here trains in place on the page they asked for, so
     * once an obstacle is cleared the page reloads that same address: the edge then shows the next
     * obstacle, or, after the last one, lets them through on the pass this response carries. A visitor
     * out of tries reloads it too, and gets the gym's forbidden page. Previews and sessions with nowhere
     * to return to go back to the gym's own page instead.
     *
     * @param  array<string, mixed>  $body
     */
    private function respond(Request $request, GymSession $gymSession, array $body): JsonResponse|RedirectResponse
    {
        $showUrl = route('gym.show', $gymSession, absolute: false);
        $returnsInPlace = ! $gymSession->is_preview && $gymSession->destination !== null;

        if (! $request->expectsJson()) {
            return redirect()->to($showUrl);
        }

        if (! in_array($body['status'], ['cleared', 'moved', 'failed'], true)) {
            return response()->json($body);
        }

        $response = response()->json([...$body, 'redirect' => $returnsInPlace ? $gymSession->destination : $showUrl]);

        $plan = $this->pipeline->plan($gymSession);

        if ($returnsInPlace && $plan->currentStep($gymSession) === null) {
            foreach ((new GymVisit($gymSession, $plan, $request))->finish()->headers->getCookies() as $cookie) {
                $response->headers->setCookie($cookie);
            }
        }

        return $response;
    }

    /**
     * Get the client hints the visitor's browser sent with a request, which the edge passes through to
     * the gym's pages and attempts.
     */
    private function clientHints(Request $request): ?string
    {
        return ClientHint::valueOf($request->header('Sec-CH-UA'), $request->header('Sec-CH-UA-Platform'));
    }
}
