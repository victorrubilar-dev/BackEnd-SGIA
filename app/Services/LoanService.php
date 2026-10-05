<?php

namespace App\Services;

use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Servicio de la entidad Loan (FU-04).
 *
 * REQ-09: creación de la solicitud remota del docente con validación de
 * disponibilidad de stock. REQ-10 agrega las operaciones del pañol
 * (aprobar / rechazar / entregar / préstamo presencial) reutilizando estos
 * métodos base.
 */
class LoanService
{
    /**
     * Crea la solicitud remota de préstamo del docente en estado `pendiente`.
     *
     * @param  array<string, mixed>  $validated  datos validados por StoreLoanRequest
     */
    public function createRequest(array $validated, User $creator): Loan
    {
        $items = $validated['items'];

        $this->assertItemsAvailable($items);

        return DB::transaction(function () use ($validated, $creator, $items) {
            $loan = Loan::create([
                'code' => Loan::generateUniqueCode(Loan::TYPE_REMOTE),
                'type' => Loan::TYPE_REMOTE,
                'requested_by' => $creator->id,
                'borrower_name' => $creator->name,
                'subject' => $validated['subject'],
                'room' => $validated['room'],
                'loan_date' => $validated['loan_date'],
                'time_block' => $validated['time_block'] ?? null,
                'status' => LoanStateMachine::STATUS_PENDING,
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->attachItems($loan, $items);

            return $loan;
        });
    }

    /**
     * Valida que cada producto exista, esté activo y tenga stock suficiente
     * para la cantidad solicitada.
     *
     * @param  array<int, array<string, mixed>>  $items
     *
     * @throws ValidationException
     */
    public function assertItemsAvailable(array $items): void
    {
        foreach ($items as $index => $item) {
            $product = Product::find($item['product_id'] ?? 0);

            if ($product === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'El producto solicitado no existe en el inventario.',
                ]);
            }

            if (! $product->is_active) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => "El producto \"{$product->name}\" está inactivo y no puede prestarse.",
                ]);
            }

            $requested = (int) $item['quantity'];

            if ($product->quantity < $requested) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => "Stock insuficiente para \"{$product->name}\": "
                        . "se solicitaron {$requested} y hay {$product->quantity} disponibles.",
                ]);
            }
        }
    }

    /**
     * Crea los ítems del préstamo tomando el nombre actual del producto.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    public function attachItems(Loan $loan, array $items): Loan
    {
        foreach ($items as $item) {
            $product = Product::find($item['product_id']);

            LoanItem::create([
                'loan_id' => $loan->id,
                'product_id' => $product?->id,
                'name' => $product?->name ?? ('Producto #' . $item['product_id']),
                'quantity' => (int) $item['quantity'],
            ]);
        }

        return $loan;
    }
}
