<?php

namespace NiceTry\Transfer\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use NiceTry\Origins\Models\Origin;
use NiceTry\Transfer\Actions\ExportOrigin;
use NiceTry\Transfer\OriginsAddon;
use Symfony\Component\HttpFoundation\Response;

/**
 * One of the team's origins as a transfer file, for whoever may change it: it holds the origin's
 * secret, so it's never cached.
 */
class DownloadTransferFile extends Controller
{
    public function __invoke(Team $currentTeam, int $origin, ExportOrigin $export): Response
    {
        abort_unless(OriginsAddon::isOn(), 404);

        $model = Origin::query()->where('team_id', $currentTeam->id)->findOrFail($origin);
        Gate::authorize('update', $model);

        $name = 'nicetrybot-transfer-'.(Str::slug($model->name) ?: 'origin').'-'.now()->format('Y-m-d').'.json';
        $json = (string) json_encode($export->handle($model), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
