<?php

namespace App\Services;

use App\Exceptions\InvalidLoanStatusTransition;
use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use App\Models\Storeroom;
use App\Models\Student;
use App\Models\User;
use App\Notifications\LoanStatusNotification;
use App\Rules\ChileanRut;
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
    public function __construct(
        private readonly StockAlertService $stockAlertService,
        private readonly EquipmentUnitService $equipmentUnits,
    ) {}

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
     * `$assignments` (`[['item_id' => 1, 'unit_ids' => [..]]]`) reemplaza la asignación
     * automática por las unidades realmente entregadas; las anteriores vuelven a `disponible`.
     *
     * @param  array<int, array{item_id: int, unit_ids: array<int, int>}>  $assignments
     *
     * @throws InvalidLoanStatusTransition|ValidationException
     */
    public function deliver(Loan $loan, User $processor, array $assignments = []): Loan
    {
        DB::transaction(function () use ($loan, $processor, $assignments) {
            if (! LoanStateMachine::canTransition($loan->status, LoanStateMachine::STATUS_PROCESSED)) {
                throw new InvalidLoanStatusTransition($loan->status, LoanStateMachine::STATUS_PROCESSED);
            }

            $loan->load('items.product');

            foreach ($assignments as $index => $assignment) {
                $item = $loan->items->firstWhere('id', (int) $assignment['item_id']);

                if ($item === null || ! $item->product?->hasUnits()) {
                    throw ValidationException::withMessages([
                        "items.{$index}.item_id" => 'El ítem no pertenece a este préstamo o no se gestiona por unidades.',
                    ]);
                }

                $this->replaceUnits($item, array_map('intval', $assignment['unit_ids']), $index);
            }

            $loan->items()->update(['delivered_quantity' => DB::raw('quantity')]);

            $this->transition($loan, LoanStateMachine::STATUS_PROCESSED, [
                'processed_by' => $processor->id,
                'processed_at' => now(),
            ]);
        });

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
                'storeroom_id' => $validated['storeroom_id'] ?? $operator->storeroom_id ?? Storeroom::default()->id,
                'student_id' => $this->resolveStudentId($validated),
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

            // Las unidades pedidas se asocian a cada ítem por posición (los ítems se crean en orden).
            $requested = [];
            foreach ($loan->items->sortBy('id')->values() as $index => $item) {
                $requested[$item->id] = $items[$index]['unit_ids'] ?? null;
            }

            $this->deductStock($loan, $requested, delivered: true);

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
     * Descuenta el stock de los productos del préstamo dentro de la transacción.
     *
     * Insumos: bloquea el producto y resta la cantidad. Equipos y maquetas: asigna las
     * unidades indicadas en `$requestedUnits[item_id]` o, si no vienen, unidades `disponible`
     * automáticas (se bloquean primero las unidades y luego el producto). Evalúa las alertas (REQ-06).
     *
     * @param  array<int, array<int, int>|null>  $requestedUnits  unit_ids por id de ítem
     *
     * @throws ValidationException
     */
    private function deductStock(Loan $loan, array $requestedUnits = [], bool $delivered = false): void
    {
        foreach ($loan->items as $item) {
            if ($item->product_id === null) {
                continue;
            }

            $peek = Product::find($item->product_id);

            if ($peek === null) {
                throw ValidationException::withMessages([
                    'items' => "El producto \"{$item->name}\" ya no existe en el inventario.",
                ]);
            }

            if ($peek->hasUnits()) {
                $this->assignUnits($item, $peek, $requestedUnits[$item->id] ?? null, $delivered);

                continue;
            }

            $product = Product::whereKey($item->product_id)->lockForUpdate()->first();

            if ($product->quantity < $item->quantity) {
                throw ValidationException::withMessages([
                    'items' => "Stock insuficiente para \"{$product->name}\": "
                        ."se requieren {$item->quantity} y hay {$product->quantity} disponibles.",
                ]);
            }

            $product->quantity -= $item->quantity;
            $product->save();

            $item->forceFill(['delivered_quantity' => $delivered ? $item->quantity : 0])->save();

            $this->stockAlertService->checkStock($product);
        }
    }

    /**
     * Pasa a `prestado` las unidades del ítem y las vincula en `loan_item_units`.
     *
     * @param  array<int, int>|null  $unitIds
     *
     * @throws ValidationException
     */
    private function assignUnits(LoanItem $item, Product $product, ?array $unitIds, bool $delivered): void
    {
        $available = EquipmentUnit::where('product_id', $product->id)
            ->where('status', EquipmentUnit::STATUS_AVAILABLE)
            ->orderBy('id');

        if ($unitIds) {
            $unitIds = array_values(array_unique(array_map('intval', $unitIds)));

            if (count($unitIds) !== (int) $item->quantity) {
                throw ValidationException::withMessages([
                    'items' => "Debes indicar exactamente {$item->quantity} unidad(es) para \"{$product->name}\".",
                ]);
            }

            $units = $available->whereIn('id', $unitIds)->lockForUpdate()->get();

            if ($units->count() !== count($unitIds)) {
                throw ValidationException::withMessages([
                    'items' => "Alguna de las unidades indicadas para \"{$product->name}\" no existe, no pertenece al producto o no está disponible.",
                ]);
            }
        } else {
            $units = $available->limit($item->quantity)->lockForUpdate()->get();

            if ($units->count() < $item->quantity) {
                throw ValidationException::withMessages([
                    'items' => "Stock insuficiente para \"{$product->name}\": "
                        ."se requieren {$item->quantity} y hay {$units->count()} disponibles.",
                ]);
            }
        }

        EquipmentUnit::whereIn('id', $units->pluck('id'))->update(['status' => EquipmentUnit::STATUS_ON_LOAN]);
        $item->units()->attach($units->pluck('id')->all());
        $item->forceFill(['delivered_quantity' => $delivered ? $item->quantity : 0])->save();

        $this->equipmentUnits->syncProductCounters($product);
    }

    /**
     * Sustituye las unidades asignadas a un ítem por las realmente entregadas.
     *
     * @param  array<int, int>  $unitIds
     *
     * @throws ValidationException
     */
    private function replaceUnits(LoanItem $item, array $unitIds, int $index): void
    {
        $unitIds = array_values(array_unique($unitIds));

        if (count($unitIds) !== (int) $item->quantity) {
            throw ValidationException::withMessages([
                "items.{$index}.unit_ids" => "Debes indicar exactamente {$item->quantity} unidad(es) para \"{$item->name}\".",
            ]);
        }

        $current = $item->units()->pluck('equipment_units.id')->all();
        $toTake = array_values(array_diff($unitIds, $current));
        $toRelease = array_values(array_diff($current, $unitIds));

        if ($toTake !== []) {
            $units = EquipmentUnit::whereIn('id', $toTake)
                ->where('product_id', $item->product_id)
                ->where('status', EquipmentUnit::STATUS_AVAILABLE)
                ->lockForUpdate()
                ->get();

            if ($units->count() !== count($toTake)) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_ids" => 'Alguna de las unidades indicadas no existe, no pertenece al producto o no está disponible.',
                ]);
            }
        }

        if ($toRelease !== []) {
            EquipmentUnit::whereIn('id', $toRelease)->update(['status' => EquipmentUnit::STATUS_AVAILABLE]);
            $item->units()->detach($toRelease);
        }

        if ($toTake !== []) {
            EquipmentUnit::whereIn('id', $toTake)->update(['status' => EquipmentUnit::STATUS_ON_LOAN]);
            $item->units()->attach($toTake);
        }

        $this->equipmentUnits->syncProductCounters(Product::findOrFail($item->product_id));
    }

    /**
     * Si el documento del solicitante es un RUT válido, crea o reutiliza el estudiante.
     *
     * @param  array<string, mixed>  $validated
     */
    private function resolveStudentId(array $validated): ?int
    {
        $document = $validated['borrower_document'] ?? null;

        if (! is_string($document) || ! ChileanRut::isValid($document)) {
            return null;
        }

        return Student::firstOrCreate(
            ['rut' => ChileanRut::normalize($document)],
            ['full_name' => $validated['borrower_name'] ?? null]
        )->id;
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

            if (! empty($item['unit_ids']) && ! $product->hasUnits()) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_ids" => "El producto \"{$product->name}\" es un insumo y no tiene unidades.",
                ]);
            }

            if (! empty($item['unit_ids']) && count(array_unique($item['unit_ids'])) !== $requested) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_ids" => "Debes indicar exactamente {$requested} unidad(es) para \"{$product->name}\".",
                ]);
            }

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
            $loan->load('items.product');

            if (! $loan->isProcessed()) {
                throw ValidationException::withMessages([
                    'loan_id' => 'Solo se pueden devolver préstamos entregados (estado procesado).',
                ]);
            }

            $seen = [];
            $seenUnits = [];
            $touchedProducts = [];

            foreach ($validated['items'] as $index => $row) {
                $item = isset($row['item_id'])
                    ? $loan->items->firstWhere('id', (int) $row['item_id'])
                    : $loan->items->firstWhere('product_id', (int) $row['product_id']);

                if ($item === null) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => 'El ítem no pertenece a este préstamo.',
                    ]);
                }

                $condition = $this->normalizeCondition($row['condition'], (bool) ($row['damaged'] ?? false));

                if ($item->product?->hasUnits()) {
                    $this->checkinUnits($item, $row['unit_ids'] ?? null, $condition, $operator, $index, $seenUnits);
                    $touchedProducts[$item->product_id] = $item->product;

                    continue;
                }

                if ($item->returned_at !== null || in_array($item->id, $seen, true)) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => "El ítem \"{$item->name}\" ya fue devuelto.",
                    ]);
                }

                $seen[] = $item->id;

                $this->restoreItem($item, $condition);

                $item->forceFill([
                    'returned_at' => now(),
                    'return_condition' => $condition,
                    'returned_quantity' => $item->quantity,
                ])->save();
            }

            foreach ($touchedProducts as $product) {
                $this->equipmentUnits->syncProductCounters($product);
            }

            $attributes = [];

            if (! empty($validated['general_notes'])) {
                $attributes['return_notes'] = $validated['general_notes'];
            }

            if ($loan->items()->whereNull('returned_at')->doesntExist()) {
                $attributes += ['returned_at' => now(), 'returned_by' => $operator->id];
            }

            $loan->forceFill($attributes)->save();

            return $loan->load('items.units');
        });
    }

    /**
     * Devuelve unidades de un ítem: las indicadas en `unit_ids` o todas las pendientes.
     * El ítem queda devuelto cuando todas sus unidades lo están (condición = la peor).
     *
     * @param  array<int, int>|null  $unitIds
     * @param  array<int, int>  $seenUnits  unidades ya devueltas en esta operación
     *
     * @throws ValidationException
     */
    private function checkinUnits(LoanItem $item, ?array $unitIds, string $condition, User $operator, int $index, array &$seenUnits): void
    {
        $pending = DB::table('loan_item_units')
            ->where('loan_item_id', $item->id)
            ->whereNull('returned_at')
            ->lockForUpdate()
            ->pluck('equipment_unit_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $ids = $unitIds !== null ? array_map('intval', $unitIds) : $pending;

        if ($ids === []) {
            throw ValidationException::withMessages([
                "items.{$index}" => "El ítem \"{$item->name}\" ya fue devuelto.",
            ]);
        }

        foreach ($ids as $id) {
            if (in_array($id, $seenUnits, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_ids" => 'Una unidad no puede devolverse dos veces en la misma operación.',
                ]);
            }

            if (! in_array($id, $pending, true)) {
                throw ValidationException::withMessages([
                    "items.{$index}.unit_ids" => "La unidad {$id} no está pendiente de devolución en el ítem \"{$item->name}\".",
                ]);
            }

            $seenUnits[] = $id;
        }

        $status = match ($condition) {
            LoanItem::CONDITION_DAMAGED => EquipmentUnit::STATUS_IN_REPAIR,
            LoanItem::CONDITION_LOST => EquipmentUnit::STATUS_WRITTEN_OFF,
            default => EquipmentUnit::STATUS_AVAILABLE,
        };

        EquipmentUnit::whereIn('id', $ids)->update(['status' => $status, 'updated_by' => $operator->id]);
        DB::table('loan_item_units')
            ->where('loan_item_id', $item->id)
            ->whereIn('equipment_unit_id', $ids)
            ->update(['returned_at' => now(), 'return_condition' => $condition, 'updated_at' => now()]);

        $returned = (int) $item->returned_quantity + count($ids);
        $attributes = ['returned_quantity' => $returned];

        if ($returned >= $item->quantity) {
            $attributes += ['returned_at' => now(), 'return_condition' => $this->worstCondition($item)];
        }

        $item->forceFill($attributes)->save();
    }

    /**
     * La peor condición entre las unidades devueltas: extraviado > danado > regular > bueno.
     */
    private function worstCondition(LoanItem $item): string
    {
        $order = [LoanItem::CONDITION_GOOD, LoanItem::CONDITION_REGULAR, LoanItem::CONDITION_DAMAGED, LoanItem::CONDITION_LOST];

        $conditions = DB::table('loan_item_units')
            ->where('loan_item_id', $item->id)
            ->whereNotNull('return_condition')
            ->pluck('return_condition')
            ->all();

        $worst = array_reduce(
            $conditions,
            fn (int $carry, string $c) => max($carry, (int) array_search($c, $order, true)),
            0
        );

        return $order[$worst];
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
