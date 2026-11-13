<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla de dashboard con nombre de un usuario: agrupa hasta `UserChart::MAX_POSITION` gráficos.
 */
class DashboardTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'description'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<UserChart, $this> */
    public function charts(): HasMany
    {
        return $this->hasMany(UserChart::class);
    }
}
