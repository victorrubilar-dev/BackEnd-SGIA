<?php

namespace App\Services;

use App\Models\Product;
use App\Models\StockAlert;
use App\Models\User;
use App\Notifications\StockThresholdNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * Alertas de stock (REQ-06). La fuente de verdad es el inventario (`products.quantity` frente a
 * `stock_minimo`); la tabla `stock_alerts` solo guarda el aviso y su atención manual:
 *
 *  - crítico:     quantity <= stock_minimo
 *  - advertencia: stock_minimo < quantity <= stock_minimo + 5
 *
 * Un producto activo dentro de un umbral tiene como máximo una alerta abierta, del tipo que le
 * corresponde y con sus cifras actuales. `reconcile()` deja la tabla en ese estado.
 */
class StockAlertService
{
    public const WARNING_MARGIN = 5;

    /**
     * Revisa un producto tras un movimiento de stock y avisa al personal si queda en un umbral.
     */
    public function checkStock(Product $product): ?StockAlert
    {
        $alert = DB::transaction(fn () => $this->syncProduct($product));

        if ($alert !== null) {
            $this->notifyStaff($alert);
        }

        return $alert;
    }

    /**
     * Deja las alertas abiertas iguales al inventario real: cierra las que ya no corresponden
     * (stock repuesto, cambio de tipo, producto inactivo, duplicadas), actualiza las cifras de
     * las que siguen vigentes y abre las que faltan. No envía notificaciones.
     */
    public function reconcile(): void
    {
        DB::transaction(function () {
            $withOpenAlert = StockAlert::where('is_resolved', false)->distinct()->pluck('product_id');

            $inThreshold = Product::where('is_active', true)
                ->whereRaw('quantity <= stock_minimo + ?', [self::WARNING_MARGIN])
                ->pluck('id');

            Product::whereIn('id', $withOpenAlert->merge($inThreshold)->unique())
                ->get()
                ->each(fn (Product $product) => $this->syncProduct($product));
        });
    }

    /**
     * Atención manual: la alerta se cierra con las cifras del momento. No vuelve a abrirse
     * mientras el stock del producto no cambie (ver `acknowledged`).
     */
    public function resolve(StockAlert $alert): StockAlert
    {
        $product = $alert->product;

        $alert->forceFill([
            'is_resolved' => true,
            'current_stock' => $product->quantity ?? $alert->current_stock,
            'stock_minimo' => $product->stock_minimo ?? $alert->stock_minimo,
        ])->save();

        return $alert;
    }

    /**
     * Tipo de alerta que le corresponde hoy a un producto, o null si no está en ningún umbral.
     */
    public function levelFor(Product $product): ?string
    {
        if (! $product->is_active) {
            return null;
        }

        return match (true) {
            $product->quantity <= $product->stock_minimo => StockAlert::TYPE_CRITICAL,
            $product->quantity <= $product->stock_minimo + self::WARNING_MARGIN => StockAlert::TYPE_WARNING,
            default => null,
        };
    }

    /**
     * Sincroniza las alertas de un producto con su stock y devuelve la alerta abierta, si queda una.
     */
    private function syncProduct(Product $product): ?StockAlert
    {
        $level = $this->levelFor($product);
        $live = ['current_stock' => $product->quantity, 'stock_minimo' => $product->stock_minimo];

        $open = StockAlert::where('product_id', $product->id)
            ->where('is_resolved', false)
            ->orderByDesc('id')
            ->get();

        // Se conserva la más reciente del tipo vigente; el resto se cierra con las cifras actuales.
        $current = $level === null ? null : $open->firstWhere('alert_type', $level);

        $open->reject(fn (StockAlert $alert) => $alert->is($current))
            ->each(fn (StockAlert $alert) => $alert->forceFill(['is_resolved' => true, ...$live])->save());

        if ($level === null) {
            return null;
        }

        if ($current !== null) {
            $current->fill([...$live, 'message' => $this->message($product, $level)]);
            if ($current->isDirty()) {
                $current->save();
            }

            return $current;
        }

        if ($this->acknowledged($product, $level)) {
            return null;
        }

        return StockAlert::create([
            'product_id' => $product->id,
            'alert_type' => $level,
            'is_resolved' => false,
            ...$live,
            'message' => $this->message($product, $level),
        ]);
    }

    /**
     * La última alerta del producto se atendió a mano con este mismo tipo y estas mismas cifras:
     * no se vuelve a avisar hasta que el stock cambie. (Las cerradas por reposición guardan cifras
     * fuera del umbral, así que nunca coinciden.)
     */
    private function acknowledged(Product $product, string $level): bool
    {
        $last = StockAlert::where('product_id', $product->id)->orderByDesc('id')->first();

        return $last !== null
            && $last->is_resolved
            && $last->alert_type === $level
            && $last->current_stock === $product->quantity
            && $last->stock_minimo === $product->stock_minimo;
    }

    private function message(Product $product, string $level): string
    {
        $figures = "({$product->quantity}/{$product->stock_minimo})";

        return $level === StockAlert::TYPE_CRITICAL
            ? "Stock crítico: el producto {$product->name} ha alcanzado su stock mínimo {$figures}."
            : "Advertencia de stock: el producto {$product->name} está a 5 o menos unidades de su stock mínimo {$figures}.";
    }

    /**
     * Notify administrators (AD-01), directors (DIR-01) and warehouse keepers (PAN-01).
     */
    protected function notifyStaff(StockAlert $alert): void
    {
        $recipients = User::withRole([User::ROLE_ADMIN, User::ROLE_DIRECTOR, User::ROLE_WAREHOUSE])
            ->where('is_active', true)
            ->get();

        if ($recipients->isNotEmpty()) {
            Notification::send($recipients, new StockThresholdNotification($alert));
        }
    }
}
