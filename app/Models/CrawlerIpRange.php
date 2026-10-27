<?php

namespace App\Models;

use App\Enums\VerifiedCrawler;
use Database\Factories\CrawlerIpRangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One IP range (CIDR) a crawler's operator says the crawler uses.
 *
 * @property int $id
 * @property VerifiedCrawler $crawler
 * @property string $cidr
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['crawler', 'cidr'])]
class CrawlerIpRange extends Model
{
    /** @use HasFactory<CrawlerIpRangeFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'crawler' => VerifiedCrawler::class,
        ];
    }
}
