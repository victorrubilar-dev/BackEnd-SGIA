<?php

namespace App\Http\Requests\Export;

use App\Models\User;
use Illuminate\Validation\Rule;

/** Filtros de `GET /api/users/export` (los mismos de `GET /api/users`). */
class ExportUsersRequest extends ExportRequest
{
    protected ?string $model = User::class;

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:120'],
            'role' => ['nullable', 'string', Rule::in(User::ROLES)],
            'area' => ['nullable', 'string', 'max:120'],
            'career_id' => ['nullable', 'integer'],
            'storeroom_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['role' => 'rol', 'area' => 'área'];
    }
}
