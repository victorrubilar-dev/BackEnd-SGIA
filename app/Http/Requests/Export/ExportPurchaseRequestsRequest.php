<?php

namespace App\Http\Requests\Export;

use App\Models\PurchaseRequest;
use App\Services\PurchaseRequestStateMachine;
use Illuminate\Validation\Rule;

/** Filtros de `GET /api/purchase-requests/export` (los mismos de `GET /api/purchase-requests`). */
class ExportPurchaseRequestsRequest extends ExportRequest
{
    protected ?string $model = PurchaseRequest::class;

    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string', Rule::in(PurchaseRequestStateMachine::STATUSES)],
            'code' => ['nullable', 'string', 'max:20'],
            ...$this->dateRange(),
        ];
    }

    public function attributes(): array
    {
        return parent::attributes() + ['code' => 'código'];
    }
}
