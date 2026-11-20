<?php

namespace App\Http\Requests;

use App\Models\Cajon;
use App\Models\Estante;
use App\Models\Location;
use App\Models\Zona;
use App\Services\RoomMapService;
use Illuminate\Validation\Validator;

/**
 * Validación compartida del estante (REQ-22, `docs/mapa-salas.md`): código por sala, zona de la misma
 * sala, límites de la sala y superposición con otros estantes, sobre los valores resultantes.
 */
final class EstanteRules
{
    /**
     * @param  array{codigo: string, zona_id: int|null, width: int, depth: int, rotation: int, x: int|null, y: int|null}  $values
     */
    public static function validate(Validator $validator, Location $location, array $values, bool $checkCode, ?int $ignoreId = null): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        if ($checkCode) {
            $codigo = Cajon::normalizeCode($values['codigo']);
            if ($codigo === '') {
                $validator->errors()->add('codigo', 'El código del estante debe tener al menos una letra o un número.');

                return;
            }
            if (strlen($codigo) > Estante::CODE_MAX_LENGTH) {
                $validator->errors()->add('codigo', 'El código del estante puede tener como máximo '.Estante::CODE_MAX_LENGTH.' caracteres ('.$codigo.' tiene '.strlen($codigo).').');

                return;
            }
            $taken = Estante::where('location_id', $location->id)->where('codigo', $codigo)
                ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists();
            if ($taken) {
                $validator->errors()->add('codigo', "Ya existe un estante con el código {$codigo} en esta sala.");

                return;
            }
        }

        if ($values['zona_id'] !== null && ! Zona::whereKey($values['zona_id'])->where('location_id', $location->id)->exists()) {
            $validator->errors()->add('zona_id', 'La zona no pertenece a la sala.');

            return;
        }

        if ($values['x'] === null || $values['y'] === null) {
            return;
        }

        $map = app(RoomMapService::class);
        $rect = $map->occupiedRect($values['x'], $values['y'], $values['width'], $values['depth'], $values['rotation']);

        if (! $map->contains($map->roomRect($location), $rect)) {
            $validator->errors()->add('x', 'El estante queda fuera de la sala.');

            return;
        }

        $other = $map->overlappingEstante($location->id, $rect, $ignoreId);
        if ($other !== null) {
            $validator->errors()->add('x', "Se superpone con el estante {$other->codigo}.");
        }
    }
}
