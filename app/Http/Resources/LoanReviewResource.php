<?php

namespace App\Http\Resources;

use App\Models\EquipmentUnit;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Product;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Revisión de una solicitud remota antes de aprobarla (`docs/revision-solicitud-prestamo.md`).
 * Espera el préstamo preparado por `LoanService::review()`.
 *
 * @mixin Loan
 */
class LoanReviewResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $items = $this->items->whereInstanceOf(LoanItem::class)->map(fn (LoanItem $item) => $this->reviewItem($item, $request))->values();

        return [
            'loan_id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'storeroom' => $this->storeroom->only(['id', 'name']),
            'can_approve' => $items->every(fn (array $item) => $item['shortage'] === 0),
            'items' => $items,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewItem(LoanItem $item, Request $request): array
    {
        $product = $item->product;
        $hasUnits = $product instanceof Product && $product->hasUnits();
        $quantity = (int) $item->quantity;
        $available = $hasUnits ? $item->getRelation('availableUnits') : collect();
        $availableQuantity = $hasUnits ? $available->count() : (int) ($product->quantity ?? 0);
        // La misma forma de ubicación de producto que entrega LoanItemResource.
        $place = (new LoanItemResource($item))->toArray($request);

        return [
            'item_id' => $item->id,
            'product_id' => $item->product_id,
            'name' => $item->name,
            'quantity' => $quantity,
            'has_units' => $hasUnits,
            'available_quantity' => $availableQuantity,
            'shortage' => max(0, $quantity - $availableQuantity),
            'location' => $hasUnits ? null : $place['location'],
            'cajon' => $hasUnits ? null : $place['cajon'],
            // `$available` viene por id: las mismas que tomaría la asignación automática.
            'suggested_units' => $available->take($quantity)->map(fn (EquipmentUnit $unit) => $this->unit($unit))->values(),
            'available_units' => $available
                ->sortBy([
                    fn (EquipmentUnit $a, EquipmentUnit $b) => strnatcasecmp((string) ($a->cajon->location->nombre ?? ''), (string) ($b->cajon->location->nombre ?? '')),
                    fn (EquipmentUnit $a, EquipmentUnit $b) => strnatcasecmp((string) $a->cajon->codigo, (string) $b->cajon->codigo),
                    fn (EquipmentUnit $a, EquipmentUnit $b) => strnatcasecmp((string) $a->code, (string) $b->code),
                ])
                ->map(fn (EquipmentUnit $unit) => $this->unit($unit))
                ->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unit(EquipmentUnit $unit): array
    {
        $cajon = $unit->cajon;
        $location = $cajon->location;

        return [
            'id' => $unit->id,
            'code' => $unit->code,
            'serial_number' => $unit->serial_number,
            'status' => $unit->status,
            'cajon' => ['id' => $cajon->id, 'codigo' => $cajon->codigo, 'descripcion' => $cajon->descripcion],
            'location' => [
                'id' => $location->id,
                'nombre' => $location->nombre ?? $location->sala,
                'tipo' => $location->tipo ?? 'sala',
                'storeroom_id' => $location->storeroom_id,
            ],
        ];
    }
}
