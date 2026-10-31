<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

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
     * `users.role` es la única fuente de escritura: `role_id` se sincroniza solo.
     */
    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->role_id === null || $user->isDirty('role')) {
                $user->role_id = Role::where('code', $user->role)->value('id')
                    ?? Role::where('code', self::ROLE_TEACHER)->value('id');
            }
        });
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

    public function roleModel(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function storeroom(): BelongsTo
    {
        return $this->belongsTo(Storeroom::class);
    }

    public function careers(): BelongsToMany
    {
        return $this->belongsToMany(Career::class)->withTimestamps();
    }

    public function charts(): HasMany
    {
        return $this->hasMany(UserChart::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

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
