<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Pañol (bodega). Pañol por defecto: `Storeroom::DEFAULT_CODE`.
 */
class Storeroom extends Model
{
    use HasFactory;

    public const DEFAULT_CODE = 'PAN-TEMUCO';

    protected $fillable = ['name', 'code', 'general_location', 'created_by', 'updated_by'];

    public static function default(): self
    {
        return static::firstOrCreate(
            ['code' => self::DEFAULT_CODE],
            ['name' => 'Pañol Temuco', 'general_location' => 'Sede Temuco']
        );
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }
}
