<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

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

    /** Largo máximo del código de sala (`locations.code`). */
    public const CODE_MAX_LENGTH = 10;

    /** Formato del código de sala: solo letras y dígitos, de 2 a 10. */
    public const CODE_PATTERN = '/^[A-Z0-9]{2,10}$/';

    /** Palabras que no aportan al código sugerido. */
    private const CODE_STOPWORDS = ['DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS', 'Y'];

    protected $fillable = [
        'code',
        'nombre',
        'tipo',
        'sala',
        'cajon',
        'descripcion',
        'storeroom_id',
        'building',
        'floor',
        'created_by',
        'updated_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (Location $location) {
            $location->storeroom_id ??= Storeroom::default()->id;
            $location->code = filled($location->code)
                ? Str::upper((string) $location->code)
                : static::suggestCode((string) ($location->nombre ?? $location->sala));
        });
    }

    /**
     * Código sugerido a partir del nombre (`docs/codigos-ubicacion.md`), único en el sistema:
     * si ya existe se le agrega un sufijo numérico (`PREDES2`).
     */
    public static function suggestCode(string $nombre): string
    {
        $base = static::baseCode($nombre);
        $code = $base;

        for ($suffix = 2; static::where('code', $code)->exists(); $suffix++) {
            $code = substr($base, 0, self::CODE_MAX_LENGTH - strlen((string) $suffix)).$suffix;
        }

        return $code;
    }

    /**
     * Regla de sugerencia sin verificar unicidad: número de sala (`L-206` → `L206`) o,
     * si no hay, la inicial de la primera palabra y el resto pegadas (`Pañol Central` → `PCENTRAL`).
     */
    public static function baseCode(string $nombre): string
    {
        $name = Str::upper(Str::ascii($nombre));

        if (preg_match('/(?<![A-Z0-9])([A-Z]{1,2})[- ]?(\d{2,4})(?![0-9])/', $name, $match)) {
            return $match[1].$match[2];
        }

        $words = array_values(array_filter(
            preg_split('/[^A-Z0-9]+/', $name) ?: [],
            fn (string $word) => $word !== '' && ! in_array($word, self::CODE_STOPWORDS, true),
        ));

        $code = match (count($words)) {
            0 => '',
            1 => substr($words[0], 0, 8),
            default => substr($words[0][0].implode('', array_slice($words, 1)), 0, self::CODE_MAX_LENGTH),
        };

        return strlen($code) >= 2 ? $code : 'SALA';
    }

    protected function casts(): array
    {
        return ['floor' => 'integer'];
    }

    /** @return BelongsTo<Storeroom, $this> */
    public function storeroom(): BelongsTo
    {
        return $this->belongsTo(Storeroom::class);
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

    /** @return HasMany<Cajon, $this> */
    public function cajones(): HasMany
    {
        return $this->hasMany(Cajon::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
