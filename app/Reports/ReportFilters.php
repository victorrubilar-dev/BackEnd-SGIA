<?php

namespace App\Reports;

use App\Models\AnnualBudget;
use App\Models\Cajon;
use App\Models\Career;
use App\Models\EquipmentUnit;
use App\Models\Location;
use App\Models\Product;
use App\Models\Storeroom;
use App\Models\Supplier;
use App\Models\User;
use Closure;

/**
 * Filtros de la solicitud en texto legible para la hoja «Info»: `career_id=3` → «Carrera: Ingeniería en Informática».
 */
final class ReportFilters
{
    /**
     * @param  array<string, mixed>  $values  Parámetros de la solicitud (`$request->query()`).
     * @param  array<string, string|array{0: string, 1: Closure(mixed): ?string}>  $labels  Parámetro → etiqueta, o [etiqueta, traductor del valor].
     * @return array<string, string>
     */
    public static function describe(array $values, array $labels): array
    {
        $described = [];
        foreach ($labels as $param => $label) {
            $value = $values[$param] ?? null;
            if ($value === null || $value === '' || $value === []) {
                continue;
            }
            [$text, $resolve] = is_array($label) ? $label : [$label, self::resolver($param)];
            $shown = $resolve ? $resolve($value) : null;
            $described[$text] = $shown ?? (is_array($value) ? implode(', ', $value) : (string) $value);
        }

        return $described;
    }

    /** «sí» / «no» para parámetros booleanos (`1`, `true`, `0`…). */
    public static function yesNo(mixed $value): string
    {
        return filter_var($value, FILTER_VALIDATE_BOOLEAN) ? 'sí' : 'no';
    }

    /** @return (Closure(mixed): ?string)|null */
    private static function resolver(string $param): ?Closure
    {
        $find = fn (string $model, Closure $label) => function (mixed $id) use ($model, $label): ?string {
            if (! is_numeric($id)) {
                return null;
            }
            $record = $model::query()->find((int) $id);

            return $record ? $label($record) : null;
        };

        return match ($param) {
            'career_id' => $find(Career::class, fn (Career $c) => $c->name),
            'storeroom_id' => $find(Storeroom::class, fn (Storeroom $s) => $s->name),
            'location_id' => $find(Location::class, fn (Location $l) => trim(($l->code ? $l->code.' ' : '').($l->nombre ?? $l->sala ?? ''))),
            'cajon_id' => $find(Cajon::class, fn (Cajon $c) => $c->codigo),
            'user_id' => $find(User::class, fn (User $u) => $u->name),
            'product_id' => $find(Product::class, fn (Product $p) => $p->name),
            'equipment_unit_id' => $find(EquipmentUnit::class, fn (EquipmentUnit $u) => $u->code),
            'supplier_id' => $find(Supplier::class, fn (Supplier $s) => $s->name),
            'annual_budget_id' => $find(AnnualBudget::class, fn (AnnualBudget $b) => trim($b->career->name.' '.$b->year)),
            default => null,
        };
    }
}
