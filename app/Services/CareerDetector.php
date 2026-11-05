<?php

namespace App\Services;

use App\Models\Career;
use Illuminate\Support\Str;

/**
 * Detecta la carrera de una factura a partir de su texto: por nombre ("Ingeniería en
 * Ciberseguridad") o por sigla (ISD, II, IC, AP). Si no aparece ninguna, o aparecen
 * varias distintas, no adivina: devuelve null y el usuario elige el presupuesto.
 */
class CareerDetector
{
    public function detect(string $text): ?Career
    {
        if (trim($text) === '') {
            return null;
        }

        $normalized = Str::lower(Str::ascii($text));
        $matches = Career::query()->get()->filter(function (Career $career) use ($text, $normalized) {
            $name = Str::lower(Str::ascii(trim($career->name)));

            if ($name !== '' && str_contains($normalized, $name)) {
                return true;
            }

            // La sigla se busca en mayúsculas y como palabra completa para evitar falsos positivos.
            return $career->code !== null
                && preg_match('/(?<![\p{L}\d])'.preg_quote($career->code, '/').'(?![\p{L}\d])/u', $text) === 1;
        });

        return $matches->count() === 1 ? $matches->first() : null;
    }
}
