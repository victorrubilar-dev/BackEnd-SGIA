<?php

namespace App\Http\Requests;

use App\Models\IncidentReport;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Formulario del informe de novedades (solo docentes): asignatura, fecha, laboratorio,
 * problemas marcados con su descripción, sugerencia, firma, y foto y equipo opcionales.
 */
class StoreIncidentReportRequest extends FormRequest
{
    /** Tamaño máximo de la firma ya decodificada. */
    public const MAX_SIGNATURE_BYTES = 512 * 1024;

    public function prepareForValidation(): void
    {
        // La fecha es la de hoy salvo que el docente indique otra.
        if (! $this->filled('report_date')) {
            $this->merge(['report_date' => today()->toDateString()]);
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', IncidentReport::class) ?? false;
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:100'],
            'report_date' => ['required', 'date', 'before_or_equal:today'],
            'location_id' => ['required', 'integer', 'exists:locations,id'],
            'problems' => ['required', 'array', 'min:1', 'max:'.count(IncidentReport::PROBLEM_LABELS)],
            'problems.*.type' => ['required', 'string', Rule::in(array_keys(IncidentReport::PROBLEM_LABELS)), 'distinct'],
            'problems.*.description' => ['required', 'string', 'max:1000'],
            'suggestion' => ['nullable', 'string', 'max:2000'],
            // PNG dibujado en pantalla, en base64 (con o sin el prefijo `data:image/png;base64,`).
            'signature' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) {
                $png = self::decodeSignature((string) $value);

                if ($png === null) {
                    $fail('La firma debe ser una imagen PNG válida.');
                } elseif (strlen($png) > self::MAX_SIGNATURE_BYTES) {
                    $fail('La firma no puede superar los 512 KB.');
                }
            }],
            'evidence_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'equipment_code' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * Binario PNG de la firma, o null si no es un PNG en base64.
     */
    public static function decodeSignature(string $value): ?string
    {
        $base64 = preg_replace('#^data:image/png;base64,#i', '', trim($value));
        $binary = base64_decode((string) $base64, true);

        return $binary !== false && str_starts_with($binary, "\x89PNG\r\n\x1a\n") ? $binary : null;
    }

    public function messages(): array
    {
        return [
            'subject.required' => 'La asignatura es obligatoria.',
            'subject.max' => 'La asignatura no puede superar los 100 caracteres.',
            'report_date.before_or_equal' => 'La fecha no puede ser futura.',
            'report_date.date' => 'La fecha debe ser válida.',
            'location_id.required' => 'El laboratorio es obligatorio.',
            'location_id.exists' => 'El laboratorio seleccionado no existe.',
            'problems.required' => 'Marca al menos un problema.',
            'problems.min' => 'Marca al menos un problema.',
            'problems.*.type.required' => 'Cada problema debe indicar su tipo.',
            'problems.*.type.in' => 'El tipo de problema debe ser falla_equipo, falta_equipamiento, falta_insumo u otros.',
            'problems.*.type.distinct' => 'No repitas un mismo tipo de problema.',
            'problems.*.description.required' => 'Describe cada problema marcado.',
            'problems.*.description.max' => 'La descripción de un problema no puede superar los 1000 caracteres.',
            'suggestion.max' => 'La sugerencia no puede superar los 2000 caracteres.',
            'signature.required' => 'La firma del profesor es obligatoria.',
            'evidence_photo.mimes' => 'La foto debe ser jpg, png o webp.',
            'evidence_photo.max' => 'La foto no puede superar los 10 MB.',
        ];
    }
}
