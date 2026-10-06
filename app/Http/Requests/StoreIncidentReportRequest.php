<?php

namespace App\Http\Requests;

use App\Models\IncidentReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreIncidentReportRequest extends FormRequest
{
    /**
     * Normaliza los alias en español aceptados por los clientes:
     * descripcion -> description, titulo -> title, gravedad/severidad -> severity
     * y los alias del adjunto (archivo, adjunto, foto, photo, file).
     */
    public function prepareForValidation(): void
    {
        $aliases = [
            'description' => $this->input('descripcion'),
            'title' => $this->input('titulo'),
            'severity' => $this->input('gravedad') ?? $this->input('severidad'),
        ];

        $this->merge(array_filter($aliases, fn ($value) => $value !== null && $value !== ''));

        // El adjunto también llega como archivo: se normaliza en el FileBag
        // (sin tocar $request->file() para no congelar la caché de archivos).
        $files = $this->files->all();

        if (empty($files['attachment'])) {
            foreach (['archivo', 'adjunto', 'foto', 'photo', 'file'] as $alias) {
                if (! empty($files[$alias])) {
                    $this->files->set('attachment', $files[$alias]);

                    break;
                }
            }
        }
    }

    public function authorize(): bool
    {
        return $this->user()?->can('create', IncidentReport::class) ?? false;
    }

    public function rules(): array
    {
        return [
            // El formulario exige describir la novedad; se acepta el título
            // como mínimo identificador del informe.
            'description' => ['required_without:title', 'nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:150'],
            'severity' => ['sometimes', 'nullable', Rule::in(IncidentReport::SEVERITIES)],
            // Adjunto opcional: documento o imagen (máx. 10 MB).
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'description.required_without' => 'Debes describir la novedad detectada en el equipo.',
            'description.max' => 'La descripción no puede superar los 2000 caracteres.',
            'severity.in' => 'La severidad debe ser leve, media o crítica.',
            'attachment.mimes' => 'El adjunto debe ser un documento PDF o una imagen (jpg, png, webp).',
            'attachment.max' => 'El adjunto no puede superar los 10 MB.',
        ];
    }
}
