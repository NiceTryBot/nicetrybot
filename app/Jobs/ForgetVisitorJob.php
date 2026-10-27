<?php

namespace App\Jobs;

use App\Actions\VisitorData\ForgetVisitor;
use App\Models\VisitorErasure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ForgetVisitorJob implements ShouldQueue
{
    use Queueable;

    /**
     * Give a big team's request log time to be searched.
     */
    public int $timeout = 600;

    public function __construct(public VisitorErasure $erasure, public ?string $ip, public ?string $ja4)
    {
        //
    }

    public function handle(ForgetVisitor $forgetVisitor): void
    {
        $forgetVisitor->handle($this->erasure, $this->ip, $this->ja4);
    }
}
