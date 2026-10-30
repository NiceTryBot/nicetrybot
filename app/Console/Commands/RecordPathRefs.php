<?php

namespace App\Console\Commands;

use App\Actions\Edge\RecordPathRefs as RecordRefs;
use App\Models\RequestPath;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('edge:record-path-refs')]
#[Description('Record the visitor record every recorded path nesting two UUIDs points at, into request_path_refs (run after deploying the table)')]
class RecordPathRefs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(RecordRefs $recordRefs): int
    {
        $paths = 0;

        RequestPath::query()->toBase()
            ->where('path', 'like', '%/%-%-%-%-%/%-%-%-%-%')
            ->orderBy('id')
            ->chunkById(1000, function ($rows) use ($recordRefs, &$paths) {
                $recordRefs->paths($rows->pluck('id', 'path')->all());
                $paths += $rows->count();
            });

        $this->info(sprintf('Checked %d path(s) for refs.', $paths));

        return self::SUCCESS;
    }
}
