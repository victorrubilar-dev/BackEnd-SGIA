<?php

namespace App\Services;

use App\Exceptions\InvalidEquipmentUnitStatusTransition;
use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\Location;
use App\Models\Product;
use App\Models\Storeroom;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EquipmentUnitService
{
    public function __construct(
        private readonly StockAlertService $stockAlerts,
        private readonly CodeSequenceService $codes,
    ) {}

    /**
     * Recalcula `quantity`, `quantity_in_repair` y `quantity_written_off` desde las unidades
     * (las `prestado` no suman) y evalúa las alertas de stock. Es la única vía que escribe
     * esos contadores en un producto con unidades.
     */
    public function syncProductCounters(Product $product): Product
    {
        return DB::transaction(function () use ($product) {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            $counts = EquipmentUnit::where('product_id', $locked->id)
                ->selectRaw('status, COUNT(*) as total')
                ->groupBy('status')
                ->pluck('total', 'status');

            $locked->forceFill([
                'quantity' => (int) ($counts[EquipmentUnit::STATUS_AVAILABLE] ?? 0),
                'quantity_in_repair' => (int) ($counts[EquipmentUnit::STATUS_IN_REPAIR] ?? 0),
                'quantity_written_off' => (int) ($counts[EquipmentUnit::STATUS_WRITTEN_OFF] ?? 0),
            ])->save();

            $this->stockAlerts->checkStock($locked);

            // Mantiene sincronizada la instancia del llamador.
            $product->setRawAttributes($locked->getAttributes(), true);

            return $product;
        });
    }

    /**
     * Crea `$count` unidades `disponible`. Un `code` manual solo se acepta en el alta individual.
     *
     * @param  array{cajon_id: int, code?: string|null, serial_number?: string|null}  $attributes
     * @param  array<int, string|null>  $serialNumbers  series por posición (alta masiva)
     * @return Collection<int, EquipmentUnit>
     */
    public function createUnits(Product $product, int $count, array $attributes, ?int $userId, array $serialNumbers = []): Collection
    {
        if (! $product->hasUnits()) {
            throw ValidationException::withMessages([
                'product_id' => 'Los insumos no tienen unidades; solo equipos y maquetas.',
            ]);
        }

        return DB::transaction(function () use ($product, $count, $attributes, $userId, $serialNumbers) {
            $units = collect();

            for ($i = 0; $i < $count; $i++) {
                $manualCode = $count === 1 ? ($attributes['code'] ?? null) : null;

                $units->push(EquipmentUnit::create([
                    'product_id' => $product->id,
                    'cajon_id' => $attributes['cajon_id'],
                    'code' => $manualCode ?: $this->codes->forProduct($product),
                    'serial_number' => $count === 1
                        ? ($attributes['serial_number'] ?? ($serialNumbers[0] ?? null))
                        : ($serialNumbers[$i] ?? null),
                    'status' => EquipmentUnit::STATUS_AVAILABLE,
                    'created_by' => $userId,
                    'updated_by' => $userId,
                ]));
            }

            $this->syncProductCounters($product);

            return $units;
        });
    }

    /**
     * Cambia el estado validando la máquina de estados. `prestado` solo cambia por préstamos.
     *
     * @throws InvalidEquipmentUnitStatusTransition|ValidationException
     */
    public function changeStatus(EquipmentUnit $unit, string $to, ?int $userId, bool $fromLoan = false): EquipmentUnit
    {
        return DB::transaction(function () use ($unit, $to, $userId, $fromLoan) {
            $locked = EquipmentUnit::whereKey($unit->id)->lockForUpdate()->firstOrFail();
            $from = $locked->status;

            if (! $fromLoan && ($from === EquipmentUnit::STATUS_ON_LOAN || $to === EquipmentUnit::STATUS_ON_LOAN)) {
                throw ValidationException::withMessages([
                    'status' => 'El estado prestado solo se cambia mediante préstamos.',
                ]);
            }

            if (! EquipmentUnitStateMachine::canTransition($from, $to)) {
                throw new InvalidEquipmentUnitStatusTransition($from, $to);
            }

            $locked->forceFill(['status' => $to, 'updated_by' => $userId])->save();
            $this->syncProductCounters(Product::findOrFail($locked->product_id));

            $unit->setRawAttributes($locked->getAttributes(), true);

            return $unit;
        });
    }

    /**
     * Genera las unidades de un producto que pasa a gestionarse por unidades
     * (insumo -> equipo/maqueta) a partir de sus contadores.
     */
    public function createUnitsFromCounters(Product $product, ?int $userId): void
    {
        $plan = [
            EquipmentUnit::STATUS_AVAILABLE => (int) $product->quantity,
            EquipmentUnit::STATUS_IN_REPAIR => (int) $product->quantity_in_repair,
            EquipmentUnit::STATUS_WRITTEN_OFF => (int) $product->quantity_written_off,
        ];
        $total = array_sum($plan);
        if ($total === 0) {
            return;
        }

        $cajonId = $product->cajon_id ?? $this->unassignedCajon()->id;

        DB::transaction(function () use ($product, $plan, $cajonId, $userId) {
            foreach ($plan as $status => $count) {
                for ($i = 0; $i < $count; $i++) {
                    EquipmentUnit::create([
                        'product_id' => $product->id,
                        'cajon_id' => $cajonId,
                        'code' => $this->codes->forProduct($product),
                        'status' => $status,
                        'created_by' => $userId,
                        'updated_by' => $userId,
                    ]);
                }
            }
            $this->syncProductCounters($product);
        });
    }

    /**
     * Cajón "SIN-ASIGNAR" de la ubicación "Sin asignar" del pañol por defecto.
     */
    public function unassignedCajon(): Cajon
    {
        $location = Location::firstOrCreate(
            ['nombre' => 'Sin asignar'],
            ['sala' => 'Sin asignar', 'tipo' => Location::TIPO_BODEGA, 'storeroom_id' => Storeroom::default()->id]
        );

        return Cajon::firstOrCreate(['location_id' => $location->id, 'codigo' => 'SIN-ASIGNAR']);
    }

    /**
     * Una unidad con historial (préstamos, mantenciones, informes) o prestada no se borra.
     */
    public function hasHistory(EquipmentUnit $unit): bool
    {
        return $unit->status === EquipmentUnit::STATUS_ON_LOAN
            || $unit->loanItems()->exists()
            || $unit->maintenanceRecords()->exists()
            || $unit->incidentReports()->exists();
    }

    /**
     * Filtros del listado: producto, estado, ubicación, búsqueda y mantención vencida.
     */
    public function applyFilters(Builder $query, Request $request): Builder
    {
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->integer('product_id'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('cajon_id')) {
            $query->where('cajon_id', $request->integer('cajon_id'));
        }

        if ($request->filled('location_id')) {
            $query->whereHas('cajon', fn ($q) => $q->where('location_id', $request->integer('location_id')));
        }

        if ($request->filled('storeroom_id')) {
            $query->whereHas('cajon.location', fn ($q) => $q->where('storeroom_id', $request->integer('storeroom_id')));
        }

        if ($request->filled('search')) {
            $search = '%'.$request->string('search').'%';
            $query->where(fn ($q) => $q->where('code', 'ilike', $search)->orWhere('serial_number', 'ilike', $search));
        }

        if ($request->boolean('maintenance_due')) {
            $query->whereNotNull('next_maintenance_at')->where('next_maintenance_at', '<=', now()->endOfDay());
        }

        return $query;
    }
}
