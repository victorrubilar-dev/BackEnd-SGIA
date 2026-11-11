<?php

namespace App\Exceptions;

use App\Services\PurchaseRequestStateMachine;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Transición de estado no permitida en una solicitud de compra. Se responde como `422` en `status`.
 */
class InvalidPurchaseRequestStatusTransition extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        string $message = ''
    ) {
        parent::__construct(
            $message !== ''
                ? $message
                : 'La solicitud está "'.PurchaseRequestStateMachine::label($from).'": no se puede pasar a "'
                    .PurchaseRequestStateMachine::label($to).'".'
        );
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'errors' => ['status' => [$this->getMessage()]],
            'current_status' => $this->from,
            'attempted_status' => $this->to,
        ], 422);
    }
}
