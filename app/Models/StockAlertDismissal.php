<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerta de stock que un usuario quitó de su campana (docs/exportaciones-y-campana.md).
 */
class StockAlertDismissal extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'stock_alert_id'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<StockAlert, $this> */
    public function stockAlert(): BelongsTo
    {
        return $this->belongsTo(StockAlert::class);
    }
}
