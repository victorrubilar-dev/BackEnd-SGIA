<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreReportSubscriptionRequest;
use App\Http\Requests\UpdateReportSubscriptionRequest;
use App\Http\Resources\ReportSubscriptionResource;
use App\Jobs\SendReportSubscriptionJob;
use App\Models\ReportSubscription;
use App\Services\ReportSubscriptionService;
use App\Support\ReportSubscriptionPeriod;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Suscripción de reportes por correo del usuario autenticado (docs/suscripcion-reportes.md).
 */
class ReportSubscriptionController extends Controller
{
    /** Mailers que no entregan nada a nadie. */
    private const SILENT_MAILERS = ['log', 'array'];

    public function index(Request $request): AnonymousResourceCollection
    {
        return ReportSubscriptionResource::collection(
            $request->user()->reportSubscriptions()->orderByDesc('created_at')->orderByDesc('id')->get(),
        )->additional(['meta' => [
            'mail_enabled' => ! in_array(config('mail.default'), self::SILENT_MAILERS, true),
            'limit' => ReportSubscription::LIMIT,
        ]]);
    }

    public function store(StoreReportSubscriptionRequest $request, ReportSubscriptionService $service): JsonResponse
    {
        $subscription = $service->create($request->user(), $request->validated());

        return (new ReportSubscriptionResource($subscription))->response()->setStatusCode(201);
    }

    public function update(UpdateReportSubscriptionRequest $request, ReportSubscription $reportSubscription, ReportSubscriptionService $service): ReportSubscriptionResource
    {
        return new ReportSubscriptionResource($service->update($reportSubscription, $request->validated()));
    }

    public function destroy(ReportSubscription $reportSubscription): Response
    {
        Gate::authorize('delete', $reportSubscription);

        $reportSubscription->delete();

        return response()->noContent();
    }

    /** «Enviar ahora»: el último período completo, en cola y sin tocar `last_period`. */
    public function send(ReportSubscription $reportSubscription): JsonResponse
    {
        Gate::authorize('update', $reportSubscription);

        SendReportSubscriptionJob::dispatch($reportSubscription->id, ReportSubscriptionPeriod::previous($reportSubscription->frequency), false);

        return response()->json(['message' => 'El reporte se está enviando a tu correo.'], 202);
    }
}
