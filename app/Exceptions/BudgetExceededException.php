<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/** La factura supera lo libre del presupuesto: 422 (alerta) o 403 si pidió autorizar quien no es DIR-01. */
class BudgetExceededException extends Exception
{
    public function __construct(private readonly int $overCents, private readonly int $freeCents, private readonly int $totalCents, private readonly int $status = 422)
    {
        parent::__construct($status === 403
            ? 'Solo el director de carrera puede autorizar un gasto sobre el presupuesto.'
            : 'El total de la factura supera el presupuesto libre.');
    }

    public function render(): JsonResponse
    {
        $money = fn (int $cents) => number_format($cents / 100, 2, '.', '');

        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'over_budget',
            'over_by' => $money($this->overCents),
            'free_amount' => $money($this->freeCents),
            'total' => $money($this->totalCents),
        ], $this->status);
    }
}
