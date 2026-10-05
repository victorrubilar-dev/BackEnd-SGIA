<?php

namespace App\Exceptions;

use App\Services\QuotationStateMachine;
use RuntimeException;

/**
 * Se lanza cuando se intenta una transición de estado inválida en una
 * cotización, violando su máquina de estados.
 */
class InvalidQuotationStatusTransition extends RuntimeException
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        string $message = ''
    ) {
        $allowed = QuotationStateMachine::allowedFrom($from);

        parent::__construct(
            $message !== ''
                ? $message
                : "Transición de estado no permitida: no se puede pasar de \"{$from}\" a \"{$to}\". "
                    . 'Estados permitidos desde "' . $from . '": '
                    . ($allowed === [] ? 'ninguno (estado final).' : implode(', ', $allowed) . '.')
        );
    }
}
