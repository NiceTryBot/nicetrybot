<?php

namespace App\Listeners;

use App\Models\LiveTail;
use Illuminate\Auth\Events\Logout;

class EndLiveTails
{
    /**
     * End the user's live tails when they log out, so the edge stops sending their requests.
     */
    public function handle(Logout $event): void
    {
        if ($event->user !== null) {
            LiveTail::query()->where('user_id', $event->user->getAuthIdentifier())->delete();
        }
    }
}
