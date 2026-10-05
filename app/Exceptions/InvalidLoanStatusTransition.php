<?php

namespace App\Exceptions;

use App\Services\LoanStateMachine;
use RuntimeException;

/**
 * Se lanza cuando se intenta una transición de estado inválida en un préstamo,
 * violando la máquina de estados pendiente -> en_proceso -> procesado.
 */
class InvalidLoanStatusTransition extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        string $message = ''
    ) {
        $allowed = LoanStateMachine::allowedFrom($from);

        parent::__construct(
            $message !== ''
                ? $message
                : "Transición de estado no permitida: no se puede pasar de \"{$from}\" a \"{$to}\". "
                    . 'Estados permitidos desde "' . $from . '": '
                    . ($allowed === [] ? 'ninguno (estado final).' : implode(', ', $allowed) . '.')
        );
    }
}
