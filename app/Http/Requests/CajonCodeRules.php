<?php

namespace App\Http\Requests;

use App\Models\Cajon;
use App\Models\Location;
use Illuminate\Validation\Validator;

/**
 * Validación compartida del código del cajón (`docs/codigos-ubicacion.md`): `codigo` normalizado
 * no vacío, de hasta 12 caracteres y sin repetirse en la misma sala.
 */
final class CajonCodeRules
{
    /**
     * @param  bool  $checkFormat  si se envió `codigo` (en edición, mover de sala no revalida el largo)
     */
    public static function validate(Validator $validator, string $codigo, int $locationId, bool $checkFormat, ?int $ignoreId = null): void
    {
        if ($validator->errors()->hasAny(['codigo', 'location_id'])) {
            return;
        }

        $normalized = Cajon::normalizeCode($codigo);

        if ($checkFormat && $normalized === '') {
            $validator->errors()->add('codigo', 'El código del cajón debe tener al menos una letra o un número.');

            return;
        }

        if ($checkFormat && strlen($normalized) > Cajon::CODE_MAX_LENGTH) {
            $validator->errors()->add('codigo', 'El código del cajón puede tener como máximo '.Cajon::CODE_MAX_LENGTH.' caracteres ('.$normalized.' tiene '.strlen($normalized).').');

            return;
        }

        $barcode = Cajon::buildBarcode((string) Location::whereKey($locationId)->value('code'), $codigo);

        $taken = Cajon::where('barcode', $barcode)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            $validator->errors()->add('codigo', 'Ya existe un cajón con el código '.substr($barcode, strpos($barcode, '-') + 1).' en esta sala.');
        }
    }
}
