<?php

namespace App\Exceptions;

use App\Services\MaintenanceRecordStateMachine;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Transición inválida de una mantención (ver MaintenanceRecordStateMachine).
 */
class InvalidMaintenanceRecordStatusTransition extends RuntimeException
{
    public function __construct(public readonly string $from, public readonly string $to)
    {
        $allowed = MaintenanceRecordStateMachine::allowedFrom($from);

        parent::__construct(
            "Transición de estado no permitida: la mantención no puede pasar de \"{$from}\" a \"{$to}\". "
            .'Estados permitidos desde "'.$from.'": '
            .($allowed === [] ? 'ninguno (estado final).' : implode(', ', $allowed).'.')
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['status' => [$this->getMessage()]],
            'current_status' => $this->from,
            'attempted_status' => $this->to,
            'allowed_transitions' => MaintenanceRecordStateMachine::allowedFrom($this->from),
        ], 422);
    }
}
