<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string|null $role Código del rol (`AD-01`…), derivado de `role_id`.
 */
#[Fillable(['name', 'email', 'password', 'last_login_at', 'role', 'area', 'is_active', 'storeroom_id'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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

    /**
     * Sin rol explícito el usuario queda como docente (`PRO-01`).
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            $user->role_id ??= Role::idFor(self::ROLE_TEACHER);
        });
    }

    /**
     * Código del rol (`AD-01`…). Se guarda como `role_id`; la API sigue usando el código.
     */
    protected function role(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value, array $attributes) => Role::codeFor(isset($attributes['role_id']) ? (int) $attributes['role_id'] : null),
            set: fn (string $code) => ['role_id' => Role::idFor($code)],
        );
    }

    /**
     * Pañol que limita los préstamos que ve y opera el usuario: el suyo si es pañolero (o el pañol
     * por defecto si no tiene uno asignado). `null` para los demás roles, que no se limitan por pañol.
     */
    public function loanStoreroomId(): ?int
    {
        if (! $this->hasRole(self::ROLE_WAREHOUSE)) {
            return null;
        }

        return $this->storeroom_id ?? Storeroom::default()->id;
    }

    /**
     * Usuarios con alguno de los roles indicados (por código).
     *
     * @param  Builder<User>  $query
     * @param  string|array<int, string>  $roles
     */
    public function scopeWithRole(Builder $query, string|array $roles): void
    {
        $query->whereIn('role_id', Role::idsFor((array) $roles));
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsTo<Role, $this> */
    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    /** @return BelongsTo<Storeroom, $this> */
    public function storeroom(): BelongsTo
    {
        return $this->belongsTo(Storeroom::class);
    }

    public function careers(): BelongsToMany
    {
        return $this->belongsToMany(Career::class)->withTimestamps();
    }

    /** @return HasMany<DashboardTemplate, $this> */
    public function dashboardTemplates(): HasMany
    {
        return $this->hasMany(DashboardTemplate::class);
    }

    /** @return HasMany<UserChart, $this> */
    public function charts(): HasMany
    {
        return $this->hasMany(UserChart::class);
    }

    /** @return HasMany<DeviceToken, $this> */
    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /** @return HasMany<LoginRecord, $this> */
    public function loginRecords(): HasMany
    {
        return $this->hasMany(LoginRecord::class);
    }

    /**
     * Determine if the user has any of the given roles.
     *
     * @param  string|array<int, string>  $roles
     */
    public function hasRole(string|array $roles): bool
    {
        if (is_string($roles)) {
            $roles = [$roles];
        }

        return in_array($this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole(self::ROLE_ADMIN);
    }

    /**
     * Gestiona presupuestos (crear, editar, borrar, comprometer en compras, facturas y reportes).
     * DIR-01 es director de área y el sistema hoy solo cubre el Área de Informática, así que
     * opera sobre todas sus carreras (ISD, II, IC, AP), igual que AD-01. Si se agregan áreas,
     * aquí va el filtro por área.
     */
    public function managesBudgets(): bool
    {
        return $this->is_active && $this->hasRole([self::ROLE_ADMIN, self::ROLE_DIRECTOR]);
    }

    public function isDirector(): bool
    {
        return $this->hasRole(self::ROLE_DIRECTOR);
    }

    public function isWarehouse(): bool
    {
        return $this->hasRole(self::ROLE_WAREHOUSE);
    }

    public function isTeacher(): bool
    {
        return $this->hasRole(self::ROLE_TEACHER);
    }
}
