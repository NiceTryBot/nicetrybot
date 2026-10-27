<?php

namespace App\Models;

use App\Actions\Edge\ResolveLookupIds;
use App\Support\CountryCentroids;
use Database\Factories\LocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Where an IP address geolocates to. Region and city are empty strings when unknown.
 *
 * @property int $id
 * @property string $country_code
 * @property string $region
 * @property string $city
 * @property float|null $latitude The city's (or region's) centre from the edge's geolocation, when it sent one.
 * @property float|null $longitude
 */
#[Fillable(['country_code', 'region', 'city', 'latitude', 'longitude'])]
class Location extends Model
{
    /** @use HasFactory<LocationFactory> */
    use HasFactory;

    /**
     * Indicates if the model should be timestamped.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * Get the id for a location, recording it if it's new.
     */
    public static function idFor(string $countryCode, string $region = '', string $city = ''): int
    {
        return app(ResolveLookupIds::class)->locations([[$countryCode, $region, $city]])[self::key(strtoupper($countryCode), $region, $city)];
    }

    /**
     * Build the key that identifies a location in a batch.
     */
    public static function key(string $countryCode, string $region, string $city): string
    {
        return $countryCode.'|'.$region.'|'.$city;
    }

    /**
     * Get where to put the location on a map as [latitude, longitude]: its own coordinates, else its
     * country's centre, else null.
     *
     * @return array{0: float, 1: float}|null
     */
    public function coordinates(): ?array
    {
        if ($this->latitude !== null && $this->longitude !== null) {
            return [(float) $this->latitude, (float) $this->longitude];
        }

        return CountryCentroids::for($this->country_code);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /**
     * Get the place as people write it, e.g. "Montreal, Quebec, CA".
     */
    public function label(): string
    {
        return implode(', ', array_filter([$this->city, $this->region, $this->country_code]));
    }
}
