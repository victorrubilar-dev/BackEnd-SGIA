<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Export\ExportMonitoringRequest;
use App\Http\Requests\LoginActivityRequest;
use App\Http\Resources\FailedLoginResource;
use App\Http\Resources\SessionResource;
use App\Models\FailedLogin;
use App\Models\LoginRecord;
use App\Models\User;
use App\Reports\MonitoringReport;
use App\Reports\ReportExporter;
use App\Services\AuditLogger;
use App\Services\SecurityMonitorService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Monitoreo de seguridad y salud (solo AD-01).
 */
class MonitoringController extends Controller
{
    public function __construct(private SecurityMonitorService $monitor) {}

    /**
     * GET /api/monitoring/logins — resumen de inicios de sesión de las últimas `hours` horas (24 por defecto),
     * o del período `from`–`to` si viene.
     */
    public function logins(LoginActivityRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->monitor->loginActivity($request->integer('hours', 24), $request->date('from'), $request->date('to'))]);
    }

    /**
     * GET /api/monitoring/failed-logins — intentos rechazados, más reciente primero. Filtros `email`, `ip_address`,
     * `reason` y `from`/`to` (fecha del intento).
     */
    public function failedLogins(Request $request): AnonymousResourceCollection
    {
        $this->validateDateRange($request);
        $query = $this->failedLoginsQuery($request);

        return FailedLoginResource::collection($query->orderByDesc('attempted_at')->orderByDesc('id')->paginate($this->perPage($request)));
    }

    /**
     * GET /api/monitoring/sessions — sesiones vigentes de todos los usuarios, la usada más recientemente primero.
     */
    public function sessions(Request $request): AnonymousResourceCollection
    {
        $tokens = $this->sessionsQuery()
            ->with('tokenable')
            ->orderByRaw('last_used_at desc nulls last')
            ->orderByDesc('id')
            ->paginate($this->perPage($request));

        return SessionResource::collection($tokens);
    }

    /**
     * GET /api/monitoring/export — reporte Excel: inicios de sesión de la ventana `hours` (o del período `from`–`to`),
     * intentos fallidos con los filtros de `failed-logins` y sesiones activas. Tiene correos e IP: queda en
     * auditoría (docs/reportes-excel.md).
     */
    public function export(ExportMonitoringRequest $request, ReportExporter $exporter): Response|JsonResponse
    {
        $hours = $request->integer('hours', 24);
        [$from, $to] = $this->monitor->window($hours, $request->date('from'), $request->date('to'));

        return $exporter->respond(new MonitoringReport(
            $request,
            $hours,
            LoginRecord::query()->where('logged_in_at', '>=', $from)->where('logged_in_at', '<=', $to),
            $this->failedLoginsQuery($request),
            $this->sessionsQuery(),
        ), $request);
    }

    /**
     * Intentos rechazados con los filtros `email` (parcial), `ip_address`, `reason` y `from`/`to` (fecha del intento;
     * sin ellos, todos).
     *
     * @return Builder<FailedLogin>
     */
    private function failedLoginsQuery(Request $request): Builder
    {
        return FailedLogin::query()
            ->when($request->filled('email'), fn ($q) => $q->where('email', 'ilike', '%'.$request->string('email').'%'))
            ->when($request->filled('ip_address'), fn ($q) => $q->where('ip_address', $request->string('ip_address')))
            ->when($request->filled('reason'), fn ($q) => $q->where('reason', $request->string('reason')))
            ->when($request->filled('from'), fn ($q) => $q->where('attempted_at', '>=', $request->date('from')->startOfDay()))
            ->when($request->filled('to'), fn ($q) => $q->where('attempted_at', '<=', $request->date('to')->endOfDay()));
    }

    /**
     * Tokens de usuarios no vencidos.
     *
     * @return Builder<PersonalAccessToken>
     */
    private function sessionsQuery(): Builder
    {
        return PersonalAccessToken::query()
            ->where('tokenable_type', User::class)
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /**
     * DELETE /api/monitoring/sessions/{id} — cierra una sesión ajena y lo deja en auditoría (`user.session_revoked`).
     */
    public function revokeSession(Request $request, int $session): Response|JsonResponse
    {
        $token = PersonalAccessToken::where('tokenable_type', User::class)->with('tokenable')->findOrFail($session);

        // La API usa tokens Bearer: la sesión actual siempre es un PersonalAccessToken.
        if ($request->user()->currentAccessToken()->is($token)) {
            return $this->unprocessable('No puedes cerrar tu propia sesión desde aquí; usa «Cerrar sesión».', 'session');
        }

        $token->delete();
        if ($token->tokenable instanceof User) {
            AuditLogger::record('user.session_revoked', $token->tokenable, null, ['token_id' => $token->id, 'device_name' => $token->name]);
        }

        return response()->noContent();
    }

    /**
     * GET /api/monitoring/health — estado detallado de base de datos, almacenamiento y cola.
     */
    public function health(): JsonResponse
    {
        return response()->json(['data' => $this->monitor->health()]);
    }
}
