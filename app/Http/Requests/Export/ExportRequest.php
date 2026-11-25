<?php

namespace App\Http\Requests\Export;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Base de las solicitudes de reporte Excel (docs/reportes-excel.md): valida los mismos filtros que el
 * listado de la pantalla y autoriza con la policy `viewAny` del recurso (el rol ya lo filtra la ruta).
 */
abstract class ExportRequest extends FormRequest
{
    /** Modelo cuya policy `viewAny` autoriza el reporte; `null` si basta el middleware de rol. */
    protected ?string $model = null;

    public function authorize(): bool
    {
        return $this->model === null || ($this->user()?->can('viewAny', $this->model) ?? false);
    }

    /**
     * Reglas de `from` / `to` (rango de fechas).
     *
     * @return array<string, list<string>>
     */
    protected function dateRange(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    public function messages(): array
    {
        return [
            'date' => 'La fecha de :attribute no es válida.',
            'integer' => 'El filtro :attribute no es válido.',
            'numeric' => 'El filtro :attribute no es válido.',
            'boolean' => 'El filtro :attribute debe ser sí o no.',
            'in' => 'El valor del filtro :attribute no es válido.',
            'string' => 'El filtro :attribute no es válido.',
            'max' => 'El filtro :attribute es demasiado largo.',
            'exists' => 'El filtro :attribute no existe.',
            'between' => 'El filtro :attribute no es válido.',
            'to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ];
    }

    public function attributes(): array
    {
        return [
            'from' => 'inicio',
            'to' => 'término',
            'search' => 'búsqueda',
            'status' => 'estado',
            'career_id' => 'carrera',
            'storeroom_id' => 'pañol',
            'location_id' => 'sala',
            'cajon_id' => 'cajón',
            'is_active' => 'activo',
            'type' => 'tipo',
        ];
    }
}
