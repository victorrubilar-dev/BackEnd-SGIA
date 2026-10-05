<?php

namespace App\Models;

use App\Services\LoanStateMachine;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Loan extends Model
{
    use HasFactory;

    public const STATUS_PENDING = LoanStateMachine::STATUS_PENDING;
    public const STATUS_IN_PROGRESS = LoanStateMachine::STATUS_IN_PROGRESS;
    public const STATUS_PROCESSED = LoanStateMachine::STATUS_PROCESSED;
    public const STATUS_REJECTED = LoanStateMachine::STATUS_REJECTED;

    public const TYPE_REMOTE = 'remoto';
    public const TYPE_DIRECT = 'presencial';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_IN_PROGRESS,
        self::STATUS_PROCESSED,
        self::STATUS_REJECTED,
    ];

    protected $fillable = [
        'code',
        'type',
        'requested_by',
        'borrower_name',
        'borrower_document',
        'subject',
        'room',
        'loan_date',
        'time_block',
        'status',
        'rejection_reason',
        'approved_by',
        'approved_at',
        'processed_by',
        'processed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'loan_date' => 'date',
            'approved_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(LoanItem::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isInProcess(): bool
    {
        return $this->status === self::STATUS_IN_PROGRESS;
    }

    public function isProcessed(): bool
    {
        return $this->status === self::STATUS_PROCESSED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::STATUS_REJECTED;
    }

    /**
     * Generate a unique loan code (e.g. REM-2026-A1B2C3 / PRE-2026-A1B2C3).
     */
    public static function generateUniqueCode(string $type = self::TYPE_REMOTE): string
    {
        $prefix = $type === self::TYPE_DIRECT ? 'PRE' : 'REM';

        do {
            $code = $prefix . '-' . date('Y') . '-' . strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
