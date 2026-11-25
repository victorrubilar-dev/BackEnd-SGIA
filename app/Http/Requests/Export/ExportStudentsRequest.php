<?php

namespace App\Http\Requests\Export;

use App\Models\Student;

/** Filtros de `GET /api/students/export` (los mismos de `GET /api/students`). */
class ExportStudentsRequest extends ExportRequest
{
    protected ?string $model = Student::class;

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'career_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
