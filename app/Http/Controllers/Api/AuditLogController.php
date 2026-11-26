<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Export\ExportAuditLogsRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Reports\AuditLogsReport;
use App\Reports\ReportExporter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Consulta del registro de eventos críticos (REQ-NF-01, solo AD-01; solo lectura).
 */
class AuditLogController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $request->validate([
            'auditable_type' => ['nullable', Rule::in([...array_keys(AuditLog::TYPES), AuditLog::REPORT_TYPE])],
            'auditable_id' => ['nullable', 'integer'],
            'user_id' => ['nullable', 'integer'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ], [
            'auditable_type.in' => 'auditable_type debe ser '.implode(', ', [...array_keys(AuditLog::TYPES), AuditLog::REPORT_TYPE]).'.',
            'from.date' => 'La fecha inicial no es válida.',
            'to.date' => 'La fecha final no es válida.',
            'to.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
        ]);

        $query = $this->filter(AuditLog::with('user'), $request);

        return AuditLogResource::collection(
            $query->orderByDesc('created_at')->orderByDesc('id')->paginate($this->perPage($request))
        );
    }

    /**
     * Reporte Excel de los eventos con los filtros del listado; la exportación queda en auditoría (docs/reportes-excel.md).
     */
    public function export(ExportAuditLogsRequest $request, ReportExporter $exporter): Response|JsonResponse
    {
        return $exporter->respond(new AuditLogsReport($request, $this->filter(AuditLog::query(), $request)), $request);
    }

    /**
     * Filtros del listado (`action` con prefijo `user.`, `auditable_type`, `auditable_id`, `user_id`, `from`/`to`),
     * compartidos con el reporte Excel.
     *
     * @param  Builder<AuditLog>  $query
     * @return Builder<AuditLog>
     */
    private function filter(Builder $query, Request $request): Builder
    {
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

        return $query;
    }
}
