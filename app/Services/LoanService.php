<?php

namespace App\Services;

use App\Exceptions\InvalidLoanStatusTransition;
use App\Models\Equipo;
use App\Models\Estante;
use App\Models\Panol;
use App\Models\Prestamo;
use App\Models\PrestamoDetalle;
use App\Models\Producto;
use App\Models\Sala;
use App\Models\Usuario;
use App\Notifications\LoanStatusNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoanService
{
    public function __construct(private readonly StockAlertService $stockAlertService)
    {
    }

    /**
     * Crea la solicitud remota de préstamo del docente en estado `pendiente`.
     */
    public function createRequest(array $validated, Usuario $creator): Prestamo
    {
        $items = $validated['items'];

        $this->assertItemsAvailable($items);

        return DB::transaction(function () use ($validated, $creator, $items) {
            $panolId = $creator->panol_id ?? Panol::first()?->id ?? 1;

            $meta = [
                'subject' => $validated['subject'] ?? null,
                'room' => $validated['room'] ?? null,
                'time_block' => $validated['time_block'] ?? null,
                'borrower_name' => $creator->nombre,
                'notes' => $validated['notes'] ?? null,
            ];

            $loan = Prestamo::create([
                'panol_id' => $panolId,
                'tipo_prestamo' => Prestamo::TYPE_REMOTE,
                'profesor_id' => $creator->id,
                'estado' => LoanStateMachine::STATUS_PENDING,
                'fecha_solicitud' => now(),
                'fecha_hora_prestamo' => isset($validated['loan_date']) ? date('Y-m-d H:i:s', strtotime($validated['loan_date'])) : now(),
                'observaciones' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);

            $this->attachItems($loan, $items);

            return $loan;
        });
    }

    /**
     * Aprueba la solicitud remota: descuenta el stock de cada producto,
     * deja el préstamo `en_proceso` y notifica al solicitante (REQ-10).
     */
    public function approve(Prestamo $loan, Usuario $approver): Prestamo
    {
        $loan = DB::transaction(function () use ($loan, $approver) {
            $loan->loadMissing('items.equipo.producto');

            if (! LoanStateMachine::canTransition($loan->status, LoanStateMachine::STATUS_IN_PROGRESS)) {
                throw new InvalidLoanStatusTransition($loan->status, LoanStateMachine::STATUS_IN_PROGRESS);
            }

            $this->deductStock($loan);

            $loan->estado = LoanStateMachine::STATUS_IN_PROGRESS;
            $loan->despachado_por_id = $approver->id;
            $loan->fecha_hora_prestamo = now();
            $loan->save();

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
     */
    public function reject(Prestamo $loan, Usuario $rejector, string $reason): Prestamo
    {
        if (! LoanStateMachine::canTransition($loan->status, LoanStateMachine::STATUS_REJECTED)) {
            throw new InvalidLoanStatusTransition($loan->status, LoanStateMachine::STATUS_REJECTED);
        }

        $loan->setMetadataValue('rejection_reason', $reason);
        $loan->estado = LoanStateMachine::STATUS_REJECTED;
        $loan->save();

        $this->notifyRequester($loan, "Tu solicitud de préstamo {$loan->code} fue rechazada. Motivo: {$reason}");

        return $loan;
    }

    /**
     * Confirma la entrega material al docente: `en_proceso` -> `procesado`.
     */
    public function deliver(Prestamo $loan, Usuario $processor): Prestamo
    {
        if (! LoanStateMachine::canTransition($loan->status, LoanStateMachine::STATUS_PROCESSED)) {
            throw new InvalidLoanStatusTransition($loan->status, LoanStateMachine::STATUS_PROCESSED);
        }

        $loan->estado = LoanStateMachine::STATUS_PROCESSED;
        $loan->recibido_por_id = $processor->id;
        $loan->fecha_hora_devolucion_real = null;
        $loan->save();

        $this->notifyRequester(
            $loan,
            "Tu préstamo {$loan->code} fue entregado: {$loan->room} el {$loan->loan_date?->toDateString()}."
        );

        return $loan;
    }

    /**
     * Registra un préstamo presencial directo en el pañol: crea el préstamo
     * `procesado` y descuenta el stock atómicamente (REQ-10).
     */
    public function checkout(array $validated, Usuario $operator): Prestamo
    {
        $items = $validated['items'];

        $this->assertItemsAvailable($items);

        return DB::transaction(function () use ($validated, $operator, $items) {
            $panolId = $operator->panol_id ?? Panol::first()?->id ?? 1;

            $meta = [
                'subject' => $validated['subject'] ?? null,
                'room' => $validated['room'] ?? null,
                'time_block' => $validated['time_block'] ?? null,
                'borrower_name' => $validated['borrower_name'],
                'borrower_document' => $validated['borrower_document'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ];

            $loan = Prestamo::create([
                'panol_id' => $panolId,
                'tipo_prestamo' => Prestamo::TYPE_DIRECT,
                'despachado_por_id' => $operator->id,
                'recibido_por_id' => $operator->id,
                'estado' => LoanStateMachine::STATUS_PROCESSED,
                'fecha_solicitud' => now(),
                'fecha_hora_prestamo' => now(),
                'observaciones' => json_encode($meta, JSON_UNESCAPED_UNICODE),
            ]);

            $this->attachItems($loan, $items);
            $loan->load('items.equipo.producto');
            $this->deductStock($loan);

            return $loan;
        });
    }

    /**
     * Transiciona el préstamo a otro estado respetando la máquina de estados.
     */
    public function transition(Prestamo $loan, string $toStatus, array $attributes = []): Prestamo
    {
        if (! LoanStateMachine::canTransition($loan->status, $toStatus)) {
            throw new InvalidLoanStatusTransition($loan->status, $toStatus);
        }

        $attributes['estado'] = $toStatus;
        $loan->forceFill($attributes)->save();

        return $loan;
    }

    /**
     * Descuenta el stock de los productos del préstamo dentro de la transacción.
     */
    private function deductStock(Prestamo $loan): void
    {
        foreach ($loan->items as $item) {
            $product = $item->equipo?->producto ?? Producto::find($item->product_id);

            if ($product === null) {
                continue;
            }

            $product = Producto::whereKey($product->id)->lockForUpdate()->first();

            if ($product === null) {
                throw ValidationException::withMessages([
                    'items' => "El producto ya no existe en el inventario.",
                ]);
            }

            if ($product->stock_actual < $item->quantity) {
                throw ValidationException::withMessages([
                    'items' => "Stock insuficiente para \"{$product->nombre}\": "
                        . "se requieren {$item->quantity} y hay {$product->stock_actual} disponibles.",
                ]);
            }

            $product->stock_actual -= $item->quantity;
            $product->save();

            $this->stockAlertService->checkStock($product);
        }
    }

    /**
     * Notifica al docente solicitante, si tiene usuario en el sistema.
     */
    private function notifyRequester(Prestamo $loan, string $message): void
    {
        $requester = $loan->profesor_id !== null
            ? Usuario::find($loan->profesor_id)
            : null;

        if ($requester !== null && $requester->activo) {
            Notification::send($requester, new LoanStatusNotification($loan, $message));
        }
    }

    /**
     * Valida que cada producto exista y tenga stock suficiente.
     */
    public function assertItemsAvailable(array $items): void
    {
        foreach ($items as $index => $item) {
            $product = Producto::find($item['product_id'] ?? 0);

            if ($product === null) {
                throw ValidationException::withMessages([
                    "items.{$index}.product_id" => 'El producto solicitado no existe en el inventario.',
                ]);
            }

            $requested = (int) $item['quantity'];

            if ($product->stock_actual < $requested) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => "Stock insuficiente para \"{$product->nombre}\": "
                        . "se solicitaron {$requested} y hay {$product->stock_actual} disponibles.",
                ]);
            }
        }
    }

    /**
     * Crea los ítems del préstamo tomando el equipo o producto.
     */
    public function attachItems(Prestamo $loan, array $items): Prestamo
    {
        $defaultEstante = Estante::first() ?? Estante::create([
            'sala_id' => Sala::first()?->id ?? 1,
            'nombre' => 'Estante General',
            'codigo_barras' => 'EST-GEN-01',
        ]);

        foreach ($items as $item) {
            $product = Producto::find($item['product_id']);

            if (! $product) {
                continue;
            }

            $equipo = Equipo::firstOrCreate(
                ['producto_id' => $product->id],
                [
                    'estante_id' => $defaultEstante->id,
                    'codigo_barras' => $product->sku . '-EQ01',
                    'estado' => 'DISPONIBLE',
                ]
            );

            PrestamoDetalle::create([
                'prestamo_id' => $loan->id,
                'equipo_id' => $equipo->id,
                'cantidad_solicitada' => (int) $item['quantity'],
                'cantidad_entregada' => $loan->status === LoanStateMachine::STATUS_PROCESSED ? (int) $item['quantity'] : 0,
            ]);
        }

        return $loan;
    }
}
