<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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
        'invoice_intake_item_id',
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Cajon, $this> */
    public function cajon(): BelongsTo
    {
        return $this->belongsTo(Cajon::class);
    }

    /** Sala del cajón de la unidad (el pañol se alcanza con `location.storeroom`). */
    public function location(): HasOneThrough
    {
        return $this->hasOneThrough(Location::class, Cajon::class, 'id', 'id', 'cajon_id', 'location_id');
    }

    /**
     * Unidades guardadas en un cajón de alguna sala del pañol indicado.
     *
     * @param  Builder<EquipmentUnit>  $query
     */
    public function scopeInStoreroom(Builder $query, int $storeroomId): void
    {
        $query->whereHas('cajon.location', fn (Builder $location) => $location->where('storeroom_id', $storeroomId));
    }

    /**
     * Unidades `disponible` guardadas en el pañol indicado. Es la regla única de disponibilidad
     * por pañol: la usan la validación de préstamos y el listado de productos.
     *
     * @param  Builder<EquipmentUnit>  $query
     */
    public function scopeAvailableInStoreroom(Builder $query, int $storeroomId): void
    {
        $query->where('status', self::STATUS_AVAILABLE)->inStoreroom($storeroomId);
    }

    /** @return HasMany<IncidentReport, $this> */
    public function incidentReports(): HasMany
    {
        return $this->hasMany(IncidentReport::class);
    }

    /** @return HasMany<MaintenanceRecord, $this> */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    /** @return HasMany<MaintenanceDocument, $this> */
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

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
