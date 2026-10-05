<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Se lanza cuando se intenta una transición de estado inválida en una orden
 * de compra, violando la máquina de estados pendiente -> en_camino -> completa.
 */
class InvalidPurchaseStatusTransition extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        string $message = ''
    ) {
        $allowed = \App\Services\PurchaseStateMachine::allowedFrom($from);

        parent::__construct(
            $message !== ''
                ? $message
                : "Transición de estado no permitida: no se puede pasar de \"{$from}\" a \"{$to}\". "
                    . 'Estados permitidos desde "' . $from . '": '
                    . ($allowed === [] ? 'ninguno (estado final).' : implode(', ', $allowed) . '.')
        );
    }
}
