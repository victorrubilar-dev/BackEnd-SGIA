<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Intento de inicio de sesión rechazado (monitoreo de seguridad).
 *
 * @property int $id
 * @property string $email
 * @property int|null $user_id
 * @property string $reason
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property Carbon $attempted_at
 */
class FailedLogin extends Model
{
    public const REASON_INVALID_CREDENTIALS = 'invalid_credentials';

    public const REASON_INACTIVE = 'inactive';

    public const REASON_THROTTLED = 'throttled';

    public $timestamps = false;

    protected $fillable = ['email', 'user_id', 'reason', 'ip_address', 'user_agent', 'attempted_at'];

    protected function casts(): array
    {
        return ['attempted_at' => 'datetime'];
    }

    public static function record(Request $request, string $reason, ?User $user = null): self
    {
        return self::create([
            'email' => Str::limit(Str::lower(trim((string) $request->input('email'))), 250, ''),
            'user_id' => $user?->id,
            'reason' => $reason,
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 250, '') ?: null,
            'attempted_at' => now(),
        ]);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
