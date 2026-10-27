<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PresupuestoAnual extends Model
{
    use HasFactory;

    protected $table = 'presupuestos_anuales';

    public const CREATED_AT = null;
    public const UPDATED_AT = 'updated_at';

    protected $fillable = [
        'carrera_id',
        'anio',
        'monto_inicial',
        'monto_disponible',
    ];

    protected function casts(): array
    {
        return [
            'anio' => 'integer',
            'monto_inicial' => 'decimal:2',
            'monto_disponible' => 'decimal:2',
            'updated_at' => 'datetime',
        ];
    }

    public function carrera(): BelongsTo
    {
        return $this->belongsTo(Carrera::class, 'carrera_id');
    }

    public function ingresosOcr(): HasMany
    {
        return $this->hasMany(IngresoOcr::class, 'presupuesto_anual_id');
    }
}
