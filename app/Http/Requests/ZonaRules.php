<?php

namespace App\Http\Requests;

use App\Models\Location;
use App\Models\Zona;
use App\Services\RoomMapService;
use Illuminate\Validation\Validator;

/**
 * Validación compartida de la zona (REQ-22): nombre único por sala y dentro de los muros.
 * Las zonas pueden superponerse entre sí y contener estantes.
 */
final class ZonaRules
{
    /**
     * @param  array{nombre: string, x: int, y: int, width: int, depth: int}  $values
     */
    public static function validate(Validator $validator, Location $location, array $values, ?int $ignoreId = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $nombre = trim($values['nombre']);
        $taken = Zona::where('location_id', $location->id)
            ->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists();
        if ($taken) {
            $validator->errors()->add('nombre', "Ya existe una zona llamada {$nombre} en esta sala.");

            return;
        }

        $map = app(RoomMapService::class);
        if (! $map->contains($map->roomRect($location), [$values['x'], $values['y'], $values['width'], $values['depth']])) {
            $validator->errors()->add('x', 'La zona queda fuera de la sala.');
        }
    }
}
