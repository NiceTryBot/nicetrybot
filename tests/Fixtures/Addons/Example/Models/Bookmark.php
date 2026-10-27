<?php

namespace Tests\Fixtures\Addons\Example\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The example add-on's model: a page a team bookmarked on one of its domains, maybe on a Livewire
 * component. Tests make its table with createTable(), since the add-on is installed after the
 * database is migrated.
 *
 * @property int $id
 * @property int $team_id
 * @property int|null $domain_id
 * @property int|null $livewire_component_id
 * @property string $path
 * @property int|null $edge_request_id The request the edge saw it on, when the import kept it.
 */
#[Fillable(['team_id', 'domain_id', 'livewire_component_id', 'path', 'edge_request_id'])]
class Bookmark extends Model
{
    protected $table = 'example_bookmarks';

    public $timestamps = false;

    public static function createTable(): void
    {
        Schema::create('example_bookmarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id');
            $table->foreignId('domain_id')->nullable();
            $table->foreignId('livewire_component_id')->nullable();
            $table->string('path');
            $table->foreignId('edge_request_id')->nullable();
        });
    }
}
