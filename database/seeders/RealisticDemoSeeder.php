<?php

namespace Database\Seeders;

use App\Models\AnnualBudget;
use App\Models\Cajon;
use App\Models\Career;
use App\Models\Category;
use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\InvoiceIntake;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Location;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\QuotationSupplier;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Services\AnnualBudgetService;
use App\Services\CodeSequenceService;
use App\Services\EquipmentUnitService;
use App\Services\InvoiceIntakeService;
use App\Services\PurchaseService;
use App\Services\StockAlertService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Datos de demostración realistas para presentar el sistema completo (reemplaza a DemoMetricsSeeder + InvoiceDemoSeeder).
 * Deja SIN procesar la factura 1 (prueba1.jpeg, Electroplus): su OC de pilas queda pendiente y la factura sin subir.
 * Uso: php artisan migrate:fresh --seed --seeder=RealisticDemoSeeder   (destruye la BD local)
 */
class RealisticDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Datos de demostración deshabilitados en producción.');

            return;
        }

        mt_srand(2026);
        $this->call(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@sgia.cl')->firstOrFail();
        $director = User::where('email', 'director@sgia.cl')->firstOrFail();
        $panolero = User::where('email', 'panol@sgia.cl')->firstOrFail();
        $password = Hash::make(config('sgia.seed_admin_password') ?? 'Admin1234!');
        $careers = Career::pluck('id', 'code');
        $director->careers()->syncWithoutDetaching($careers->values()->all());

        [$locations, $cajones] = $this->places($admin);
        $suppliers = $this->suppliers();
        $products = $this->catalog($admin, $careers, $suppliers, $locations, $cajones);
        $budgets = $this->budgets($admin, $careers);
        $teachers = $this->teachers($password, $careers);
        $students = $this->students($careers);

        $this->purchasing($admin, $director, $suppliers, $products, $budgets);
        $this->loans($panolero, $teachers, $students, $products, $locations, $careers);
        $this->maintenance($panolero, $suppliers);
        $this->incidents($teachers, $locations, $products);

        $this->command?->info(sprintf(
            'Demo realista: %d productos, %d unidades, %d préstamos, %d OC, %d solicitudes de compra, %d facturas, %d alertas. Factura 1 (Electroplus) pendiente.',
            Product::count(), EquipmentUnit::count(), Loan::count(), Purchase::count(), PurchaseRequest::count(), InvoiceIntake::count(), DB::table('stock_alerts')->count()
        ));
    }

    /** @return array{0: Collection, 1: Collection} */
    private function places(User $admin): array
    {
        $extra = [
            ['Laboratorio de Redes R-204', Location::TIPO_SALA, 'Laboratorio de Redes y Telecomunicaciones', '2', 2, ['Rack-1', 'Rack-2', 'Armario-Redes']],
            ['Laboratorio de Ciberseguridad C-105', Location::TIPO_SALA, 'Laboratorio de Ciberseguridad y Hacking Ético', '1', 1, ['Mesa-A', 'Mesa-B', 'Caja-Fuerte-Equipos']],
            ['Sala de Computación S-210', Location::TIPO_SALA, 'Sala de Programación y Desarrollo de Software', '2', 2, ['Armario-Notebooks']],
            ['Bodega de Insumos', Location::TIPO_BODEGA, 'Bodega de insumos y material de reposición', '1', 1, ['Estante-I1', 'Estante-I2', 'Estante-I3']],
        ];
        foreach ($extra as [$name, $type, $desc, $building, $floor, $drawers]) {
            $loc = Location::firstOrCreate(['nombre' => $name], [
                'tipo' => $type, 'descripcion' => $desc, 'building' => $building, 'floor' => $floor,
                'created_by' => $admin->id, 'updated_by' => $admin->id,
            ]);
            foreach ($drawers as $code) {
                Cajon::firstOrCreate(['location_id' => $loc->id, 'codigo' => $code], [
                    'descripcion' => "{$code} en {$name}", 'created_by' => $admin->id, 'updated_by' => $admin->id,
                ]);
            }
        }

        return [Location::with('cajones')->get()->keyBy('nombre'), Cajon::all()->keyBy('codigo')];
    }

    /** Proveedores de demostración con sus áreas (≥3 activos por área). */
    private function suppliers()
    {
        return DemoSupplierSeeder::suppliers();
    }

    private function catalog(User $admin, $careers, $suppliers, $locations, $cajones)
    {
        $codes = app(CodeSequenceService::class);
        $units = app(EquipmentUnitService::class);
        $cat = fn (string $n) => Category::firstOrCreate(['name' => $n])->id;

        // clave => [nombre, tipo, carrera, categoría, proveedor, cantidad/unidades, stock mínimo, precio, ubicación, cajón, marca-serie]
        $rows = [
            'multimetro' => ['Multímetro digital Fluke 117', 'equipo', 'AP', 'Electricidad', 'systelec', 10, 3, 189000, 'Pañol Central', 'Cajon-A1', 'FLK'],
            'osciloscopio' => ['Osciloscopio Rigol DS1054Z', 'equipo', 'ISD', 'Electrónica', 'microtec', 6, 2, 420000, 'Laboratorio E-301', 'Mesa-1-Cajon', 'RGL'],
            'fuente' => ['Fuente de poder variable 30V 5A', 'equipo', 'ISD', 'Electrónica', 'systelec', 6, 2, 135000, 'Laboratorio E-301', 'Mesa-2-Cajon', 'FPV'],
            'notebook' => ['Notebook Lenovo ThinkPad E14', 'equipo', 'II', 'Computación', 'compintegral', 12, 3, 749900, 'Sala de Computación S-210', 'Armario-Notebooks', 'TPE'],
            'router' => ['Router Cisco ISR 2901', 'equipo', 'ISD', 'Redes', 'redsur', 6, 2, 520000, 'Laboratorio de Redes R-204', 'Rack-1', 'CSC'],
            'switch' => ['Switch Cisco Catalyst 2960 24p', 'equipo', 'ISD', 'Redes', 'redsur', 8, 2, 380000, 'Laboratorio de Redes R-204', 'Rack-2', 'CAT'],
            'crimpadora' => ['Crimpadora RJ45 profesional', 'equipo', 'ISD', 'Redes', 'redsur', 8, 2, 28900, 'Pañol Central', 'Gaveta-01', 'CRP'],
            'raspberry' => ['Raspberry Pi 4 Model B 4GB', 'equipo', 'IC', 'Electrónica', 'ciberlab', 10, 3, 95000, 'Laboratorio de Ciberseguridad C-105', 'Mesa-A', 'RPI'],
            'pineapple' => ['WiFi Pineapple Mark VII', 'equipo', 'IC', 'Ciberseguridad', 'ciberlab', 4, 1, 210000, 'Laboratorio de Ciberseguridad C-105', 'Caja-Fuerte-Equipos', 'WPM'],
            'proyector' => ['Proyector Epson PowerLite X49', 'equipo', 'II', 'Audiovisual', 'compintegral', 4, 1, 450000, 'Pañol Central', 'Estante-E1', 'EPS'],
            'arduino' => ['Kit Arduino Uno R3', 'equipo', 'AP', 'Electrónica', 'systelec', 15, 3, 32000, 'Pañol Central', 'Cajon-A2', 'ARD'],
            'maq_lan' => ['Maqueta de red LAN empresarial', 'maqueta', 'ISD', 'Redes', 'redsur', 4, 1, 150000, 'Laboratorio de Redes R-204', 'Armario-Redes', 'MLN'],
            'maq_torre' => ['Maqueta de torre de telecomunicaciones', 'maqueta', 'ISD', 'Electrónica', 'redsur', 3, 1, 180000, 'Laboratorio de Redes R-204', 'Armario-Redes', 'MTT'],
            'maq_dc' => ['Maqueta de centro de datos', 'maqueta', 'II', 'Computación', 'compintegral', 3, 1, 220000, 'Sala de Computación S-210', 'Armario-Notebooks', 'MDC'],
            'maq_fw' => ['Maqueta de firewall perimetral', 'maqueta', 'IC', 'Ciberseguridad', 'ciberlab', 3, 1, 175000, 'Laboratorio de Ciberseguridad C-105', 'Mesa-B', 'MFW'],
            'tarjeta' => ['Tarjeta de interconexión Modbus TCP a Profibus DP GCA-EN-024806', 'equipo', 'AP', 'Electrónica', 'zenner', 0, 1, 3787000, 'Pañol Central', 'Cajon-A1', 'TMB'],
            'valvula' => ['Válvula on/off con switch posición (bola 1 cuerpo, acero carbono)', 'equipo', 'AP', 'Electrónica', 'microtec', 0, 1, 2390500, 'Pañol Central', 'Cajon-A1', 'VLV'],
            // Insumos: la cantidad es el stock actual.
            'utp' => ['Cable UTP Cat6 (metro)', 'insumo', 'ISD', 'Redes', 'redsur', 420, 100, 650, 'Bodega de Insumos', 'Estante-I1', null],
            'rj45' => ['Conectores RJ45 Cat6 (unidad)', 'insumo', 'ISD', 'Redes', 'redsur', 260, 80, 250, 'Bodega de Insumos', 'Estante-I1', null],
            'consola' => ['Cable de consola RJ45-USB', 'insumo', 'ISD', 'Redes', 'redsur', 5, 5, 7800, 'Bodega de Insumos', 'Estante-I1', null],
            'estano' => ['Estaño 60/40 (rollo 100 g)', 'insumo', 'AP', 'Electrónica', 'systelec', 14, 5, 9500, 'Bodega de Insumos', 'Estante-I2', null],
            'resistencias' => ['Resistencias 1/4W surtidas (pack 100)', 'insumo', 'AP', 'Electrónica', 'systelec', 55, 20, 1800, 'Bodega de Insumos', 'Estante-I2', null],
            'protoboard' => ['Protoboard 830 puntos', 'insumo', 'AP', 'Electrónica', 'systelec', 24, 10, 4200, 'Bodega de Insumos', 'Estante-I2', null],
            'fusibles' => ['Fusibles 5A (caja 100)', 'insumo', 'AP', 'Electricidad', 'electroplus', 22, 8, 2900, 'Bodega de Insumos', 'Estante-I3', null],
            'aislante' => ['Cinta aislante negra 18 mm', 'insumo', 'II', 'Electricidad', 'electroplus', 34, 10, 1200, 'Bodega de Insumos', 'Estante-I3', null],
            'pasta' => ['Pasta térmica (jeringa 4 g)', 'insumo', 'II', 'Computación', 'compintegral', 7, 6, 3500, 'Bodega de Insumos', 'Estante-I2', null],
            'pendrive' => ['Pendrive 32GB (arranque forense)', 'insumo', 'IC', 'Ciberseguridad', 'ciberlab', 16, 6, 5900, 'Bodega de Insumos', 'Estante-I2', null],
            'tornillos' => ['Tornillos M3 (caja 200)', 'insumo', 'IC', 'Electricidad', 'electroplus', 6, 6, 2200, 'Bodega de Insumos', 'Estante-I3', null],
            // Insumos de las facturas de prueba. Pilas AAA/AA: stock 0 hasta ingresar la factura 1.
            'aaa' => ['Pila Duracell AAA alcalina 2400', 'insumo', 'AP', 'Electricidad', 'electroplus', 0, 10, 1190, 'Bodega de Insumos', 'Estante-I3', null],
            'aa' => ['Pila Duracell AA alcalina tira x 12', 'insumo', 'AP', 'Electricidad', 'electroplus', 0, 10, 1200, 'Bodega de Insumos', 'Estante-I3', null],
            'carton' => ['Cartón corrugado 1,2x5', 'insumo', 'II', 'Audiovisual', 'arbol', 0, 2, 12370, 'Bodega de Insumos', 'Estante-I1', null],
            'guantes' => ['Guantes de seguridad Ansell Nitrilo Hyflex 11-800', 'insumo', 'ISD', 'Seguridad', 'systelec', 0, 20, 4632, 'Bodega de Insumos', 'Estante-I3', null],
            'gafas' => ['Gafas de seguridad Raptor Tech claras', 'insumo', 'ISD', 'Seguridad', 'systelec', 0, 10, 620, 'Bodega de Insumos', 'Estante-I3', null],
            'cinta' => ['Cinta seguridad peligro amarilla-negra 100 m x 48 mm', 'insumo', 'ISD', 'Seguridad', 'systelec', 0, 2, 3468, 'Bodega de Insumos', 'Estante-I3', null],
        ];

        return collect($rows)->map(function ($d) use ($codes, $units, $cat, $admin, $careers, $suppliers, $locations, $cajones) {
            [$name, $type, $career, $category, $supplier, $qty, $min, $price, $place, $drawer, $serialPrefix] = $d;
            $hasUnits = $type !== Product::TYPE_SUPPLY;
            $cajon = $cajones[$drawer];

            $product = Product::create([
                'barcode' => $codes->forType($type, $careers[$career]),
                'name' => $name, 'description' => $name, 'type' => $type,
                'quantity' => $hasUnits ? 0 : $qty, 'stock_minimo' => $min,
                'supplier_id' => $suppliers[$supplier]->id,
                'cajon_id' => $cajon->id, 'location_id' => $locations[$place]->id,
                'area' => $category, 'category_id' => $cat($category), 'career_id' => $careers[$career],
                'reference_price' => $price,
                'maintenance_priority' => $hasUnits ? (['equipo' => 'A', 'maqueta' => 'C'][$type]) : 'C',
                'requires_calibration' => in_array($name, ['Osciloscopio Rigol DS1054Z', 'Multímetro digital Fluke 117', 'Fuente de poder variable 30V 5A'], true),
                'maintenance_frequency_months' => $hasUnits ? ($type === 'equipo' ? 6 : 12) : null,
                'is_active' => true, 'created_by' => $admin->id, 'updated_by' => $admin->id,
            ]);

            if ($hasUnits && $qty > 0) {
                $serials = array_map(fn ($i) => sprintf('%s-%04d%s', $serialPrefix, mt_rand(1000, 9999), chr(65 + $i % 26)), range(0, $qty - 1));
                $units->createUnits($product, $qty, ['cajon_id' => $cajon->id], $admin->id, $serials);
            }

            return $product->fresh();
        });
    }

    private function budgets(User $admin, $careers)
    {
        return collect(['AP' => 15_000_000, 'II' => 8_000_000, 'IC' => 9_000_000, 'ISD' => 12_000_000])
            ->map(fn ($amount, $code) => AnnualBudget::create([
                'career_id' => $careers[$code], 'year' => 2026,
                'initial_amount' => $amount, 'available_amount' => $amount, 'reserved_amount' => 0,
                'created_by' => $admin->id, 'updated_by' => $admin->id,
            ]));
    }

    private function teachers(string $password, $careers)
    {
        $names = Career::pluck('name', 'code');

        return collect([
            ['Carlos Muñoz Henríquez', 'ISD'], ['Patricia Soto Alarcón', 'II'], ['Andrés Rojas Quezada', 'IC'],
            ['Daniela Fuentes Maturana', 'AP'], ['Felipe Vargas Cifuentes', 'II'], ['Camila Reyes Painemal', 'IC'],
        ])->map(fn ($t, $i) => User::updateOrCreate(
            ['email' => 'docente'.($i + 1).'@sgia.cl'],
            ['name' => $t[0], 'password' => $password, 'role' => User::ROLE_TEACHER, 'area' => $names[$t[1]], 'is_active' => true]
        )->setRelation('careerCode', $t[1]));
    }

    private function students($careers)
    {
        return collect([
            ['21345678-9', 'Matías Parra Lagos', 'IC'], ['20987654-3', 'Sofía Contreras Díaz', 'II'],
            ['21111222-K', 'Benjamín Navarro Silva', 'AP'], ['20555444-1', 'Valentina Ortiz Ruiz', 'ISD'],
            ['21666777-5', 'Joaquín Mella Soto', 'IC'], ['20222333-7', 'Antonia Vega Cid', 'AP'],
            ['21098765-4', 'Ignacio Carrasco Leal', 'ISD'], ['20876543-2', 'Francisca Neira Millar', 'II'],
            ['21234987-6', 'Tomás Painemal Huenchullán', 'IC'], ['20765432-1', 'Javiera Alarcón Pino', 'AP'],
        ])->map(fn ($s) => Student::firstOrCreate(['rut' => $s[0]], [
            'full_name' => $s[1], 'email' => strtolower(explode(' ', $s[1])[0]).'.'.strtolower(explode(' ', $s[1])[1]).'@alumnos.inacap.cl',
            'career_id' => $careers[$s[2]], 'registered_at' => now()->subDays(mt_rand(30, 200)),
        ]));
    }

    private function purchasing(User $admin, User $director, $suppliers, $products, $budgets): void
    {
        $purchases = app(PurchaseService::class);
        $intakes = app(InvoiceIntakeService::class);

        $price = fn (string $k) => (float) $products[$k]->reference_price;
        $lines = fn (array $l) => collect($l)->map(fn ($x) => [
            'product_id' => $products[$x[0]]->id, 'name' => $products[$x[0]]->name, 'quantity' => $x[1], 'unit_price' => $price($x[0]),
        ])->all();
        $makeOc = function (string $sup, string $budget, string $ref, string $expected, string $notes, array $l, ?int $daysAgo = null) use ($purchases, $suppliers, $budgets, $admin, $lines) {
            $p = $purchases->createReserved([
                'supplier_id' => $suppliers[$sup]->id, 'annual_budget_id' => $budgets[$budget]->id,
                'quotation_reference' => $ref, 'expected_at' => $expected, 'notes' => $notes, 'items' => $lines($l),
            ], $admin);
            if ($daysAgo !== null) {
                $p->forceFill(['created_at' => now()->subDays($daysAgo)])->saveQuietly();
            }

            return $p;
        };
        $confirm = function (Purchase $p, string $file, string $number, array $l, int $daysAgo) use ($intakes, $products, $price, $admin) {
            $path = 'invoice-intakes/'.basename($file);
            Storage::disk('local')->put($path, file_get_contents(base_path("pruebas-facturas/{$file}")));
            $intake = InvoiceIntake::create([
                'user_id' => $admin->id, 'annual_budget_id' => $p->annual_budget_id, 'purchase_id' => $p->id,
                'pdf_path' => $path, 'document_number' => $number, 'status' => InvoiceIntake::STATUS_DRAFT,
                'raw_ocr_payload' => ['invoice_number' => $number, 'supplier_id' => $p->supplier_id, 'items' => [], 'ocr_available' => true],
            ]);
            $intakes->confirm($intake, $number, array_map(fn ($x) => [
                'product_id' => $products[$x[0]]->id, 'quantity' => $x[1], 'unit_price' => $price($x[0]),
            ], $l), $admin->id);
            $when = now()->subDays($daysAgo);
            $intake->forceFill(['created_at' => $when, 'updated_at' => $when, 'received_at' => $when])->saveQuietly();
            $p->refresh()->forceFill(['received_at' => $when])->saveQuietly();
            DB::table('budget_movements')->where('invoice_intake_id', $intake->id)->update(['created_at' => $when]);
        };

        // Ya facturadas (prueba2 y prueba3): stock ingresado, reserva liberada, presupuesto consumido.
        $oc2 = $makeOc('arbol', 'II', 'IP10909', now()->subDays(60)->toDateString(), 'Cartón para maquetas de Informática. Factura 208 Árbolvisual.', [['carton', 2]], 70);
        $confirm($oc2, 'prueba2.jpeg', '208', [['carton', 2]], 55);
        $oc3 = $makeOc('systelec', 'ISD', 'IP860', now()->subDays(40)->toDateString(), 'Equipos de protección personal para talleres. Factura 20050 Systelec.', [['guantes', 50], ['gafas', 20], ['cinta', 4]], 48);
        $confirm($oc3, 'prueba3.jpeg', '20050', [['guantes', 50], ['gafas', 20], ['cinta', 4]], 33);

        // Factura 1 (prueba1.jpeg): OC pendiente, sin factura. Es la que se presenta en la demostración.
        $makeOc('electroplus', 'AP', 'OC-ELECTROPLUS-1111', now()->addDays(5)->toDateString(), 'Pilas para multímetros y kits Arduino. Factura 1111 Electroplus ($59.800).', [['aaa', 20], ['aa', 30]], 6);

        // OC de INACAP (prueba4 y prueba5): una en camino y otra recién emitida.
        $oc4 = $makeOc('zenner', 'AP', 'IP8344', now()->addDays(14)->toDateString(), 'Cotización 9137. Garantía 12 meses. Para taller de automatización.', [['tarjeta', 1]], 12);
        $purchases->transition($oc4, Purchase::STATUS_IN_TRANSIT);
        $makeOc('microtec', 'AP', 'IPN614947', now()->addDays(21)->toDateString(), 'Cotización 28042025. Válvula para banco de pruebas de instrumentación.', [['valvula', 1]], 3);

        // OC anulada (libera la reserva).
        $cancelled = $makeOc('redsur', 'ISD', 'RDS-0457', now()->subDays(10)->toDateString(), 'Conectores y cable UTP. Se anula: el proveedor no tenía stock.', [['utp', 300], ['rj45', 200]], 25);
        $purchases->cancel($cancelled, $admin);

        $this->quotations($admin, $suppliers, $products);
        $this->purchaseRequests($director, $suppliers, $products, $budgets);
    }

    /**
     * Solicitudes de compra del flujo nuevo, una por estado. Se arman directo en la BD (sin enviar correos).
     * Las cotizaciones y la OC tienen un PDF de muestra en el disco privado.
     */
    private function purchaseRequests(User $director, $suppliers, $products, $budgets): void
    {
        $codes = app(CodeSequenceService::class);
        $budgetService = app(AnnualBudgetService::class);
        $pdf = "%PDF-1.4\n% Documento de demostración SGIA\n%%EOF\n";

        // [estado, presupuesto, notas, [[producto, cantidad]], áreas, [[proveedor, factor de precio]], índice elegido, días atrás]
        $defs = [
            [PurchaseRequest::STATUS_DRAFT, 'ISD', 'Cable de consola para el laboratorio de redes.', [['consola', 15]], [], [], null, 1],
            [PurchaseRequest::STATUS_APPROVED, 'II', 'Pasta térmica para la mantención de notebooks.', [['pasta', 20]], [], [], null, 3],
            [PurchaseRequest::STATUS_SENT, 'ISD', 'Conectores RJ45 y cable UTP para el semestre.', [['utp', 200], ['rj45', 150]], ['telecomunicaciones'], [], null, 6],
            [PurchaseRequest::STATUS_QUOTED, 'IC', 'Pendrives de arranque forense.', [['pendrive', 30]], ['ciberseguridad', 'informatica'], [['ciberlab', 1.15], ['compintegral', 1.1], ['systelec', 1.2]], 1, 9],
            [PurchaseRequest::STATUS_ORDERED, 'AP', 'Reposición de pilas AAA para multímetros.', [['aaa', 40]], ['telecomunicaciones'], [['electroplus', 1.0], ['systelec', 0.97], ['microtec', 1.05]], 0, 14],
            [PurchaseRequest::STATUS_CANCELLED, 'ISD', 'Switches para ampliar el laboratorio (se posterga al próximo año).', [['switch', 4]], [], [], null, 20],
        ];

        foreach ($defs as [$status, $budget, $notes, $lines, $areas, $quotes, $selected, $daysAgo]) {
            $when = now()->subDays($daysAgo);
            $approvedAt = $status === PurchaseRequest::STATUS_DRAFT ? null : $when->copy()->addHours(2);
            $request = PurchaseRequest::create([
                'code' => $codes->forPurchaseRequest(), 'status' => $status, 'annual_budget_id' => $budgets[$budget]->id,
                'notes' => $notes, 'created_by' => $director->id, 'updated_by' => $director->id,
                'approved_by' => $approvedAt ? $director->id : null,
                'approved_at' => $approvedAt,
                'sent_at' => $areas === [] ? null : $when->copy()->addDay(),
            ]);
            $request->forceFill(['created_at' => $when])->saveQuietly();
            $items = collect($lines)->map(fn ($l) => $request->items()->create(['product_id' => $products[$l[0]]->id, 'quantity' => $l[1]]));

            foreach ($areas as $area) {
                $request->areas()->create(['area' => $area]);
            }
            if ($areas !== []) {
                foreach (Supplier::where('is_active', true)->inAreas($areas)->pluck('id') as $supplierId) {
                    $request->suppliers()->create(['supplier_id' => $supplierId, 'email_sent_at' => $when->copy()->addDay()]);
                }
            }

            $quotationIds = [];
            foreach ($quotes as $i => [$sup, $factor]) {
                $request->suppliers()->firstOrCreate(['supplier_id' => $suppliers[$sup]->id], ['email_sent_at' => $when->copy()->addDay()]);
                $path = "purchase-requests/quotations/demo-{$request->code}-{$sup}.pdf";
                Storage::disk('local')->put($path, $pdf);
                $prices = $items->map(fn ($item) => round($products->firstWhere('id', $item->product_id)->reference_price * $factor));
                $quotation = $request->quotations()->create([
                    'supplier_id' => $suppliers[$sup]->id, 'file_path' => $path, 'original_name' => "cotizacion-{$sup}.pdf",
                    'total' => $items->values()->sum(fn ($item, $k) => $prices[$k] * $item->quantity),
                    'received_at' => $when->copy()->addDays(2 + $i), 'uploaded_by' => $director->id,
                ]);
                foreach ($items->values() as $k => $item) {
                    $quotation->items()->create(['purchase_request_item_id' => $item->id, 'unit_price' => $prices[$k]]);
                }
                $quotationIds[] = $quotation->id;
            }

            if ($selected !== null) {
                $request->forceFill(['selected_quotation_id' => $quotationIds[$selected]])->saveQuietly();
            }

            if ($status === PurchaseRequest::STATUS_ORDERED) {
                $chosen = $request->selectedQuotation()->with('items')->firstOrFail();
                $path = "purchase-requests/orders/demo-{$request->code}.pdf";
                Storage::disk('local')->put($path, $pdf);
                $purchase = Purchase::create([
                    'code' => Purchase::generateUniqueCode(), 'supplier_id' => $chosen->supplier_id, 'created_by' => $director->id,
                    'annual_budget_id' => $request->annual_budget_id, 'purchase_request_id' => $request->id,
                    'order_number' => '4500'.str_pad((string) $request->id, 6, '0', STR_PAD_LEFT), 'order_file_path' => $path,
                    'status' => Purchase::STATUS_PENDING, 'quotation_reference' => $request->code, 'notes' => $notes,
                    'total' => $chosen->total, 'expected_at' => now()->addDays(10)->toDateString(),
                ]);
                foreach ($items as $item) {
                    $purchase->items()->create([
                        'product_id' => $item->product_id, 'name' => $products->firstWhere('id', $item->product_id)->name,
                        'quantity' => $item->quantity, 'unit_price' => $chosen->items->firstWhere('purchase_request_item_id', $item->id)?->unit_price,
                    ]);
                }
                $budgetService->reserve($budgets[$budget]->refresh(), $chosen->total, $purchase, $director->id);
            }
        }
    }

    private function quotations($admin, $suppliers, $products): void
    {
        // [título, estado, producto, cantidad, [[proveedor, factor de precio] ...]] — sin ofertas si está pendiente.
        $offers = [['redsur', 1.0], ['systelec', 0.97], ['compintegral', 1.05]];
        /** @var list<array{string, string, string, int, list<array{string, float}>}> $defs */
        $defs = [
            ['Reposición de pilas AAA', Quotation::STATUS_ACCEPTED, 'aaa', 40, [['electroplus', 1.0], ['systelec', 0.97], ['arbol', 1.05]]],
            ['Reposición de pilas AA', Quotation::STATUS_ACCEPTED, 'aa', 60, [['electroplus', 1.0], ['systelec', 0.97], ['arbol', 1.05]]],
            ['Reposición de guantes de seguridad', Quotation::STATUS_ACCEPTED, 'guantes', 30, [['electroplus', 1.0], ['systelec', 0.97], ['arbol', 1.05]]],
            ['Cable de consola para laboratorio de redes', Quotation::STATUS_PENDING, 'consola', 15, $offers],
            ['Pasta térmica para mantención de notebooks', Quotation::STATUS_PENDING, 'pasta', 20, $offers],
            ['Pendrives de arranque forense', Quotation::STATUS_REJECTED, 'pendrive', 40, [['ciberlab', 1.15], ['compintegral', 1.1], ['systelec', 1.2]]],
            ['Switches Catalyst para ampliar laboratorio', Quotation::STATUS_REJECTED, 'switch', 4, $offers],
        ];

        foreach ($defs as $i => [$title, $status, $key, $qty, $contacted]) {
            $product = $products[$key];
            $q = Quotation::create([
                'code' => Quotation::generateUniqueCode(), 'created_by' => $admin->id, 'status' => $status,
                'title' => $title, 'notes' => 'Solicitada por el Área de Informática y Ciberseguridad.',
                'expires_at' => now()->addDays(30 + $i)->toDateString(),
            ]);
            $q->forceFill(['created_at' => now()->subDays(20 - $i * 2)])->saveQuietly();
            $q->items()->create(['product_id' => $product->id, 'name' => $product->name, 'quantity' => $qty]);

            foreach ($contacted as [$sup, $factor]) {
                $responded = $status !== Quotation::STATUS_PENDING;
                $unit = round($product->reference_price * $factor);
                $contact = $q->suppliers()->create([
                    'supplier_id' => $suppliers[$sup]->id,
                    'status' => match ($status) {
                        Quotation::STATUS_ACCEPTED => QuotationSupplier::STATUS_ACCEPTED,
                        Quotation::STATUS_REJECTED => QuotationSupplier::STATUS_REJECTED,
                        default => QuotationSupplier::STATUS_PENDING,
                    },
                    'offer_total' => $responded ? $unit * $qty : null,
                    'responded_at' => $responded ? now()->subDays(15 - $i) : null,
                ]);
                if ($responded) {
                    $contact->offerItems()->create(['product_id' => $product->id, 'requested_quantity' => $qty, 'offered_unit_price' => $unit]);
                }
            }
        }
    }

    private function loans(User $panolero, $teachers, $students, $products, $locations, $careers): void
    {
        $subjects = [
            'ISD' => ['Redes de Computadores', 'Telecomunicaciones I', 'Cableado Estructurado'],
            'II' => ['Infraestructura de TI', 'Administración de Sistemas', 'Gestión de Proyectos TI'],
            'IC' => ['Ciberseguridad Aplicada', 'Hacking Ético', 'Análisis Forense'],
            'AP' => ['Electrónica Digital', 'Programación de Dispositivos', 'Instalaciones Eléctricas'],
        ];
        $rooms = $locations->filter(fn ($l) => $l->tipo === Location::TIPO_SALA)->values();
        $blocks = [['08:30', '10:00', 'Bloque 1-2'], ['10:15', '11:45', 'Bloque 3-4'], ['14:00', '15:30', 'Bloque 5-6'], ['15:45', '17:15', 'Bloque 7-8']];
        $loanable = $products->filter(fn ($p) => $p->type !== Product::TYPE_SUPPLY || in_array($p->stock_minimo, [6, 8, 10, 20, 80, 100], true))
            ->reject(fn ($p) => in_array($p->name, ['Tarjeta de interconexión Modbus TCP a Profibus DP GCA-EN-024806', 'Válvula on/off con switch posición (bola 1 cuerpo, acero carbono)'], true))
            ->filter(fn ($p) => $p->type !== Product::TYPE_SUPPLY || $p->quantity > 0)->values();
        $busy = [];

        for ($i = 1; $i <= 110; $i++) {
            $day = now()->subDays(mt_rand(0, 150));
            if ($day->isWeekend()) {
                $day = $day->previousWeekday();
            }
            $day = $day->startOfDay();
            $teacher = $teachers->random();
            $career = $teacher->careerCode;
            $recent = $day->gte(now()->subDays(3));
            $roll = mt_rand(1, 100);
            $status = $recent
                ? [Loan::STATUS_PENDING, Loan::STATUS_IN_PROGRESS, Loan::STATUS_PROCESSED][$roll % 3]
                : ($roll <= 90 ? Loan::STATUS_PROCESSED : Loan::STATUS_REJECTED);
            $open = $status === Loan::STATUS_PROCESSED && $recent;
            $due = $day->copy()->addDays(mt_rand(0, 3));
            $returned = $status === Loan::STATUS_PROCESSED && ! $open ? $due->copy()->setTime(mt_rand(15, 18), mt_rand(0, 59)) : null;
            [$start, $end, $block] = $blocks[array_rand($blocks)];
            $direct = mt_rand(1, 100) <= 40;
            $student = $direct && mt_rand(0, 1) ? $students->random() : null;
            $room = $rooms->random();

            $loan = Loan::create([
                'code' => Loan::generateUniqueCode($direct ? Loan::TYPE_DIRECT : Loan::TYPE_REMOTE),
                'type' => $direct ? Loan::TYPE_DIRECT : Loan::TYPE_REMOTE,
                'requested_by' => $teacher->id, 'borrower_name' => $student->full_name ?? $teacher->name,
                'borrower_document' => $student?->rut, 'student_id' => $student?->id,
                'subject' => $subjects[$career][array_rand($subjects[$career])], 'career_id' => $careers[$career],
                'location_id' => $room->id, 'room' => $room->nombre, 'start_time' => $start, 'end_time' => $end, 'time_block' => $block,
                'loan_date' => $day->toDateString(), 'due_date' => $due->toDateString(), 'status' => $status,
                'rejection_reason' => $status === Loan::STATUS_REJECTED ? 'Material reservado para otra sección en el mismo bloque.' : null,
                'approved_by' => $status === Loan::STATUS_PENDING ? null : $panolero->id,
                'approved_at' => $status === Loan::STATUS_PENDING ? null : $day->copy()->setTime(8, 0),
                'processed_by' => $status === Loan::STATUS_PROCESSED ? $panolero->id : null,
                'processed_at' => $status === Loan::STATUS_PROCESSED ? $day->copy()->setTime(8, 20) : null,
                'returned_at' => $returned, 'returned_by' => $returned ? $panolero->id : null,
                'return_notes' => $returned && mt_rand(1, 10) === 1 ? 'Se devuelve con un cable faltante.' : null,
            ]);
            $loan->forceFill(['created_at' => $day->copy()->subDay(), 'updated_at' => $returned ?? $day])->saveQuietly();

            foreach ($loanable->random(mt_rand(1, 3)) as $p) {
                $qty = $p->type === Product::TYPE_SUPPLY ? mt_rand(1, 5) : 1;
                $item = LoanItem::create([
                    'loan_id' => $loan->id, 'product_id' => $p->id, 'name' => $p->name, 'quantity' => $qty,
                    'delivered_quantity' => $status === Loan::STATUS_PROCESSED ? $qty : 0,
                    'returned_quantity' => $returned ? $qty : 0, 'returned_at' => $returned,
                    'return_condition' => $returned ? ['bueno', 'bueno', 'bueno', 'bueno', 'regular', 'danado'][mt_rand(0, 5)] : null,
                ]);

                if ($status === Loan::STATUS_PROCESSED && $p->hasUnits()) {
                    $unit = EquipmentUnit::where('product_id', $p->id)->where('status', EquipmentUnit::STATUS_AVAILABLE)->whereNotIn('id', $busy)->inRandomOrder()->first();
                    if ($unit) {
                        DB::table('loan_item_units')->insert([
                            'loan_item_id' => $item->id, 'equipment_unit_id' => $unit->id,
                            'returned_at' => $returned, 'return_condition' => $item->return_condition,
                            'created_at' => $day, 'updated_at' => $day,
                        ]);
                        if ($open) {
                            $busy[] = $unit->id;
                            $unit->update(['status' => EquipmentUnit::STATUS_ON_LOAN]);
                        }
                    }
                }
            }
        }

        foreach ($products->where('type', '!=', Product::TYPE_SUPPLY) as $p) {
            app(EquipmentUnitService::class)->syncProductCounters($p);
        }
    }

    private function maintenance(User $panolero, $suppliers): void
    {
        $notes = [
            MaintenanceRecord::TYPE_PREVENTIVE => 'Limpieza, revisión de conectores y prueba de funcionamiento.',
            MaintenanceRecord::TYPE_CORRECTIVE => 'Reemplazo de componente dañado y prueba posterior a la reparación.',
            MaintenanceRecord::TYPE_CALIBRATION => 'Calibración contra patrón con certificado.',
        ];
        $picked = EquipmentUnit::where('status', EquipmentUnit::STATUS_AVAILABLE)->inRandomOrder()->limit(16)->get();

        foreach ($picked as $k => $u) {
            $type = MaintenanceRecord::TYPES[$k % 3];
            $external = $type === MaintenanceRecord::TYPE_CALIBRATION || $k % 5 === 0;
            $done = $k >= 3;
            $start = now()->subDays(mt_rand(4, 170));
            MaintenanceRecord::create([
                'equipment_unit_id' => $u->id, 'responsible_id' => $panolero->id,
                'maintenance_type' => $type,
                'execution_mode' => $external ? MaintenanceRecord::MODE_EXTERNAL : MaintenanceRecord::MODE_OWN,
                'external_supplier_id' => $external ? $suppliers['microtec']->id : null,
                'repair_cost' => $external ? mt_rand(18, 95) * 1000 : 0,
                'spare_parts_used' => $type === MaintenanceRecord::TYPE_CORRECTIVE ? 'Repuestos según diagnóstico' : null,
                'technical_notes' => $notes[$type],
                'status' => $done ? MaintenanceRecord::STATUS_FINISHED : MaintenanceRecord::STATUS_IN_PROGRESS,
                'result' => $done ? MaintenanceRecord::RESULT_OPERATIONAL : null,
                'started_at' => $start, 'finished_at' => $done ? $start->copy()->addDays(mt_rand(1, 5)) : null,
                'created_by' => $panolero->id,
            ]);
            if ($done) {
                $u->forceFill(['last_maintenance_at' => $start, 'next_maintenance_at' => $start->copy()->addMonths(6)])->save();
            } else {
                $u->update(['status' => EquipmentUnit::STATUS_IN_REPAIR]);
            }
        }

        foreach (Product::where('type', '!=', Product::TYPE_SUPPLY)->get() as $p) {
            app(EquipmentUnitService::class)->syncProductCounters($p);
        }

        // Alertas de stock reales para los insumos bajo el mínimo.
        foreach (Product::where('type', Product::TYPE_SUPPLY)->whereRaw('quantity <= stock_minimo + 5')->where('quantity', '>', 0)->get() as $p) {
            app(StockAlertService::class)->checkStock($p);
        }
    }

    private function incidents(User|Collection $teachers, $locations, $products): void
    {
        // [equipo, título, descripción, gravedad, estado, tipo de solicitud]
        /** @var list<array{string, string, string, string, string, string}> $cases */
        $cases = [
            ['router', 'Router sin respuesta por consola', 'El equipo enciende pero no responde por el puerto de consola; se probó con dos cables.', 'media', 'en_revision', 'falla'],
            ['osciloscopio', 'Canal 2 con lectura inestable', 'La señal del canal 2 fluctúa aun con la sonda en cortocircuito.', 'media', 'en_reparacion', 'falla'],
            ['notebook', 'Batería no carga', 'El notebook solo funciona conectado a la red eléctrica.', 'leve', 'reportado', 'falla'],
            ['proyector', 'Lámpara con poca luminosidad', 'La imagen se ve muy tenue en la sala S-210. Se solicita mantención.', 'leve', 'reportado', 'mantencion'],
            ['multimetro', 'Pantalla con segmentos apagados', 'Faltan segmentos en el display; las lecturas son ilegibles.', 'media', 'resuelto', 'falla'],
            ['switch', 'Puertos 13 al 16 sin enlace', 'Los puertos no levantan enlace con ningún cable.', 'critica', 'en_revision', 'falla'],
            ['pineapple', 'Falla al iniciar el firmware', 'El dispositivo queda en bucle de arranque.', 'critica', 'en_reparacion', 'falla'],
            ['arduino', 'Kit incompleto: falta cable USB', 'Se necesita reponer el cable USB-B de dos kits.', 'leve', 'resuelto', 'reposicion'],
            ['raspberry', 'Tarjeta microSD corrupta', 'No arranca el sistema; se reinstaló sin éxito.', 'media', 'descartado', 'falla'],
            ['fuente', 'Perilla de voltaje con juego', 'El ajuste de voltaje salta entre valores.', 'media', 'reportado', 'mantencion'],
            ['maq_lan', 'Faltan patch cords en la maqueta', 'La maqueta fue devuelta sin tres cables de conexión.', 'leve', 'reportado', 'reposicion'],
            ['crimpadora', 'Cuchilla desgastada', 'Ya no corta bien el cable UTP.', 'leve', 'resuelto', 'mantencion'],
        ];
        $statusMap = ['descartado' => IncidentReport::STATUS_DISMISSED];
        $teacherList = $teachers instanceof Collection ? $teachers : collect([$teachers]);

        foreach ($cases as $i => [$key, $title, $desc, $severity, $status, $request]) {
            $product = $products[$key];
            $teacher = $teacherList->random();
            $at = now()->subDays(mt_rand(1, 90));
            $report = IncidentReport::create([
                'product_id' => $product->id,
                'equipment_unit_id' => EquipmentUnit::where('product_id', $product->id)->value('id'),
                'request_type' => $request, 'code' => IncidentReport::generateUniqueCode(),
                'reported_by' => $teacher->id, 'teacher_name' => $teacher->name,
                'title' => $title, 'description' => $desc, 'severity' => $severity,
                'status' => $statusMap[$status] ?? $status,
                'subject' => 'Clase práctica', 'report_date' => $at->toDateString(),
                'location_id' => $locations->values()->random()->id,
                'problems' => [[IncidentReport::PROBLEM_EQUIPMENT_FAILURE, IncidentReport::PROBLEM_MISSING_SUPPLIES, IncidentReport::PROBLEM_OTHER][$request === 'reposicion' ? 1 : ($request === 'mantencion' ? 2 : 0)]],
                'suggestion' => $request === 'falla' ? 'Revisar el equipo antes de la próxima clase.' : null,
            ]);
            $report->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
        }
    }
}
