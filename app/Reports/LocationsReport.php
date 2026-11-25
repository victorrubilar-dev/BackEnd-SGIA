<?php

namespace App\Reports;

use App\Models\Cajon;
use App\Models\EquipmentUnit;
use App\Models\Location;
use App\Models\Product;
use App\Support\XlsxWriter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Ubicaciones (`/ubicaciones`, `/mapa-salas/:id`): dónde está cada unidad e insumo, los cajones y las salas
 * (docs/reportes-excel.md, ampliación A1). Sin filtro de sala, suma al final lo que no tiene ubicación.
 */
final class LocationsReport implements Report
{
    private const NO_LOCATION = 'Sin ubicación';

    private const TIPOS = [
        Location::TIPO_SALA => 'Sala',
        Location::TIPO_PANOL => 'Pañol',
        Location::TIPO_TALLER => 'Taller',
        Location::TIPO_BODEGA => 'Bodega',
    ];

    /** @param  Builder<Location>  $locations  Salas ya filtradas por el controlador. */
    public function __construct(private readonly Request $request, private readonly Builder $locations) {}

    public function title(): string
    {
        return 'Ubicaciones';
    }

    public function filename(): string
    {
        return 'ubicaciones';
    }

    public function audited(): bool
    {
        return false;
    }

    public function filters(): array
    {
        $values = $this->request->query();
        if (! empty($values['tipo'])) {
            $values['tipo'] = self::TIPOS[$values['tipo']] ?? $values['tipo'];
        }

        return ReportFilters::describe($values, [
            'location_id' => 'Sala',
            'search' => 'Búsqueda',
            'tipo' => 'Tipo',
            'storeroom_id' => 'Pañol',
        ]);
    }

    /** Sin acotar a salas, el reporte también lista los insumos sin cajón ni sala. */
    private function includesUnlocated(): bool
    {
        return $this->filters() === [];
    }

    public function sheets(): array
    {
        $locationIds = (clone $this->locations)->select('locations.id');

        $units = EquipmentUnit::query()
            ->with(['product', 'cajon.location.storeroom', 'cajon.estante.zona'])
            // Toda unidad tiene cajón (`equipment_units.cajon_id` es obligatorio).
            ->whereHas('cajon', fn (Builder $c) => $c->whereIn('location_id', $locationIds))
            ->get();

        $supplies = Product::query()
            ->whereNotIn('type', [Product::TYPE_EQUIPMENT, Product::TYPE_SCALE_MODEL])
            ->with(['cajon.location.storeroom', 'cajon.estante.zona', 'location.storeroom'])
            ->where(fn (Builder $q) => $q->whereHas('cajon', fn (Builder $c) => $c->whereIn('location_id', $locationIds))
                ->orWhere(fn (Builder $q) => $q->whereNull('cajon_id')->whereIn('location_id', $locationIds))
                ->when($this->includesUnlocated(), fn (Builder $q) => $q->orWhere(fn (Builder $q) => $q->whereNull('cajon_id')->whereNull('location_id'))))
            ->get();

        $items = [];
        $perLocation = [];
        foreach ($units as $unit) {
            $items[] = $this->itemRow($unit->cajon, null, ReportValues::label($unit->product?->type), $unit->code, $unit->product?->name, $unit->serial_number, ReportValues::label($unit->status), 1);
            $this->count($perLocation, $unit->cajon?->location_id, 'units', 1);
        }
        foreach ($supplies as $product) {
            $items[] = $this->itemRow($product->cajon, $product->location, ReportValues::label($product->type), $product->barcode, $product->name, null, $product->quantity <= $product->stock_minimo ? 'Stock crítico' : null, $product->quantity);
            $this->count($perLocation, $product->cajon->location_id ?? $product->location_id, 'supplies', 1);
        }
        // Por sala, estante, cajón y producto; en cada sala, lo que está suelto (sin cajón) al final, y lo que no tiene ubicación, al final de todo.
        $key = fn (array $r): array => [$r[1] === self::NO_LOCATION, $r[1], $r[5] === null, $r[4] ?? '', $r[5] ?? '', $r[10] ?? '', $r[9] ?? ''];
        usort($items, fn (array $a, array $b) => $key($a) <=> $key($b));

        $cajones = Cajon::query()
            ->whereIn('location_id', (clone $this->locations)->select('locations.id'))
            ->with(['location.storeroom', 'estante.zona'])
            ->withCount(['products', 'equipmentUnits'])
            ->get()
            ->sortBy(fn (Cajon $c) => [self::room($c->location), $c->estante->codigo ?? '', $c->codigo])
            ->values();

        $salas = (clone $this->locations)->with('storeroom')->withCount(['cajones', 'estantes', 'cajones as cajones_llenos_count' => fn (Builder $q) => $q->where('lleno', true)])
            ->orderBy('code')->orderBy('nombre')->get();

        return [
            new ReportSheet(
                'Dónde está cada cosa',
                ['Pañol', 'Sala', 'Tipo de sala', 'Zona', 'Estante', 'Cajón', 'Código del cajón', 'Cajón lleno', 'Tipo', 'Código', 'Producto', 'N° de serie', 'Estado', 'Cantidad'],
                $items,
                fn (array $row): array => $row,
                [13 => XlsxWriter::INTEGER],
                [20, 30, 12, 16, 16, 14, 20, 11, 10, 18, 36, 18, 14, 10],
            ),
            new ReportSheet(
                'Cajones',
                ['Pañol', 'Sala', 'Zona', 'Estante', 'Cajón', 'Código del cajón', 'Descripción', 'Lleno', 'Insumos', 'Unidades'],
                $cajones,
                fn (Cajon $c): array => [
                    $c->location?->storeroom?->name,
                    self::room($c->location),
                    $c->estante?->zona?->nombre,
                    self::estante($c),
                    $c->codigo,
                    $c->barcode,
                    $c->descripcion,
                    ReportValues::yesNo($c->lleno),
                    $c->products_count,
                    $c->equipment_units_count,
                ],
                [8 => XlsxWriter::INTEGER, 9 => XlsxWriter::INTEGER],
                [20, 30, 16, 16, 14, 20, 34, 7, 9, 10],
            ),
            new ReportSheet(
                'Salas',
                ['Código', 'Nombre', 'Tipo', 'Pañol', 'Edificio', 'Piso', 'Estantes', 'Cajones', 'Cajones llenos', 'Insumos', 'Unidades'],
                $salas,
                fn (Location $l): array => [
                    $l->code,
                    $l->nombre ?? $l->sala,
                    self::TIPOS[$l->tipo] ?? $l->tipo,
                    $l->storeroom?->name,
                    $l->building,
                    $l->floor,
                    $l->estantes_count,
                    $l->cajones_count,
                    $l->getAttribute('cajones_llenos_count'),
                    $perLocation[$l->id]['supplies'] ?? 0,
                    $perLocation[$l->id]['units'] ?? 0,
                ],
                [6 => XlsxWriter::INTEGER, 7 => XlsxWriter::INTEGER, 8 => XlsxWriter::INTEGER, 9 => XlsxWriter::INTEGER, 10 => XlsxWriter::INTEGER],
                [12, 30, 9, 20, 14, 7, 9, 9, 14, 9, 10],
            ),
        ];
    }

    /**
     * Fila de «Dónde está cada cosa»: la ubicación sale del cajón o, en un insumo sin cajón, de la sala directa.
     *
     * @return list<string|int|null>
     */
    private function itemRow(?Cajon $cajon, ?Location $location, ?string $type, ?string $code, ?string $name, ?string $serial, ?string $status, ?int $quantity): array
    {
        $room = $cajon !== null ? $cajon->location : $location;

        return [
            $room?->storeroom?->name,
            $room ? self::room($room) : self::NO_LOCATION,
            $room ? (self::TIPOS[$room->tipo] ?? $room->tipo) : null,
            $cajon?->estante?->zona?->nombre,
            $cajon ? self::estante($cajon) : null,
            $cajon?->codigo,
            $cajon?->barcode,
            $cajon ? ReportValues::yesNo($cajon->lleno) : null,
            $type,
            $code,
            $name,
            $serial,
            $status,
            $quantity,
        ];
    }

    /** @param  array<int|string, array<string, int>>  $perLocation */
    private function count(array &$perLocation, ?int $locationId, string $key, int $amount): void
    {
        if ($locationId !== null) {
            $perLocation[$locationId][$key] = ($perLocation[$locationId][$key] ?? 0) + $amount;
        }
    }

    private static function room(?Location $location): string
    {
        return ReportValues::room(null, $location) ?? self::NO_LOCATION;
    }

    private static function estante(Cajon $cajon): ?string
    {
        $estante = $cajon->estante;

        return $estante ? trim($estante->codigo.($estante->nombre ? ' '.$estante->nombre : '')) : null;
    }
}
