<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

/**
 * Unidad física de un producto `equipo` o `maqueta`, con código y ubicación propios.
 */
class EquipmentUnit extends Model
{
    use HasFactory;

    public const STATUS_AVAILABLE = 'disponible';

    public const STATUS_ON_LOAN = 'prestado';

    public const STATUS_IN_REPAIR = 'en_reparacion';

    public const STATUS_WRITTEN_OFF = 'dado_de_baja';

    public const STATUSES = [
        self::STATUS_AVAILABLE,
        self::STATUS_ON_LOAN,
        self::STATUS_IN_REPAIR,
        self::STATUS_WRITTEN_OFF,
    ];

    protected $attributes = [
        'status' => self::STATUS_AVAILABLE,
    ];

    protected $fillable = [
        'product_id',
        'cajon_id',
        'code',
        'serial_number',
        'status',
        'last_maintenance_at',
        'next_maintenance_at',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'last_maintenance_at' => 'datetime',
            'next_maintenance_at' => 'datetime',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function cajon(): BelongsTo
    {
        return $this->belongsTo(Cajon::class);
    }

    /** Sala del cajón de la unidad (el pañol se alcanza con `location.storeroom`). */
    public function location(): HasOneThrough
    {
        return $this->hasOneThrough(Location::class, Cajon::class, 'id', 'id', 'cajon_id', 'location_id');
    }

    public function incidentReports(): HasMany
    {
        return $this->hasMany(IncidentReport::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    public function maintenanceDocuments(): HasMany
    {
        return $this->hasMany(MaintenanceDocument::class);
    }

    public function loanItems(): BelongsToMany
    {
        return $this->belongsToMany(LoanItem::class, 'loan_item_units')
            ->withPivot(['returned_at', 'return_condition'])
            ->withTimestamps();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
