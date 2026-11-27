<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Un reporte que el usuario recibe por correo cada semana, mes o semestre (docs/suscripcion-reportes.md).
 *
 * @property int $id
 * @property int $user_id
 * @property string $report_id
 * @property string $title
 * @property string $path
 * @property array<string, mixed> $params
 * @property string $params_hash
 * @property array<string, string> $filters
 * @property string $frequency
 * @property bool $is_active
 * @property string|null $paused_reason
 * @property string|null $last_period
 * @property Carbon|null $last_sent_at
 * @property string|null $last_status
 */
class ReportSubscription extends Model
{
    public const FREQUENCY_WEEKLY = 'weekly';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_SEMESTER = 'semester';

    public const FREQUENCIES = [self::FREQUENCY_WEEKLY, self::FREQUENCY_MONTHLY, self::FREQUENCY_SEMESTER];

    public const STATUS_SENT = 'sent';

    public const STATUS_EMPTY = 'empty';

    public const STATUS_FAILED = 'failed';

    /** Máximo de suscripciones por usuario. */
    public const LIMIT = 20;

    protected $fillable = [
        'user_id', 'report_id', 'title', 'path', 'params', 'params_hash', 'filters', 'frequency',
        'is_active', 'paused_reason', 'last_period', 'last_sent_at', 'last_status',
    ];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'filters' => 'array',
            'is_active' => 'boolean',
            'last_sent_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Deja de enviarse y muestra el motivo en «Mis suscripciones» (decisión 9). */
    public function pause(string $reason): void
    {
        $this->update(['is_active' => false, 'paused_reason' => mb_substr($reason, 0, 200)]);
    }

    /**
     * Huella de los parámetros para no repetir la misma suscripción (el orden y el tipo no importan).
     *
     * @param  array<string, mixed>  $params
     */
    public static function hashParams(array $params): string
    {
        $normalized = array_map(strval(...), $params);
        ksort($normalized);

        return hash('sha256', (string) json_encode($normalized));
    }
}
