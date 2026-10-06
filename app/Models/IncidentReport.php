<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class IncidentReport extends Model
{
    use HasFactory;

    public const SEVERITY_LOW = 'leve';
    public const SEVERITY_MEDIUM = 'media';
    public const SEVERITY_HIGH = 'critica';

    public const SEVERITIES = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
    ];

    public const STATUS_REPORTED = 'reportado';
    public const STATUS_IN_REVIEW = 'en_revision';
    public const STATUS_IN_REPAIR = 'en_reparacion';
    public const STATUS_REPAIRED = 'reparado';
    public const STATUS_DISCARDED = 'dado_de_baja';

    public const STATUSES = [
        self::STATUS_REPORTED,
        self::STATUS_IN_REVIEW,
        self::STATUS_IN_REPAIR,
        self::STATUS_REPAIRED,
        self::STATUS_DISCARDED,
    ];

    public const SEVERITY_LABELS = [
        self::SEVERITY_LOW => 'Leve',
        self::SEVERITY_MEDIUM => 'Media',
        self::SEVERITY_HIGH => 'Crítica',
    ];

    protected $fillable = [
        'product_id',
        'code',
        'reported_by',
        'title',
        'description',
        'severity',
        'status',
        'attachment',
        'attachment_original_name',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function severityLabel(): string
    {
        return self::SEVERITY_LABELS[$this->severity] ?? $this->severity;
    }

    /**
     * Generate a unique report code (e.g. NV-2026-A1B2C3).
     */
    public static function generateUniqueCode(): string
    {
        do {
            $code = 'NV-' . date('Y') . '-' . strtoupper(Str::random(6));
        } while (static::where('code', $code)->exists());

        return $code;
    }
}
