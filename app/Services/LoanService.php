<?php

namespace App\Services;

use App\Exceptions\InvalidLoanStatusTransition;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\User;
use App\Notifications\LoanStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Servicio de la entidad Loan (FU-04).
 *
 * REQ-09: creación de la solicitud remota del docente con validación de
 * disponibilidad de stock. REQ-10: operaciones del pañol (aprobar, rechazar,
 * entregar y préstamo presencial directo) con descuento atómico de stock y
 * notificación al solicitante.
 */
class LoanService
{
    public function __construct(private readonly StockAlertService $stockAlertService)
    {
    }

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
     * Aprueba la solicitud remota: descuenta el stock de cada producto,
     * deja el préstamo `en_proceso` y notifica al solicitante (REQ-10).
     *
     * @throws InvalidLoanStatusTransition|ValidationException
     */
    public function approve(Loan $loan, User $approver): Loan
    {
        $loan = DB::transaction(function () use ($loan, $approver) {
            $loan->loadMissing('items');

            // Valida la máquina de estados antes de tocar el stock.
            if (! LoanStateMachine::canTransition($loan->status, LoanStateMachine::STATUS_IN_PROGRESS)) {
                throw new InvalidLoanStatusTransition($loan->status, LoanStateMachine::STATUS_IN_PROGRESS);
            }

            $this->deductStock($loan);

            $this->transition($loan, LoanStateMachine::STATUS_IN_PROGRESS, [
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            return $loan;
        });

        $this->notifyRequester(
            $loan,
            "Tu solicitud de préstamo {$loan->code} fue aprobada y el material quedó separado en {$loan->room}."
        );

        return $loan;
    }

    /**
     * Rechaza la solicitud remota indicando el motivo obligatorio y notifica
     * al solicitante (REQ-10).
     *
     * @throws InvalidLoanStatusTransition
     */
    public function reject(Loan $loan, User $rejector, string $reason): Loan
    {
        $this->transition($loan, LoanStateMachine::STATUS_REJECTED, [
            'rejection_reason' => $reason,
        ]);

        $this->notifyRequester($loan, "Tu solicitud de préstamo {$loan->code} fue rechazada. Motivo: {$reason}");

        return $loan;
    }

    /**
     * Confirma la entrega material al docente: `en_proceso` -> `procesado`.
     *
     * @throws InvalidLoanStatusTransition
     */
    public function deliver(Loan $loan, User $processor): Loan
    {
        $this->transition($loan, LoanStateMachine::STATUS_PROCESSED, [
            'processed_by' => $processor->id,
            'processed_at' => now(),
        ]);

        $this->notifyRequester(
            $loan,
            "Tu préstamo {$loan->code} fue entregado: {$loan->room} el {$loan->loan_date?->toDateString()}."
        );

        return $loan;
    }

    /**
     * Registra un préstamo presencial directo en el pañol: crea el préstamo
     * `procesado` y descuenta el stock atómicamente (REQ-10).
     *
     * @param  array<string, mixed>  $validated  datos validados por StoreLoanCheckoutRequest
     *
     * @throws ValidationException
     */
    public function checkout(array $validated, User $operator): Loan
    {
        $items = $validated['items'];

        $this->assertItemsAvailable($items);

        return DB::transaction(function () use ($validated, $operator, $items) {
            $loan = Loan::create([
                'code' => Loan::generateUniqueCode(Loan::TYPE_DIRECT),
                'type' => Loan::TYPE_DIRECT,
                'requested_by' => null,
                'borrower_name' => $validated['borrower_name'],
                'borrower_document' => $validated['borrower_document'] ?? null,
                'subject' => $validated['subject'],
                'room' => $validated['room'],
                'loan_date' => $validated['loan_date'],
                'time_block' => $validated['time_block'] ?? null,
                'status' => LoanStateMachine::STATUS_PROCESSED,
                'processed_by' => $operator->id,
                'processed_at' => now(),
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->attachItems($loan, $items);
            $loan->load('items');
            $this->deductStock($loan);

            return $loan;
        });
    }

    /**
     * Transiciona el préstamo a otro estado respetando la máquina de estados
     * y persiste los datos complementarios (aprobador, motivo, entrega...).
     *
     * @param  array<string, mixed>  $attributes
     *
     * @throws InvalidLoanStatusTransition
     */
    public function transition(Loan $loan, string $toStatus, array $attributes = []): Loan
    {
        if (! LoanStateMachine::canTransition($loan->status, $toStatus)) {
            throw new InvalidLoanStatusTransition($loan->status, $toStatus);
        }

        $loan->forceFill($attributes + ['status' => $toStatus])->save();

        return $loan;
    }

    /**
     * Descuenta el stock de los productos del préstamo dentro de la
     * transacción, bloqueando las filas para evitar stock negativo por
     * solicitudes concurrentes. También evalúa las alertas de stock (REQ-06).
     *
     * @throws ValidationException
     */
    private function deductStock(Loan $loan): void
    {
        foreach ($loan->items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $product = Product::whereKey($item->product_id)->lockForUpdate()->first();

            if ($product === null) {
                throw ValidationException::withMessages([
                    'items' => "El producto \"{$item->name}\" ya no existe en el inventario.",
                ]);
            }

            if ($product->quantity < $item->quantity) {
                throw ValidationException::withMessages([
                    'items' => "Stock insuficiente para \"{$product->name}\": "
                        . "se requieren {$item->quantity} y hay {$product->quantity} disponibles.",
                ]);
            }

            $product->quantity -= $item->quantity;
            $product->save();

            $this->stockAlertService->checkStock($product);
        }
    }

    /**
     * Notifica al docente solicitante, si tiene usuario en el sistema.
     */
    private function notifyRequester(Loan $loan, string $message): void
    {
        $requester = $loan->requested_by !== null
            ? User::find($loan->requested_by)
            : null;

        if ($requester !== null && $requester->is_active) {
            Notification::send($requester, new LoanStatusNotification($loan, $message));
        }
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
