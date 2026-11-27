<?php

namespace App\Services;

use App\Exceptions\ReportDeliveryRejected;
use App\Mail\ReportSubscriptionMail;
use App\Models\AuditLog;
use App\Models\ReportSubscription;
use App\Models\User;
use App\Reports\ReportEmailChannel;
use App\Reports\ReportRoutes;
use App\Support\ReportSubscriptionPeriod;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Envía una suscripción (docs/suscripcion-reportes.md, decisiones 4 a 7 y 11): pide la vista previa y el `.xlsx` a la
 * misma ruta que la descarga, decide adjunto o enlace, manda el correo y lo registra como `report.email`.
 */
class ReportSubscriptionRunner
{
    /** Sobre este tamaño el correo lleva solo el enlace. */
    public const MAX_ATTACHMENT_BYTES = 8 * 1024 * 1024;

    public const DELIVERY_ATTACHMENT = 'attachment';

    public const DELIVERY_LINK = 'link';

    public const DELIVERY_NONE = 'none';

    /** Hojas derivadas del detalle: no cuentan como movimientos. */
    private const SUMMARY_SHEETS = ['Resumen', 'Resumen general'];

    public function __construct(
        private readonly ReportSubrequest $subrequest,
        private readonly int $maxAttachmentBytes = self::MAX_ATTACHMENT_BYTES,
    ) {}

    /**
     * Vista previa del reporte con los filtros guardados y el período indicado.
     *
     * @param  array<string, mixed>  $params
     * @return array{0: Response, 1: ReportEmailChannel}
     */
    public function preview(User $user, string $reportId, array $params, ReportSubscriptionPeriod $period): array
    {
        [$path, $query] = ReportRoutes::request($reportId, $params, $period->from, $period->to);
        $channel = new ReportEmailChannel;

        return [$this->subrequest->get($user, $path, $query + ['preview' => '1'], $channel), $channel];
    }

    /**
     * @return string `sent` o `empty`
     *
     * @throws ReportDeliveryRejected si la ruta ya no deja al usuario ver el reporte
     * @throws RuntimeException si falla el servidor (la cola reintenta)
     */
    public function send(ReportSubscription $subscription, ReportSubscriptionPeriod $period): string
    {
        $user = $subscription->user;

        [$response, $channel] = $this->preview($user, $subscription->report_id, $subscription->params, $period);
        $this->ensureSuccessful($response);

        $sheets = $this->sheetCounts($response);
        $rows = array_sum(array_column($sheets, 'rows'));
        [$path, $query] = ReportRoutes::request($subscription->report_id, $subscription->params, $period->from, $period->to);

        // Datos personales nunca adjuntos; sin filas tampoco hay archivo (decisiones 5 y 6).
        $file = null;
        $delivery = $channel->personal ? self::DELIVERY_LINK : ($rows === 0 ? self::DELIVERY_NONE : self::DELIVERY_ATTACHMENT);
        if ($delivery === self::DELIVERY_ATTACHMENT) {
            $download = $this->subrequest->get($user, $path, $query, $channel);
            $this->ensureSuccessful($download);
            $content = (string) $download->getContent();
            if (strlen($content) > $this->maxAttachmentBytes) {
                $delivery = self::DELIVERY_LINK;
            } else {
                $file = ['name' => (string) $channel->filename, 'content' => $content];
            }
        }

        Mail::to($user->email)->send(new ReportSubscriptionMail(
            title: $subscription->title,
            recipient: $user->name,
            frequency: $subscription->frequency,
            kind: ReportRoutes::kind($subscription->report_id),
            period: $period,
            filters: $channel->filters,
            sheets: $sheets,
            delivery: $delivery,
            empty: $rows === 0,
            personal: $channel->personal,
            url: config('sgia.frontend_url').'/reportes?reporte='.$subscription->report_id,
            file: $file,
        ));

        AuditLog::create([
            'user_id' => $user->id,
            'action' => AuditLog::REPORT_EMAIL_ACTION,
            'auditable_type' => AuditLog::REPORT_TYPE,
            'auditable_id' => 0,
            'old_values' => null,
            'new_values' => [
                'report' => $channel->slug,
                'title' => $channel->title,
                'filters' => $channel->filters,
                'period' => ['key' => $period->key, 'from' => $period->from, 'to' => $period->to],
                'rows' => $rows,
                'delivery' => $delivery,
                'email' => $user->email,
                'path' => $path,
                'query' => $query,
                'personal' => $channel->personal,
                'subscription_id' => $subscription->id,
            ],
        ]);

        return $rows === 0 ? ReportSubscription::STATUS_EMPTY : ReportSubscription::STATUS_SENT;
    }

    private function ensureSuccessful(Response $response): void
    {
        $status = $response->getStatusCode();
        if ($response->isSuccessful()) {
            return;
        }

        if ($status >= 500 || $status === 429) {
            throw new RuntimeException("El reporte respondió {$status}.");
        }

        $message = json_decode((string) $response->getContent(), true)['message'] ?? '';

        throw new ReportDeliveryRejected(match ($status) {
            403 => 'Tu rol ya no puede ver este reporte.',
            422 => trim('Los filtros ya no son válidos. '.$message),
            default => trim('El reporte ya no está disponible. '.$message),
        });
    }

    /**
     * Filas por hoja de datos según la vista previa.
     *
     * @return list<array{name: string, rows: int, truncated: bool}>
     */
    private function sheetCounts(Response $preview): array
    {
        $sheets = json_decode((string) $preview->getContent(), true)['sheets'] ?? [];

        return array_values(array_map(
            fn (array $sheet) => ['name' => $sheet['name'], 'rows' => (int) $sheet['total_rows'], 'truncated' => (bool) $sheet['truncated']],
            array_filter($sheets, fn (array $sheet) => ! in_array($sheet['name'], self::SUMMARY_SHEETS, true)),
        ));
    }
}
