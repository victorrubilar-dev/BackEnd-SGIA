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

    /**
     * Siguiente código anual `PREFIJO-AAAA-NNNN` (p. ej. `SC-2026-0001`). El upsert bloquea la fila
     * de `yearly_code_sequences`, por lo que es atómico con llamadas concurrentes.
     */
    public function nextYearly(string $prefix, ?int $year = null): string
    {
        $year ??= (int) now()->year;

        $index = DB::selectOne(
            'insert into yearly_code_sequences (prefix, year, current_index, created_at, updated_at)
             values (?, ?, 1, now(), now())
             on conflict (prefix, year) do update
                set current_index = yearly_code_sequences.current_index + 1, updated_at = now()
             returning current_index',
            [$prefix, $year]
        )->current_index;

        return sprintf('%s-%d-%04d', $prefix, $year, $index);
    }

    /** Código de la solicitud de compra: `SC-AAAA-NNNN`. */
    public function forPurchaseRequest(?int $year = null): string
    {
        return $this->nextYearly('SC', $year);
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
            default => CodeSequence::PREFIX_EQUIPMENT,
        };
    }
}
