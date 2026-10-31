<?php

namespace App\Exceptions;

use App\Services\EquipmentUnitStateMachine;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Transición inválida del estado de una unidad de equipo (ver EquipmentUnitStateMachine).
 */
class InvalidEquipmentUnitStatusTransition extends RuntimeException
{
    public function __construct(public readonly string $from, public readonly string $to)
    {
        $allowed = EquipmentUnitStateMachine::allowedFrom($from);

        parent::__construct(
            "Transición de estado no permitida: no se puede pasar de \"{$from}\" a \"{$to}\". "
            .'Estados permitidos desde "'.$from.'": '
            .($allowed === [] ? 'ninguno (estado final).' : implode(', ', $allowed).'.')
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'current_status' => $this->from,
            'attempted_status' => $this->to,
            'allowed_transitions' => EquipmentUnitStateMachine::allowedFrom($this->from),
        ], 422);
    }
}
