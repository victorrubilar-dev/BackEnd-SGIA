<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Consulta del registro de eventos críticos (REQ-NF-01, solo AD-01; solo lectura).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'auditable_type' => ['nullable', Rule::in(array_keys(AuditLog::TYPES))],
            'auditable_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ], [
            'auditable_type.in' => 'auditable_type debe ser '.implode(', ', array_keys(AuditLog::TYPES)).'.',
            'from.date' => 'La fecha inicial no es válida.',
            'to.date' => 'La fecha final no es válida.',
            'to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        $query = AuditLog::with('user');

        if ($request->filled('action')) {
            $action = (string) $request->string('action');
            // `user.` filtra todas las acciones de usuarios.
            str_ends_with($action, '.')
                ? $query->where('action', 'like', $action.'%')
                : $query->where('action', $action);
        }

        foreach (['auditable_type', 'auditable_id', 'user_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }

        if ($request->filled('from')) {
            $query->where('created_at', '>=', $request->date('from')->startOfDay());
        }

        if ($request->filled('to')) {
            $query->where('created_at', '<=', $request->date('to')->endOfDay());
        }

        return AuditLogResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request))
        );
    }
}
