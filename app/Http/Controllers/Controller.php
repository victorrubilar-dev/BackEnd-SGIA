<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Tamaño de página de los listados: `per_page` entre 1 y 100 (15 por defecto).
     */
    protected function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 15)));
    }

    /**
     * Valida el período `from` / `to` (fechas, `to >= from`) de un listado; el export lo valida su FormRequest
     * con `ExportRequest::dateRange()` (docs/centro-reportes.md, decisión 6).
     */
    protected function validateDateRange(Request $request): void
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ], [
            'from.date' => 'La fecha inicial no es válida.',
            'to.date' => 'La fecha final no es válida.',
            'to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);
    }

    /**
     * `422` con el formato de error normalizado de la API: `message` + `errors` (campo => mensajes),
     * más datos adicionales opcionales (p. ej. `allowed_transitions`).
     *
     * @param  array<string, mixed>  $extra
     */
    protected function unprocessable(string $message, string $field, array $extra = []): JsonResponse
    {
        return response()->json(['message' => $message, 'errors' => [$field => [$message]], ...$extra], 422);
    }
}
