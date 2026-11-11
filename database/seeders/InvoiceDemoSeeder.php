<?php

namespace Database\Seeders;

use App\Models\AnnualBudget;
use App\Models\Career;
use App\Models\Category;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\QuotationSupplier;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CodeSequenceService;
use App\Services\PurchaseService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Datos de pruebas-facturas/prueba1..5: proveedores, productos, presupuestos y las 5 órdenes de compra
 * (pendientes) que esas facturas/OC deberían asociar. Más cotizaciones para probar "OC desde 3 cotizaciones".
 * Uso: php artisan db:seed --class=InvoiceDemoSeeder (después de DemoMetricsSeeder).
 */
class InvoiceDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            return;
        }

        $admin = User::where('email', 'admin@sgia.cl')->firstOrFail();
        $codes = app(CodeSequenceService::class);
        $careers = Career::pluck('id', 'code');

        // Proveedores con área (≥3 activos por área) para probar también el flujo de solicitud de compra.
        $sup = DemoSupplierSeeder::suppliers();

        $cat = fn (string $n) => Category::firstOrCreate(['name' => $n])->id;

        // clave => [nombre, tipo, carrera, categoría, proveedor, stock mínimo, precio unitario de la factura/OC]
        $catalog = [
            'aaa' => ['Pila Duracell AAA alcalina 2400', 'insumo', 'AP', 'Electricidad', 'electroplus', 10, 1190],
            'aa' => ['Pila Duracell AA alcalina tira x 12', 'insumo', 'AP', 'Electricidad', 'electroplus', 10, 1200],
            'carton' => ['Cartón corrugado 1,2x5', 'insumo', 'II', 'Audiovisual', 'arbol', 2, 12370],
            'guantes' => ['Guantes de seguridad Ansell Nitrilo Hyflex 11-800', 'insumo', 'ISD', 'Seguridad', 'systelec', 20, 4632],
            'gafas' => ['Gafas de seguridad Raptor Tech claras', 'insumo', 'ISD', 'Seguridad', 'systelec', 10, 620],
            'cinta' => ['Cinta seguridad peligro amarilla-negra 100 m x 48 mm', 'insumo', 'ISD', 'Seguridad', 'systelec', 2, 3468],
            'tarjeta' => ['Tarjeta de interconexión Modbus TCP a Profibus DP GCA-EN-024806', 'equipo', 'AP', 'Electrónica', 'zenner', 1, 3787000],
            'valvula' => ['Válvula on/off con switch posición (bola 1 cuerpo, acero carbono)', 'equipo', 'AP', 'Electrónica', 'microtec', 1, 2390500],
        ];

        $products = collect($catalog)->map(function ($d) use ($codes, $careers, $cat, $sup, $admin) {
            [$name, $type, $career, $category, $supplier, $min, $price] = $d;

            return Product::firstOrCreate(['name' => $name], [
                'barcode' => $codes->forType($type, $careers[$career]),
                'description' => $name,
                'type' => $type,
                'quantity' => 0,
                'stock_minimo' => $min,
                'supplier_id' => $sup[$supplier]->id,
                'area' => $category,
                'category_id' => $cat($category),
                'career_id' => $careers[$career],
                'reference_price' => $price,
                'is_active' => true,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
        });

        // Presupuestos 2026 por carrera (el de AP cubre las OC de mayor monto).
        $budgets = collect(['AP' => 15_000_000, 'II' => 5_000_000, 'IC' => 5_000_000, 'ISD' => 5_000_000])
            ->map(fn ($amount, $code) => AnnualBudget::firstOrCreate(
                ['career_id' => $careers[$code], 'year' => 2026],
                ['initial_amount' => $amount, 'available_amount' => $amount, 'reserved_amount' => 0, 'created_by' => $admin->id, 'updated_by' => $admin->id]
            ));

        $purchases = app(PurchaseService::class);

        // [proveedor, presupuesto, referencia, esperada, notas, [[producto, cantidad], ...]]
        $orders = [
            ['electroplus', 'AP', 'OC-ELECTROPLUS-1111', '2026-06-10', 'prueba1: Factura 1111 Electroplus ($59.800).', [['aaa', 20], ['aa', 30]]],
            ['arbol', 'II', 'IP10909', '2026-08-07', 'prueba2: Factura 208 Árbolvisual ($24.740 neto).', [['carton', 2]]],
            ['systelec', 'ISD', 'IP860', '2026-05-28', 'prueba3: Factura 20050 Systelec ($257.872 neto).', [['guantes', 50], ['gafas', 20], ['cinta', 4]]],
            ['zenner', 'AP', 'IP8344', '2026-09-09', 'prueba4: OC IP8344, cotización 9137. Garantía 12 meses.', [['tarjeta', 1]]],
            ['microtec', 'AP', 'IPN614947', '2026-05-29', 'prueba5: OC IPN614947, cotización 28042025.', [['valvula', 1]]],
        ];

        foreach ($orders as [$supplier, $budget, $ref, $expected, $notes, $lines]) {
            $purchases->createReserved([
                'supplier_id' => $sup[$supplier]->id,
                'annual_budget_id' => $budgets[$budget]->id,
                'quotation_reference' => $ref,
                'expected_at' => $expected,
                'notes' => $notes,
                'items' => collect($lines)->map(fn ($l) => [
                    'product_id' => $products[$l[0]]->id,
                    'name' => $products[$l[0]]->name,
                    'quantity' => $l[1],
                    'unit_price' => $catalog[$l[0]][6],
                ])->all(),
            ], $admin);
        }

        // Cotizaciones para probar la OC desde >=3 aceptadas: 3 aceptadas (3 proveedores con oferta),
        // 1 pendiente y 1 rechazada. Compran insumos de pañol.
        $offered = [['electroplus', 1.0], ['systelec', 0.97], ['arbol', 1.05]];
        foreach ([['Reposición de pilas', 'aaa', 40], ['Reposición de pilas AA', 'aa', 60], ['Reposición de EPP', 'guantes', 30]] as $i => [$title, $key, $qty]) {
            $this->quotation($admin, $title, $products[$key], $qty, $sup, $offered, $catalog[$key][6], Quotation::STATUS_ACCEPTED, $i === 0);
        }
        $this->quotation($admin, 'Cintas de seguridad (pendiente)', $products['cinta'], 10, $sup, $offered, 3468, Quotation::STATUS_PENDING, false);
        $this->quotation($admin, 'Gafas de seguridad (rechazada)', $products['gafas'], 40, $sup, $offered, 620, Quotation::STATUS_REJECTED, false);
    }

    /** @param Collection<string, Supplier> $sup */
    private function quotation(User $admin, string $title, Product $product, int $qty, $sup, array $offered, int $price, string $status, bool $first): void
    {
        $q = Quotation::create([
            'code' => Quotation::generateUniqueCode(),
            'created_by' => $admin->id,
            'status' => $status,
            'title' => $title,
            'notes' => 'Dato de demostración',
            'expires_at' => '2026-12-31',
        ]);
        $q->items()->create(['product_id' => $product->id, 'name' => $product->name, 'quantity' => $qty]);

        foreach ($offered as [$key, $factor]) {
            $responded = $status !== Quotation::STATUS_PENDING;
            $contact = $q->suppliers()->create([
                'supplier_id' => $sup[$key]->id,
                'status' => match ($status) {
                    Quotation::STATUS_ACCEPTED => QuotationSupplier::STATUS_ACCEPTED,
                    Quotation::STATUS_REJECTED => QuotationSupplier::STATUS_REJECTED,
                    default => QuotationSupplier::STATUS_PENDING,
                },
                'offer_total' => $responded ? round($price * $factor) * $qty : null,
                'responded_at' => $responded ? now() : null,
            ]);
            if ($responded) {
                $contact->offerItems()->create([
                    'product_id' => $product->id,
                    'requested_quantity' => $qty,
                    'offered_unit_price' => round($price * $factor),
                ]);
            }
        }
    }
}
