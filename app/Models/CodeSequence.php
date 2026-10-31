<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Secuenciador de códigos de barra `PREFIJO-CARRERA-000001` (ver `fn_generar_codigo_sgia`).
 */
class CodeSequence extends Model
{
    public const PREFIX_SCALE_MODEL = 'MAQ';

    public const PREFIX_SUPPLY = 'INS';

    public const PREFIX_PRODUCT = 'PRO';

    /** @var array<string, string> prefijo => nombre */
    public const PREFIXES = [
        self::PREFIX_SCALE_MODEL => 'maquetas',
        self::PREFIX_SUPPLY => 'insumos',
        self::PREFIX_PRODUCT => 'productos',
    ];

    protected $fillable = ['name', 'prefix', 'career', 'current_index'];

    protected function casts(): array
    {
        return ['current_index' => 'integer'];
    }
}
