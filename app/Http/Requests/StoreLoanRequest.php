<?php

namespace App\Http\Requests;

use App\Models\Loan;
use App\Models\Location;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Solicitud remota de préstamo del profesor (PRO-01). El profesor es siempre el usuario
 * autenticado: `user_id` / `teacher_id` no se leen del cuerpo.
 *
 * Cliente nuevo: `location_id` + `storeroom_id` + `start_time` + `end_time`.
 * Cliente antiguo (solo `room`, sin `location_id` ni `storeroom_id`): `time_block` y `room`
 * en texto libre, como antes.
 */
class StoreLoanRequest extends FormRequest
{
    /**
     * Acepta los alias en español usados por los clientes móviles:
     * sala -> room, asignatura -> subject, fecha -> loan_date.
     * Y deriva start_time / end_time desde un `time_block` "HH:MM - HH:MM" si no llegan.
     */
    public function prepareForValidation(): void
    {
        $aliases = [
            'room' => $this->input('sala'),
            'subject' => $this->input('asignatura'),
            'loan_date' => $this->input('fecha') ?? $this->input('fecha_prestamo'),
        ];

        $this->merge(array_filter($aliases, fn ($value) => $value !== null && $value !== ''));

        if (! $this->filled('start_time') && ! $this->filled('end_time')
            && preg_match('/^\s*(\d{1,2}:\d{2})\s*-\s*(\d{1,2}:\d{2})\s*$/', (string) $this->input('time_block'), $m)) {
            $this->merge([
                'start_time' => str_pad($m[1], 5, '0', STR_PAD_LEFT),
                'end_time' => str_pad($m[2], 5, '0', STR_PAD_LEFT),
            ]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', Loan::class) ?? false;
    }

    /**
     * Cliente antiguo: manda `room` de texto libre y ni `location_id` ni `storeroom_id`.
     */
    public function isLegacy(): bool
    {
        return ! $this->filled('location_id') && ! $this->filled('storeroom_id') && $this->filled('room');
    }

    public function rules(): array
    {
        $legacy = $this->isLegacy();

        return [
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'career_id' => ['required', 'integer', 'exists:careers,id'],
            'subject' => ['required', 'string', 'max:255'],
            // El día lo define Chile: la app corre en UTC y de noche ya sería mañana.
            'loan_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:'.now('America/Santiago')->toDateString()],
            'due_date' => ['nullable', 'date', 'after_or_equal:loan_date'],
            'start_time' => [$legacy ? 'nullable' : 'required', 'date_format:H:i'],
            'end_time' => [$legacy ? 'nullable' : 'required', 'date_format:H:i', 'after:start_time'],
            'storeroom_id' => [$legacy ? 'nullable' : 'required', 'integer', 'exists:storerooms,id'],
            'location_id' => [$legacy ? 'nullable' : 'required', 'integer', 'exists:locations,id', $this->laboratoryRule()],
            'room' => [$legacy ? 'required' : 'nullable', 'string', 'max:100'],
            'time_block' => ['nullable', 'string', 'max:50'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * El laboratorio debe ser una sala o un taller del pañol indicado.
     */
    private function laboratoryRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) {
            $location = Location::find($value);

            if ($location === null) {
                return;
            }

            if (! in_array($location->tipo, [Location::TIPO_SALA, Location::TIPO_TALLER], true)) {
                $fail('El laboratorio debe ser una sala o un taller.');
            } elseif ($this->filled('storeroom_id') && $location->storeroom_id !== $this->integer('storeroom_id')) {
                $fail('El laboratorio no pertenece al pañol indicado.');
            }
        };
    }

    public function messages(): array
    {
        return [
            'items.required' => 'La solicitud debe indicar al menos un insumo o producto.',
            'items.array' => 'El campo items debe ser una lista.',
            'items.min' => 'La solicitud debe incluir al menos un producto.',
            'items.*.product_id.required' => 'Cada ítem de la solicitud debe indicar el producto.',
            'items.*.product_id.exists' => 'El producto solicitado no existe en el inventario.',
            'items.*.product_id.distinct' => 'No repitas un mismo producto en la solicitud.',
            'items.*.quantity.required' => 'Cada ítem de la solicitud debe indicar la cantidad.',
            'items.*.quantity.min' => 'La cantidad solicitada debe ser al menos 1.',
            'career_id.required' => 'La carrera es obligatoria.',
            'career_id.exists' => 'La carrera seleccionada no existe.',
            'subject.required' => 'La asignatura es obligatoria.',
            'subject.max' => 'La asignatura no puede superar los 255 caracteres.',
            'loan_date.required' => 'La fecha de la actividad es obligatoria.',
            'loan_date.date_format' => 'La fecha debe tener el formato AAAA-MM-DD.',
            'loan_date.after_or_equal' => 'La fecha de la actividad debe ser hoy o una fecha futura.',
            'start_time.required' => 'La hora de inicio es obligatoria.',
            'start_time.date_format' => 'La hora de inicio debe tener el formato HH:MM.',
            'end_time.required' => 'La hora de término es obligatoria.',
            'end_time.date_format' => 'La hora de término debe tener el formato HH:MM.',
            'end_time.after' => 'La hora de término debe ser posterior a la de inicio.',
            'storeroom_id.required' => 'El pañol es obligatorio.',
            'storeroom_id.exists' => 'El pañol seleccionado no existe.',
            'location_id.required' => 'El laboratorio es obligatorio.',
            'location_id.exists' => 'El laboratorio seleccionado no existe.',
            'room.required' => 'El laboratorio es obligatorio.',
            'room.max' => 'La sala no puede superar los 100 caracteres.',
        ];
    }
}
