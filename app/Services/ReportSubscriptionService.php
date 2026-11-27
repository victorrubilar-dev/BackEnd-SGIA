<?php

namespace App\Services;

use App\Models\ReportSubscription;
use App\Models\User;
use App\Reports\ReportRoutes;
use App\Support\ReportSubscriptionPeriod;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;

/**
 * Alta y cambios de las suscripciones de reportes por correo (docs/suscripcion-reportes.md, decisiones 8 y 12).
 */
class ReportSubscriptionService
{
    private const DUPLICATED = 'Ya tienes una suscripción a este reporte con los mismos filtros y frecuencia.';

    public function __construct(private readonly ReportSubscriptionRunner $runner) {}

    /**
     * @param  array{report_id: string, title: string, frequency: string, params?: array<string, mixed>|null}  $data
     */
    public function create(User $user, array $data): ReportSubscription
    {
        $reportId = $data['report_id'];
        $params = ReportRoutes::clean($reportId, $data['params'] ?? []);
        $hash = ReportSubscription::hashParams($params);

        if ($user->reportSubscriptions()->count() >= ReportSubscription::LIMIT) {
            throw ValidationException::withMessages(['report_id' => 'Puedes tener hasta '.ReportSubscription::LIMIT.' suscripciones: quita alguna para agregar otra.']);
        }
        $this->ensureUnique($user->id, $reportId, $hash, $data['frequency']);

        $filters = $this->validateAgainstRoute($user, $reportId, $params, $data['frequency']);

        try {
            return $user->reportSubscriptions()->create([
                'report_id' => $reportId,
                'title' => $data['title'],
                'path' => ReportRoutes::path($reportId),
                'params' => $params,
                'params_hash' => $hash,
                'filters' => $filters,
                'frequency' => $data['frequency'],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['report_id' => self::DUPLICATED]);
        }
    }

    /**
     * @param  array{frequency?: string, is_active?: bool}  $data
     */
    public function update(ReportSubscription $subscription, array $data): ReportSubscription
    {
        if (isset($data['frequency']) && $data['frequency'] !== $subscription->frequency) {
            $this->ensureUnique($subscription->user_id, $subscription->report_id, $subscription->params_hash, $data['frequency'], 'frequency');
            $subscription->frequency = $data['frequency'];
        }

        if (array_key_exists('is_active', $data)) {
            $subscription->is_active = (bool) $data['is_active'];
            if ($subscription->is_active) {
                $subscription->paused_reason = null;
            }
        }

        $subscription->save();

        return $subscription;
    }

    private function ensureUnique(int $userId, string $reportId, string $hash, string $frequency, string $field = 'report_id'): void
    {
        $exists = ReportSubscription::query()
            ->where(['user_id' => $userId, 'report_id' => $reportId, 'params_hash' => $hash, 'frequency' => $frequency])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([$field => self::DUPLICATED]);
        }
    }

    /**
     * Pide la vista previa como el usuario: si el rol no puede o los filtros no valen, responde ese error.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string> Filtros legibles, sin el período.
     */
    private function validateAgainstRoute(User $user, string $reportId, array $params, string $frequency): array
    {
        [$response, $channel] = $this->runner->preview($user, $reportId, $params, ReportSubscriptionPeriod::previous($frequency));

        if (! $response->isSuccessful()) {
            // Solo `403` y `422` son del usuario; el resto no expone detalles.
            $status = $response->getStatusCode();
            abort_unless(in_array($status, [403, 422], true), 500);

            throw new HttpResponseException(response()->json(json_decode((string) $response->getContent(), true), $status));
        }

        return ReportRoutes::savedFilters($reportId, $channel->filters);
    }
}
