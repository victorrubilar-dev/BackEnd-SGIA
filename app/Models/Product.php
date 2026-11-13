<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    use HasFactory;

    public const TYPE_EQUIPMENT = 'equipo';

    public const TYPE_SUPPLY = 'insumo';

    public const TYPE_SCALE_MODEL = 'maqueta';

    public const TYPES = [self::TYPE_EQUIPMENT, self::TYPE_SUPPLY, self::TYPE_SCALE_MODEL];

    public const MAINTENANCE_PRIORITIES = ['A', 'B', 'C'];

    protected $attributes = [
        'type' => self::TYPE_EQUIPMENT,
    ];

    protected $fillable = [
        'name',
        'description',
        'barcode',
        'type',
        'quantity',
        'quantity_in_repair',
        'quantity_written_off',
        'stock_minimo',
        'supplier_id',
        'cajon_id',
        'location_id',
        'area',
        'category_id',
        'career_id',
        'maintenance_priority',
        'requires_calibration',
        'maintenance_frequency_months',
        'reference_price',
        'photo_url',
        'is_active',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'quantity_in_repair' => 'integer',
            'quantity_written_off' => 'integer',
            'stock_minimo' => 'integer',
            'is_active' => 'boolean',
            'requires_calibration' => 'boolean',
            'maintenance_frequency_months' => 'integer',
            'reference_price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Category, $this> */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /** @return BelongsTo<Career, $this> */
    public function career(): BelongsTo
    {
        return $this->belongsTo(Career::class);
    }

    /**
     * Equipos y maquetas se gestionan por unidades; los insumos solo por cantidad.
     */
    public function hasUnits(): bool
    {
        return in_array($this->type, [self::TYPE_EQUIPMENT, self::TYPE_SCALE_MODEL], true);
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<Cajon, $this> */
    public function cajon(): BelongsTo
    {
        return $this->belongsTo(Cajon::class);
    }

    /** @return BelongsTo<Location, $this> */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
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

    /** @return HasMany<EquipmentUnit, $this> */
    public function units(): HasMany
    {
        return $this->hasMany(EquipmentUnit::class);
    }

    /** @return HasMany<StockAlert, $this> */
    public function stockAlerts(): HasMany
    {
        return $this->hasMany(StockAlert::class);
    }

    /**
     * Informes de novedades/fallas asociados al equipo (REQ-12, REQ-13).
     *
     * @return HasMany<IncidentReport, $this>
     */
    public function incidentReports(): HasMany
    {
        return $this->hasMany(IncidentReport::class);
    }

    public function isWarningStock(): bool
    {
        return $this->quantity <= ($this->stock_minimo + 5) && $this->quantity > $this->stock_minimo;
    }

    /**
     * Estado operativo resumido: disponible | en_reparacion | dado_de_baja | sin_stock.
     */
    public function operationalStatus(): string
    {
        if (! $this->is_active) {
            return 'dado_de_baja';
        }

        return match (true) {
            $this->quantity > 0 => 'disponible',
            $this->quantity_in_repair > 0 => 'en_reparacion',
            $this->quantity_written_off > 0 => 'dado_de_baja',
            default => 'sin_stock',
        };
    }

    public function isCriticalStock(): bool
    {
        return $this->quantity <= $this->stock_minimo;
    }
}
