<?php

namespace App\Services;

use App\Models\Career;
use App\Models\CodeSequence;
use App\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CodeSequenceService
{
    /**
     * Siguiente código `PREFIJO-CARRERA-000001`. La función de PostgreSQL bloquea
     * la fila del secuenciador, por lo que es atómica con llamadas concurrentes.
     *
     * @throws ValidationException si no existe el secuenciador
     */
    public function next(string $prefix, string $career): string
    {
        try {
            return DB::transaction(
                fn () => DB::selectOne('select fn_generar_codigo_sgia(?, ?) as code', [$prefix, $career])->code
            );
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'No existe secuenciador')) {
                throw ValidationException::withMessages([
                    'career_id' => "No existe un secuenciador para el prefijo {$prefix} y la carrera {$career}.",
                ]);
            }

            throw $e;
        }
    }

    public function forProduct(Product $product): string
    {
        return $this->forType($product->type, $product->career_id);
    }

    public function forType(string $type, ?int $careerId): string
    {
        $code = $careerId ? Career::whereKey($careerId)->value('code') : null;

        if (! $code) {
            throw ValidationException::withMessages([
                'career_id' => 'El producto debe tener una carrera con código para generar el código de barras.',
            ]);
        }

        return $this->next($this->prefixFor($type), $code);
    }

    public function prefixFor(string $type): string
    {
        return match ($type) {
            Product::TYPE_SUPPLY => CodeSequence::PREFIX_SUPPLY,
            Product::TYPE_SCALE_MODEL => CodeSequence::PREFIX_SCALE_MODEL,
            default => CodeSequence::PREFIX_PRODUCT,
        };
    }
}
