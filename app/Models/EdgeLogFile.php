<?php

namespace App\Models;

use Database\Factories\EdgeLogFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A batch file of edge request logs that has been imported from S3.
 *
 * @property int $id
 * @property string $key
 * @property int $rows_imported
 * @property int $rows_skipped
 * @property Carbon $imported_at
 */
#[Fillable([
    'key',
    'rows_imported',
    'rows_skipped',
    'imported_at',
])]
class EdgeLogFile extends Model
{
    /** @use HasFactory<EdgeLogFileFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rows_imported' => 'integer',
            'rows_skipped' => 'integer',
            'imported_at' => 'datetime',
        ];
    }
}
