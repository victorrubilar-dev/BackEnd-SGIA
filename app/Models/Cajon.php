<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Cajon extends Model
{
    use HasFactory;

    protected $table = 'cajones';

    /** Largo máximo del `codigo` normalizado, para que el Code128 quepa en la etiqueta de 50 mm. */
    public const CODE_MAX_LENGTH = 12;

    protected $fillable = [
        'codigo',
        'descripcion',
        'location_id',
        'estante_id',
        'lleno',
        'created_by',
        'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            // Marcado a mano desde la lista o el mapa (`docs/cajones-llenos.md`).
            'lleno' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        // `barcode` = `{locations.code}-{codigo normalizado}` (`L206-RACK-R1`); se recalcula al cambiar el código o la sala.
        static::saving(function (Cajon $cajon) {
            // Un estante es de una sola sala: si el cajón cambia de sala sin estante nuevo, queda sin estante (REQ-22).
            if ($cajon->exists && $cajon->isDirty('location_id') && ! $cajon->isDirty('estante_id')) {
                $cajon->estante_id = null;
            }

            if (! $cajon->exists || $cajon->isDirty(['codigo', 'location_id']) || blank($cajon->barcode)) {
                $cajon->barcode = static::buildBarcode(
                    (string) Location::whereKey($cajon->location_id)->value('code'),
                    (string) $cajon->codigo,
                );
            }
        });
    }

    /**
     * Normaliza el código del cajón: sin tildes, mayúsculas y todo lo que no sea `[A-Z0-9]`
     * pasa a un guion, sin guiones repetidos ni en los extremos (`Mesa 1 Cajón` → `MESA-1-CAJON`).
     */
    public static function normalizeCode(string $codigo): string
    {
        return trim((string) preg_replace('/[^A-Z0-9]+/', '-', Str::upper(Str::ascii($codigo))), '-');
    }

    /**
     * Código completo del cajón. El segmento del cajón se limita a 12 caracteres
     * (la validación ya lo exige; aquí cubre datos antiguos).
     */
    public static function buildBarcode(string $locationCode, string $codigo): string
    {
        return $locationCode.'-'.rtrim(substr(static::normalizeCode($codigo), 0, self::CODE_MAX_LENGTH), '-');
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    /** @return BelongsTo<Estante, $this> */
    public function estante(): BelongsTo
    {
        return $this->belongsTo(Estante::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /** @return HasMany<EquipmentUnit, $this> */
    public function equipmentUnits(): HasMany
    {
        return $this->hasMany(EquipmentUnit::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
