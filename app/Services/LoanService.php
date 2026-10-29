<?php

namespace App\Services;

use App\Exceptions\InvalidLoanStatusTransition;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\User;
use App\Notifications\LoanStatusNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
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
    public function __construct(private readonly StockAlertService $stockAlertService) {}

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
                'due_date' => $validated['due_date'] ?? $validated['loan_date'],
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
                'due_date' => $validated['due_date'] ?? $validated['loan_date'],
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
                        ."se requieren {$item->quantity} y hay {$product->quantity} disponibles.",
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
                        ."se solicitaron {$requested} y hay {$product->quantity} disponibles.",
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
                'name' => $product?->name ?? ('Producto #'.$item['product_id']),
                'quantity' => (int) $item['quantity'],
            ]);
        }

        return $loan;
    }

    /**
     * Registra la devolución de uno o más ítems de un préstamo entregado.
     *
     * Por ítem: `bueno`/`regular` reintegran el stock; `danado` suma a
     * `quantity_in_repair`; `extraviado` suma a `quantity_written_off`. Cuando
     * todos los ítems fueron devueltos el préstamo queda con `returned_at`.
     *
     * @param  array<string, mixed>  $validated  datos validados por StoreLoanCheckinRequest
     *
     * @throws ValidationException
     */
    public function checkin(array $validated, User $operator): Loan
    {
        return DB::transaction(function () use ($validated, $operator) {
            $loan = Loan::whereKey($validated['loan_id'])->lockForUpdate()->firstOrFail();
            $loan->load('items');

            if (! $loan->isProcessed()) {
                throw ValidationException::withMessages([
                    'loan_id' => 'Solo se pueden devolver préstamos entregados (estado procesado).',
                ]);
            }

            $seen = [];

            foreach ($validated['items'] as $index => $row) {
                $item = isset($row['item_id'])
                    ? $loan->items->firstWhere('id', (int) $row['item_id'])
                    : $loan->items->firstWhere('product_id', (int) $row['product_id']);

                if ($item === null) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => 'El ítem no pertenece a este préstamo.',
                    ]);
                }

                if ($item->returned_at !== null || in_array($item->id, $seen, true)) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => "El ítem \"{$item->name}\" ya fue devuelto.",
                    ]);
                }

                $seen[] = $item->id;
                $condition = $this->normalizeCondition($row['condition'], (bool) ($row['damaged'] ?? false));

                $this->restoreItem($item, $condition);

                $item->forceFill(['returned_at' => now(), 'return_condition' => $condition])->save();
            }

            $attributes = [];

            if (! empty($validated['general_notes'])) {
                $attributes['return_notes'] = $validated['general_notes'];
            }

            if ($loan->items()->whereNull('returned_at')->doesntExist()) {
                $attributes += ['returned_at' => now(), 'returned_by' => $operator->id];
            }

            $loan->forceFill($attributes)->save();

            return $loan->load('items');
        });
    }

    /**
     * Cancela (elimina) una solicitud remota que sigue pendiente. No tocó stock.
     *
     * @throws ValidationException
     */
    public function cancelRequest(Loan $loan): void
    {
        if (! $loan->isPending()) {
            throw ValidationException::withMessages([
                'loan' => 'Solo se pueden cancelar solicitudes en estado pendiente.',
            ]);
        }

        $loan->delete();
    }

    private function normalizeCondition(string $condition, bool $damaged): string
    {
        $normalized = match (mb_strtolower($condition)) {
            'optimo', 'bueno' => LoanItem::CONDITION_GOOD,
            'regular' => LoanItem::CONDITION_REGULAR,
            'dañado', 'danado' => LoanItem::CONDITION_DAMAGED,
            default => LoanItem::CONDITION_LOST,
        };

        $usable = [LoanItem::CONDITION_GOOD, LoanItem::CONDITION_REGULAR];

        return $damaged && in_array($normalized, $usable, true) ? LoanItem::CONDITION_DAMAGED : $normalized;
    }

    /**
     * Devuelve el ítem al inventario según su condición.
     */
    private function restoreItem(LoanItem $item, string $condition): void
    {
        if ($item->product_id === null) {
            return;
        }

        $product = Product::whereKey($item->product_id)->lockForUpdate()->first();

        if ($product === null) {
            return;
        }

        match ($condition) {
            LoanItem::CONDITION_DAMAGED => $product->quantity_in_repair += $item->quantity,
            LoanItem::CONDITION_LOST => $product->quantity_written_off += $item->quantity,
            default => $product->quantity += $item->quantity,
        };

        $product->save();

        // Al reintegrarse stock se resuelven las alertas que ya no aplican.
        $this->stockAlertService->checkStock($product);
    }

    /**
     * Filtros del historial de préstamos (REQ-11): estado, tipo, sala,
     * profesor, insumo y búsqueda libre. Acepta los alias en español e inglés.
     *
     * @throws ValidationException si `estado` o `tipo` no son válidos
     */
    public function applyHistoryFilters(Builder $query, Request $request): Builder
    {
        $status = $request->input('estado', $request->input('status'));

        if ($status !== null && ! LoanStateMachine::isValidStatus((string) $status)) {
            throw ValidationException::withMessages([
                'estado' => 'El estado debe ser: pendiente, en_proceso, procesado o rechazado.',
            ]);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        $type = $request->input('tipo', $request->input('type'));

        if ($type !== null && ! in_array($type, [Loan::TYPE_REMOTE, Loan::TYPE_DIRECT], true)) {
            throw ValidationException::withMessages([
                'tipo' => 'El tipo debe ser: remoto o presencial.',
            ]);
        }

        if ($type !== null) {
            $query->where('type', $type);
        }

        $room = $request->input('sala') ?? $request->input('room');

        if ($room !== null && $room !== '') {
            $query->where('room', 'ilike', '%'.$room.'%');
        }

        if ($request->boolean('atrasado', $request->boolean('overdue'))) {
            $query->overdue();
        }

        if ($request->filled('devuelto') || $request->filled('returned')) {
            $returned = $request->boolean('devuelto', $request->boolean('returned'));
            $returned ? $query->whereNotNull('returned_at') : $query->whereNull('returned_at');
        }

        $teacher = $request->input('profesor')
            ?? $request->input('teacher')
            ?? $request->input('borrower');

        if ($teacher !== null && $teacher !== '') {
            $like = '%'.$teacher.'%';
            $query->where(function ($q) use ($like) {
                $q->where('borrower_name', 'ilike', $like)
                    ->orWhereHas('requester', fn ($qq) => $qq->where('name', 'ilike', $like));
            });
        }

        if ($request->filled('product_id')) {
            $query->whereHas(
                'items',
                fn ($q) => $q->where('product_id', $request->integer('product_id'))
            );
        } else {
            $product = $request->input('insumo') ?? $request->input('product');

            if ($product !== null && $product !== '') {
                $like = '%'.$product.'%';
                $query->whereHas('items', function ($q) use ($like) {
                    $q->where('name', 'ilike', $like)
                        ->orWhereHas('product', fn ($qq) => $qq->where('name', 'ilike', $like));
                });
            }
        }

        if ($request->filled('search')) {
            $like = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $like)
                ->orWhere('subject', 'ilike', $like));
        }

        return $query;
    }
}
