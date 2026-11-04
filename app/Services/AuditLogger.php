<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Registra eventos críticos en `audit_logs` con el usuario autenticado, IP y agente.
 */
class AuditLogger
{
    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public static function record(string $action, Model $model, ?array $old = null, ?array $new = null): AuditLog
    {
        $type = array_search($model::class, AuditLog::TYPES, true) ?: Str::snake(class_basename($model));
        $request = app()->runningInConsole() && ! app()->runningUnitTests() ? null : request();

        // Un usuario que se borra a sí mismo ya no existe como autor.
        $actor = Auth::id();
        if ($model instanceof User && ! $model->exists && $model->getKey() === $actor) {
            $actor = null;
        }

        return AuditLog::create([
            'user_id' => $actor,
            'action' => $action,
            'auditable_type' => $type,
            'auditable_id' => $model->getKey(),
            'old_values' => $old ?: null,
            'new_values' => $new ?: null,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? Str::limit((string) $request->userAgent(), 250, '') ?: null : null,
        ]);
    }

    /**
     * Valores previos y nuevos de los campos cambiados (para observers en `updated`).
     *
     * @param  array<int, string>  $fields
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    public static function diff(Model $model, array $fields): array
    {
        $old = [];
        $new = [];
        foreach ($fields as $field) {
            if ($model->wasChanged($field)) {
                $old[$field] = $model->getOriginal($field);
                $new[$field] = $model->getAttribute($field);
            }
        }

        return [$old, $new];
    }
}
