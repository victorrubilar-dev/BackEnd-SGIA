<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $table = 'usuarios';

    public const CREATED_AT = 'created_at';
    public const UPDATED_AT = null;

    public const ROLE_ADMIN = 'AD-01';
    public const ROLE_DIRECTOR = 'DIR-01';
    public const ROLE_WAREHOUSE = 'PAN-01';
    public const ROLE_TEACHER = 'PRO-01';

    public const ROLES = [
        self::ROLE_ADMIN,
        self::ROLE_DIRECTOR,
        self::ROLE_WAREHOUSE,
        self::ROLE_TEACHER,
    ];

    protected $fillable = [
        'rol_id',
        'panol_id',
        'nombre',
        'email',
        'password',
        'activo',
    ];

    protected $hidden = [
        'password',
    ];

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'password' => 'hashed',
            'created_at' => 'datetime',
        ];
    }

    public function rol(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'rol_id');
    }

    public function panol(): BelongsTo
    {
        return $this->belongsTo(Panol::class, 'panol_id');
    }

    public function carreras(): BelongsToMany
    {
        return $this->belongsToMany(Carrera::class, 'carrera_usuario', 'usuario_id', 'carrera_id');
    }

    public function graficos(): HasMany
    {
        return $this->hasMany(Grafico::class, 'usuario_id');
    }

    public function ingresosOcr(): HasMany
    {
        return $this->hasMany(IngresoOcr::class, 'usuario_id');
    }

    public function procesosCotizacion(): HasMany
    {
        return $this->hasMany(ProcesoCotizacion::class, 'usuario_id');
    }

    public function informesNovedades(): HasMany
    {
        return $this->hasMany(InformeNovedad::class, 'usuario_id');
    }

    public function registrosMantencion(): HasMany
    {
        return $this->hasMany(RegistroMantencion::class, 'responsable_id');
    }

    public function documentosMantenimiento(): HasMany
    {
        return $this->hasMany(DocumentoMantenimiento::class, 'subido_por_id');
    }

    public function prestamosDocente(): HasMany
    {
        return $this->hasMany(Prestamo::class, 'profesor_id');
    }

    public function prestamosDespachados(): HasMany
    {
        return $this->hasMany(Prestamo::class, 'despachado_por_id');
    }

    public function prestamosRecibidos(): HasMany
    {
        return $this->hasMany(Prestamo::class, 'recibido_por_id');
    }

    /**
     * Accesor para compatibilidad con código que consulta $user->name.
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->nombre,
        );
    }

    /**
     * Accesor para compatibilidad con código que consulta $user->is_active.
     */
    protected function isActive(): Attribute
    {
        return Attribute::make(
            get: fn () => (bool) $this->activo,
        );
    }

    /**
     * Accesor para compatibilidad con código que consulta $user->role.
     */
    protected function role(): Attribute
    {
        return Attribute::make(
            get: function () {
                $nombre = $this->rol?->nombre;
                return match ($nombre) {
                    'Administrador' => 'AD-01',
                    'Director de carrera' => 'DIR-01',
                    'Pañol', 'Ayudante de pañol' => 'PAN-01',
                    'Docente' => 'PRO-01',
                    default => $nombre,
                };
            },
        );
    }

    /**
     * Verifica si el usuario posee alguno de los roles indicados (código o nombre).
     */
    public function hasRole(string|array $roles): bool
    {
        if (is_string($roles)) {
            $roles = [$roles];
        }

        $rolNombre = $this->rol?->nombre;
        $rolCodigo = match ($rolNombre) {
            'Administrador' => 'AD-01',
            'Director de carrera' => 'DIR-01',
            'Pañol', 'Ayudante de pañol' => 'PAN-01',
            'Docente' => 'PRO-01',
            default => null,
        };

        foreach ($roles as $role) {
            if ($role === $rolNombre || ($rolCodigo !== null && $role === $rolCodigo)) {
                return true;
            }
            if ($role === 'PAN-01' && in_array($rolNombre, ['Pañol', 'Ayudante de pañol'], true)) {
                return true;
            }
        }

        return false;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(['AD-01', 'Administrador']);
    }

    public function isDirector(): bool
    {
        return $this->hasRole(['DIR-01', 'Director de carrera']);
    }

    public function isWarehouse(): bool
    {
        return $this->hasRole(['PAN-01', 'Pañol', 'Ayudante de pañol']);
    }

    public function isTeacher(): bool
    {
        return $this->hasRole(['PRO-01', 'Docente']);
    }
}
