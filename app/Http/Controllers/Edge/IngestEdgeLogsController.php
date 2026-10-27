<?php

namespace App\Http\Controllers\Edge;

use App\Actions\Edge\StoreEdgeLogLines;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class IngestEdgeLogsController extends Controller
{
    /**
     * Store a batch of log lines the edge sends every couple of seconds for domains someone is watching
     * live (see ListLiveTailsController). The body is newline-delimited JSON in the same shape as the S3
     * files, which still carry every line, so the minute import fills anything this misses and skips
     * what it already stored. A failure answers 5xx and the edge retries the batch.
     */
    public function __invoke(Request $request, StoreEdgeLogLines $storeEdgeLogLines): JsonResponse
    {
        $lines = StoreEdgeLogLines::decode($request->getContent());

        return response()->json(DB::transaction(fn (): array => $storeEdgeLogLines->handle($lines)));
    }
}
