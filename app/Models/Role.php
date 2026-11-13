<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Catálogo fijo de roles (`AD-01`, `DIR-01`, `PAN-01`, `PRO-01`), sembrado por migración.
 * `users.role_id` apunta aquí; la API recibe y devuelve el código.
 */
class Role extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name'];

    /** @var array<string, int>|null código => id (los roles no cambian en ejecución) */
    private static ?array $ids = null;

    public static function idFor(string $code): int
    {
        return self::map()[$code] ?? throw new InvalidArgumentException("Rol desconocido: {$code}.");
    }

    /**
     * @param  array<int, string>  $codes
     * @return array<int, int>
     */
    public static function idsFor(array $codes): array
    {
        return array_values(array_map(fn (string $code) => self::idFor($code), $codes));
    }

    public static function codeFor(?int $id): ?string
    {
        if ($id === null) {
            return null;
        }

        $code = array_search($id, self::map(), true);
        if ($code === false) {
            self::$ids = null;
            $code = array_search($id, self::map(), true);
        }

        return $code === false ? null : $code;
    }

    /**
     * @return array<string, int>
     */
    private static function map(): array
    {
        return self::$ids ??= self::query()->pluck('id', 'code')->map(fn ($id) => (int) $id)->all();
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
