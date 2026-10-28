<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Location extends Model
{
    use HasFactory;

    public const TIPO_SALA = 'sala';

    public const TIPO_PANOL = 'panol';

    public const TIPO_TALLER = 'taller';

    public const TIPO_BODEGA = 'bodega';

    public const TIPOS = [
        self::TIPO_SALA,
        self::TIPO_PANOL,
        self::TIPO_TALLER,
        self::TIPO_BODEGA,
    ];

    protected $fillable = [
        'nombre',
        'tipo',
        'sala',
        'cajon',
        'descripcion',
        'created_by',
        'updated_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function cajones(): HasMany
    {
        return $this->hasMany(Cajon::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
