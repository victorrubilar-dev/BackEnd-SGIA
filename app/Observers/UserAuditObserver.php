<?php

namespace App\Observers;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;

/**
 * Auditoría de usuarios: alta, cambios de datos, de rol, de estado y de contraseña, y borrado.
 * `last_login_at` y `remember_token` no se auditan.
 */
class UserAuditObserver
{
    private const PROFILE_FIELDS = ['name', 'email', 'area', 'storeroom_id'];

    public function created(User $user): void
    {
        AuditLogger::record('user.created', $user, null, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'area' => $user->area,
            'is_active' => (bool) $user->is_active,
            'storeroom_id' => $user->storeroom_id,
        ]);
    }

    public function updated(User $user): void
    {
        if ($user->wasChanged('role_id')) {
            AuditLogger::record('user.role_changed', $user,
                ['role' => Role::codeFor((int) $user->getOriginal('role_id'))],
                ['role' => $user->role]);
        }

        if ($user->wasChanged('is_active')) {
            AuditLogger::record($user->is_active ? 'user.activated' : 'user.deactivated', $user,
                ['is_active' => (bool) $user->getOriginal('is_active')],
                ['is_active' => (bool) $user->is_active]);
        }

        if ($user->wasChanged('password')) {
            AuditLogger::record('user.password_changed', $user);
        }

        [$old, $new] = AuditLogger::diff($user, self::PROFILE_FIELDS);
        if ($new !== []) {
            AuditLogger::record('user.updated', $user, $old, $new);
        }
    }

    public function deleted(User $user): void
    {
        AuditLogger::record('user.deleted', $user, [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
        ]);
    }
}
