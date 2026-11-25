<?php

namespace Database\Seeders;

use App\Models\AnnualBudget;
use App\Models\Cajon;
use App\Models\Career;
use App\Models\Category;
use App\Models\ChartTemplate;
use App\Models\EquipmentUnit;
use App\Models\FailedLogin;
use App\Models\IncidentReport;
use App\Models\InvoiceIntake;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Location;
use App\Models\LoginRecord;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseRequest;
use App\Models\Quotation;
use App\Models\QuotationSupplier;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Rules\ChileanRut;
use App\Services\AnnualBudgetService;
use App\Services\CodeSequenceService;
use App\Services\EquipmentUnitService;
use App\Services\InvoiceIntakeService;
use App\Services\PurchaseService;
use App\Services\StockAlertService;
use App\Services\UserChartService;
use Dompdf\Dompdf;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * Datos de demostración realistas para presentar el sistema completo (reemplaza a DemoMetricsSeeder + InvoiceDemoSeeder).
 * Deja SIN procesar la factura 1 (prueba1.jpeg, Electroplus): su OC de pilas queda pendiente y la factura sin subir.
 * Variedad: 6 salas/talleres/bodegas nuevas, ~55 productos (equipos, maquetas e insumos; uno inactivo, insumos críticos y
 * agotados), 13 docentes (uno inactivo; docente@sgia.cl con solicitudes propias), 2 pañoleros y 2 directores, 30 estudiantes
 * (3 inactivos), 220 préstamos (algunos atrasados), ~32 mantenciones (en curso, anuladas, bajas, próximas y vencidas),
 * ~26 informes, 10 solicitudes de compra (todas las etapas, una facturada) y accesos de la última semana para Monitoreo.
 * Si faltan las imágenes de `pruebas-facturas/` (no se versionan), cada factura usa un PDF de demostración generado.
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
        $panoleros = collect([$panolero, $this->staff($password, $careers)]);

        $this->purchasing($admin, $director, $suppliers, $products, $budgets);
        $this->loans($panoleros, $teachers, $students, $products, $locations, $careers);
        $this->maintenance($panolero, $suppliers);
        $this->incidents($teachers, $locations, $products);
        $this->access($teachers);
        $this->dashboards();

        $this->command?->info(sprintf(
            'Demo realista: %d productos, %d unidades, %d préstamos, %d OC, %d solicitudes de compra, %d facturas, %d alertas, %d mantenciones, %d informes, %d estudiantes, %d usuarios. Factura 1 (Electroplus) pendiente.',
            Product::count(), EquipmentUnit::count(), Loan::count(), Purchase::count(), PurchaseRequest::count(), InvoiceIntake::count(), DB::table('stock_alerts')->count(),
            MaintenanceRecord::count(), IncidentReport::count(), Student::count(), User::count()
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
            ['Taller de Electrónica E-102', Location::TIPO_TALLER, 'Taller de soldadura, prototipos e impresión 3D', '1', 1, ['Banco-1', 'Banco-2', 'Herramientas']],
            ['Laboratorio de IoT I-301', Location::TIPO_SALA, 'Laboratorio de Internet de las Cosas y domótica', '3', 3, ['IoT-1', 'IoT-2']],
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
            // Más variedad: equipos y maquetas de todas las carreras.
            'ap' => ['Access Point Ubiquiti UniFi U6 Lite', 'equipo', 'ISD', 'Redes', 'redsur', 8, 2, 129000, 'Laboratorio de Redes R-204', 'Rack-2', 'UAP'],
            'tester' => ['Certificador de cableado Fluke MicroScanner2', 'equipo', 'ISD', 'Redes', 'redsur', 4, 1, 260000, 'Laboratorio de Redes R-204', 'Armario-Redes', 'FMS'],
            'fortigate' => ['Firewall Fortinet FortiGate 40F', 'equipo', 'IC', 'Ciberseguridad', 'ciberlab', 4, 1, 690000, 'Laboratorio de Ciberseguridad C-105', 'Caja-Fuerte-Equipos', 'FGT'],
            'camara' => ['Cámara IP Hikvision domo 4MP', 'equipo', 'IC', 'Ciberseguridad', 'ciberlab', 6, 2, 59000, 'Laboratorio de Ciberseguridad C-105', 'Mesa-B', 'HKV'],
            'tablet' => ['Tablet Samsung Galaxy Tab A9', 'equipo', 'II', 'Computación', 'compintegral', 8, 2, 169990, 'Sala de Computación S-210', 'Armario-Notebooks', 'SGT'],
            'nas' => ['NAS Synology DS224+', 'equipo', 'II', 'Computación', 'compintegral', 2, 1, 459000, 'Sala de Computación S-210', 'Armario-Notebooks', 'SYN'],
            'ups' => ['UPS APC Back-UPS 1500VA', 'equipo', 'II', 'Electricidad', 'electroplus', 4, 1, 219000, 'Pañol Central', 'Estante-E1', 'APC'],
            'cautin' => ['Estación de soldadura Hakko FX-888D', 'equipo', 'AP', 'Electrónica', 'systelec', 8, 2, 145000, 'Taller de Electrónica E-102', 'Banco-1', 'HKO'],
            'impresora3d' => ['Impresora 3D Creality Ender 3 V3', 'equipo', 'AP', 'Electrónica', 'compintegral', 3, 1, 289000, 'Taller de Electrónica E-102', 'Banco-2', 'CR3'],
            'esp32' => ['Kit ESP32 DevKit IoT', 'equipo', 'AP', 'Electrónica', 'systelec', 20, 5, 12900, 'Laboratorio de IoT I-301', 'IoT-1', 'ESP'],
            'maq_fibra' => ['Maqueta de red de fibra óptica FTTH', 'maqueta', 'ISD', 'Redes', 'redsur', 2, 1, 190000, 'Laboratorio de Redes R-204', 'Armario-Redes', 'MFO'],
            'maq_iot' => ['Maqueta de domótica IoT', 'maqueta', 'AP', 'Electrónica', 'systelec', 3, 1, 160000, 'Laboratorio de IoT I-301', 'IoT-2', 'MIO'],
            'maq_soc' => ['Maqueta de centro de operaciones de seguridad (SOC)', 'maqueta', 'IC', 'Ciberseguridad', 'ciberlab', 2, 1, 240000, 'Laboratorio de Ciberseguridad C-105', 'Mesa-A', 'MSC'],
            // Insumos con stock holgado, crítico (bajo el mínimo) y agotado.
            'patch_fibra' => ['Patch cord fibra óptica LC-LC 2 m', 'insumo', 'ISD', 'Redes', 'redsur', 40, 15, 4900, 'Bodega de Insumos', 'Estante-I1', null],
            'canaleta' => ['Canaleta PVC 20x10 mm (tira 2 m)', 'insumo', 'ISD', 'Electricidad', 'electroplus', 3, 10, 1500, 'Bodega de Insumos', 'Estante-I3', null],
            'bridas' => ['Bridas plásticas 200 mm (bolsa 100)', 'insumo', 'ISD', 'Electricidad', 'electroplus', 18, 5, 2300, 'Bodega de Insumos', 'Estante-I3', null],
            'alcohol' => ['Alcohol isopropílico 99% (1 L)', 'insumo', 'II', 'Computación', 'compintegral', 4, 3, 6900, 'Bodega de Insumos', 'Estante-I2', null],
            'hdmi' => ['Cable HDMI 2 m', 'insumo', 'II', 'Audiovisual', 'compintegral', 12, 4, 3990, 'Bodega de Insumos', 'Estante-I1', null],
            'cr2032' => ['Pila botón CR2032 (blíster 5)', 'insumo', 'AP', 'Electricidad', 'electroplus', 2, 6, 2500, 'Bodega de Insumos', 'Estante-I3', null],
            'termo' => ['Tubo termocontraíble surtido (kit)', 'insumo', 'AP', 'Electrónica', 'systelec', 9, 4, 5200, 'Taller de Electrónica E-102', 'Herramientas', null],
            'filamento' => ['Filamento PLA 1,75 mm (rollo 1 kg)', 'insumo', 'AP', 'Electrónica', 'compintegral', 5, 3, 15990, 'Taller de Electrónica E-102', 'Herramientas', null],
            'microsd' => ['Tarjeta microSD 32GB', 'insumo', 'IC', 'Ciberseguridad', 'ciberlab', 0, 5, 6500, 'Bodega de Insumos', 'Estante-I2', null],
            // Producto descontinuado: queda inactivo.
            'hub' => ['Hub USB 2.0 de 4 puertos (descontinuado)', 'insumo', 'II', 'Computación', 'compintegral', 3, 0, 4990, 'Bodega de Insumos', 'Estante-I1', null, false],
        ];

        return collect($rows)->map(function ($d) use ($codes, $units, $cat, $admin, $careers, $suppliers, $locations, $cajones) {
            [$name, $type, $career, $category, $supplier, $qty, $min, $price, $place, $drawer, $serialPrefix] = array_slice($d, 0, 11);
            $active = $d[11] ?? true;
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
                'is_active' => $active, 'created_by' => $admin->id, 'updated_by' => $admin->id,
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

        $teachers = collect([
            ['Carlos Muñoz Henríquez', 'ISD'], ['Patricia Soto Alarcón', 'II'], ['Andrés Rojas Quezada', 'IC'],
            ['Daniela Fuentes Maturana', 'AP'], ['Felipe Vargas Cifuentes', 'II'], ['Camila Reyes Painemal', 'IC'],
            ['Gonzalo Sepúlveda Araya', 'ISD'], ['Valeria Henríquez Toro', 'AP'], ['Martín Cárcamo Uribe', 'IC'],
            ['Catalina Aguilera Ríos', 'II'], ['Sebastián Lagos Ñancupil', 'AP'],
            // Docente que ya no trabaja en la sede: inactivo (no entra, conserva su historial).
            ['Rodrigo Leiva Saavedra', 'ISD', false],
        ])->map(fn ($t, $i) => User::updateOrCreate(
            ['email' => 'docente'.($i + 1).'@sgia.cl'],
            ['name' => $t[0], 'password' => $password, 'role' => User::ROLE_TEACHER, 'area' => $names[$t[1]], 'is_active' => $t[2] ?? true]
        )->setRelation('careerCode', $t[1]));

        // La cuenta de presentación (docente@sgia.cl) también tiene préstamos e informes propios.
        $demo = User::where('email', 'docente@sgia.cl')->firstOrFail();
        $demo->update(['name' => 'Francisco Morales Bravo', 'area' => $names['II']]);

        return $teachers->push($demo->setRelation('careerCode', 'II'));
    }

    /** Segundo pañolero (turno tarde) y segunda directora (Informática y Ciberseguridad). Devuelve el pañolero. */
    private function staff(string $password, $careers): User
    {
        $panol = User::updateOrCreate(['email' => 'panol2@sgia.cl'], [
            'name' => 'Héctor Jara Morales', 'password' => $password, 'role' => User::ROLE_WAREHOUSE, 'area' => 'Informática', 'is_active' => true,
        ]);
        $directora = User::updateOrCreate(['email' => 'director2@sgia.cl'], [
            'name' => 'Marcela Bustos Riquelme', 'password' => $password, 'role' => User::ROLE_DIRECTOR, 'area' => 'Informática', 'is_active' => true,
        ]);
        $directora->careers()->syncWithoutDetaching([$careers['II'], $careers['IC']]);

        return $panol;
    }

    private function students($careers)
    {
        $rows = [
            ['21345678-9', 'Matías Parra Lagos', 'IC'], ['20987654-3', 'Sofía Contreras Díaz', 'II'],
            ['21111222-K', 'Benjamín Navarro Silva', 'AP'], ['20555444-1', 'Valentina Ortiz Ruiz', 'ISD'],
            ['21666777-5', 'Joaquín Mella Soto', 'IC'], ['20222333-7', 'Antonia Vega Cid', 'AP'],
            ['21098765-4', 'Ignacio Carrasco Leal', 'ISD'], ['20876543-2', 'Francisca Neira Millar', 'II'],
            ['21234987-6', 'Tomás Painemal Huenchullán', 'IC'], ['20765432-1', 'Javiera Alarcón Pino', 'AP'],
        ];
        // 20 más, con RUT de dígito verificador válido; los tres últimos quedan inactivos (egresados o retirados).
        $more = [
            ['Agustín Fuentealba Rivas', 'ISD'], ['Isidora Catalán Burgos', 'II'], ['Vicente Huaiquil Torres', 'IC'],
            ['Florencia Arriagada Paz', 'AP'], ['Lucas Riquelme Espinoza', 'ISD'], ['Emilia Saavedra Cortés', 'II'],
            ['Diego Quilodrán Molina', 'IC'], ['Martina Gallardo Opazo', 'AP'], ['Cristóbal Yáñez Bravo', 'ISD'],
            ['Josefa Hidalgo Sanhueza', 'II'], ['Maximiliano Ulloa Vera', 'IC'], ['Trinidad Bustamante Lillo', 'AP'],
            ['Felipe Cayuqueo Antilef', 'ISD'], ['Renata Jofré Castillo', 'II'], ['Bastián Orellana Pérez', 'IC'],
            ['Amanda Villagra Retamal', 'AP'], ['Simón Toledo Garrido', 'ISD'], ['Paz Zúñiga Lobos', 'II'],
            ['Nicolás Mardones Fica', 'IC'], ['Constanza Barra Vidal', 'AP'],
        ];
        foreach ($more as $i => [$name, $career]) {
            $body = (string) (20_300_000 + $i * 61_237);
            $rows[] = [$body.'-'.ChileanRut::checkDigit($body), $name, $career, $i >= 17];
        }

        return collect($rows)->map(function ($s) use ($careers) {
            // Dígito verificador siempre válido (los RUT de la lista original no lo tenían).
            $body = explode('-', $s[0])[0];
            $s[0] = $body.'-'.ChileanRut::checkDigit($body);
            $student = Student::firstOrCreate(['rut' => $s[0]], [
                'full_name' => $s[1], 'email' => strtolower(explode(' ', $s[1])[0]).'.'.strtolower(explode(' ', $s[1])[1]).'@alumnos.inacap.cl',
                'career_id' => $careers[$s[2]], 'registered_at' => now()->subDays(mt_rand(30, 400)),
            ]);
            if ($s[3] ?? false) {
                $student->forceFill(['is_active' => false])->save();
            }

            return $student;
        })->filter(fn ($s) => $s->is_active)->values();
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
            $path = $this->storeInvoiceFile($file, $number);
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
            [PurchaseRequest::STATUS_DRAFT, 'IC', 'Tarjetas microSD para el laboratorio forense (agotadas).', [['microsd', 25]], [], [], null, 0],
            [PurchaseRequest::STATUS_SENT, 'II', 'Cables HDMI y alcohol isopropílico para las salas de computación.', [['hdmi', 15], ['alcohol', 6]], ['informatica', 'diseno'], [], null, 4],
            [PurchaseRequest::STATUS_QUOTED, 'ISD', 'Canaletas y bridas para ordenar el cableado del laboratorio R-204.', [['canaleta', 40], ['bridas', 10]], ['telecomunicaciones'], [['redsur', 1.0], ['electroplus', 0.95], ['systelec', 1.08]], null, 8],
            // Ciclo completo: con OC y factura confirmada (queda «facturada», stock ingresado y presupuesto consumido).
            [PurchaseRequest::STATUS_ORDERED, 'AP', 'Termocontraíble y patch cords de fibra para el taller de electrónica.', [['termo', 6], ['patch_fibra', 20]], ['telecomunicaciones'], [['systelec', 1.0], ['redsur', 0.98], ['electroplus', 1.06]], 1, 30, '7731'],
        ];

        $intakes = app(InvoiceIntakeService::class);

        foreach ($defs as $def) {
            [$status, $budget, $notes, $lines, $areas, $quotes, $selected, $daysAgo] = $def;
            $invoiceNumber = $def[8] ?? null;
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

                if ($invoiceNumber !== null) {
                    $intake = InvoiceIntake::create([
                        'user_id' => $director->id, 'annual_budget_id' => $purchase->annual_budget_id, 'purchase_id' => $purchase->id,
                        'pdf_path' => $this->storeInvoiceFile("factura-{$invoiceNumber}.pdf", $invoiceNumber), 'document_number' => $invoiceNumber,
                        'status' => InvoiceIntake::STATUS_DRAFT,
                        'raw_ocr_payload' => ['invoice_number' => $invoiceNumber, 'supplier_id' => $purchase->supplier_id, 'items' => [], 'ocr_available' => true],
                    ]);
                    $intakes->confirm($intake, $invoiceNumber, $purchase->items()->get()->map(fn ($it) => [
                        'product_id' => $it->product_id, 'quantity' => $it->quantity, 'unit_price' => (float) $it->unit_price,
                    ])->all(), $director->id);
                    $at = $when->copy()->addDays(12);
                    $intake->forceFill(['created_at' => $at, 'updated_at' => $at, 'received_at' => $at])->saveQuietly();
                    $purchase->refresh()->forceFill(['received_at' => $at])->saveQuietly();
                }
            }
        }
    }

    /**
     * Guarda el archivo de una factura de demostración y devuelve su ruta. Usa `pruebas-facturas/{$file}` si existe
     * (no se versiona); si no, un PDF generado con DomPDF que dice que es de demostración.
     */
    private function storeInvoiceFile(string $file, string $number): string
    {
        $source = base_path("pruebas-facturas/{$file}");
        if (is_file($source)) {
            $path = 'invoice-intakes/'.basename($file);
            Storage::disk('local')->put($path, (string) file_get_contents($source));

            return $path;
        }

        $dompdf = new Dompdf;
        $dompdf->loadHtml('<html><body style="font-family: DejaVu Sans, sans-serif; padding: 40px;">'
            .'<h1 style="font-size: 22px;">Factura N° '.e($number).'</h1>'
            .'<p style="font-size: 14px; color: #555;">Documento de demostración de SGIA: reemplaza a la imagen original de la factura, que no está en el repositorio.</p>'
            .'</body></html>');
        $dompdf->render();
        $path = 'invoice-intakes/demo-factura-'.$number.'.pdf';
        Storage::disk('local')->put($path, (string) $dompdf->output());

        return $path;
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

    private function loans(Collection $panoleros, $teachers, $students, $products, $locations, $careers): void
    {
        $subjects = [
            'ISD' => ['Redes de Computadores', 'Telecomunicaciones I', 'Cableado Estructurado'],
            'II' => ['Infraestructura de TI', 'Administración de Sistemas', 'Gestión de Proyectos TI'],
            'IC' => ['Ciberseguridad Aplicada', 'Hacking Ético', 'Análisis Forense'],
            'AP' => ['Electrónica Digital', 'Programación de Dispositivos', 'Instalaciones Eléctricas'],
        ];
        $rooms = $locations->filter(fn ($l) => $l->tipo === Location::TIPO_SALA)->values();
        $blocks = [['08:30', '10:00', 'Bloque 1-2'], ['10:15', '11:45', 'Bloque 3-4'], ['14:00', '15:30', 'Bloque 5-6'], ['15:45', '17:15', 'Bloque 7-8']];
        $loanable = $products->filter(fn ($p) => $p->is_active)
            ->filter(fn ($p) => $p->type !== Product::TYPE_SUPPLY || in_array($p->stock_minimo, [4, 5, 6, 8, 10, 15, 20, 80, 100], true))
            ->reject(fn ($p) => in_array($p->name, ['Tarjeta de interconexión Modbus TCP a Profibus DP GCA-EN-024806', 'Válvula on/off con switch posición (bola 1 cuerpo, acero carbono)'], true))
            ->filter(fn ($p) => $p->type !== Product::TYPE_SUPPLY || $p->quantity > 0)->values();
        $busy = [];

        $active = $teachers->filter(fn ($t) => $t->is_active)->values();
        $demoTeacher = $teachers->firstWhere('email', 'docente@sgia.cl');

        for ($i = 1; $i <= 220; $i++) {
            // Las 4 primeras, de la cuenta de presentación; 5 solicitudes fijas en la cola (hoy y ayer); ~15 más en los
            // últimos 5 días (préstamos abiertos y en proceso).
            $day = match (true) {
                $i <= 4 => now()->subDays($i - 1),
                $i <= 9 => now()->subDays(($i - 5) % 2),
                $i <= 24 => now()->subDays(mt_rand(0, 5)),
                // 3 atrasados fijos (hace 7, 9 y 11 días), para que Inicio y Analítica siempre los muestren.
                $i <= 27 => now()->subDays(7 + ($i - 25) * 2),
                default => now()->subDays(mt_rand(0, 180)),
            };
            $forcedLate = $i >= 25 && $i <= 27;
            $forcedPending = $i >= 5 && $i <= 9;
            if ($day->isWeekend()) {
                $day = $day->previousWeekday();
            }
            $day = $day->startOfDay();
            $recent = $day->gte(now()->subDays(3));
            $teacher = $i <= 4 ? $demoTeacher : ($recent ? $active->random() : $teachers->random());
            $career = $teacher->careerCode;
            $roll = mt_rand(1, 100);
            $status = $i <= 4
                ? [Loan::STATUS_PENDING, Loan::STATUS_IN_PROGRESS, Loan::STATUS_PROCESSED, Loan::STATUS_REJECTED][$i - 1]
                : ($forcedPending ? Loan::STATUS_PENDING : ($recent
                    ? [Loan::STATUS_PENDING, Loan::STATUS_IN_PROGRESS, Loan::STATUS_PROCESSED][$roll % 3]
                    : ($roll <= 90 || $forcedLate ? Loan::STATUS_PROCESSED : Loan::STATUS_REJECTED)));
            // Abiertos: los recientes (vencen desde hoy) y los 3 atrasados fijos.
            $late = $forcedLate;
            $open = $status === Loan::STATUS_PROCESSED && ($recent || $late);
            $panolero = $panoleros->random();
            // Los recientes que siguen abiertos vencen desde hoy: solo quedan atrasados los `$late` a propósito.
            $due = $open && $recent ? now()->startOfDay()->addDays(mt_rand(0, 3)) : $day->copy()->addDays(mt_rand(0, 3));
            $returned = $status === Loan::STATUS_PROCESSED && ! $open ? $due->copy()->setTime(mt_rand(15, 18), mt_rand(0, 59)) : null;
            [$start, $end, $block] = $blocks[array_rand($blocks)];
            // En el mostrador se entrega en el acto: solo los préstamos procesados pueden ser presenciales.
            $direct = $status === Loan::STATUS_PROCESSED && mt_rand(1, 100) <= 45;
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
        $picked = EquipmentUnit::where('status', EquipmentUnit::STATUS_AVAILABLE)->inRandomOrder()->limit(32)->get();
        $workshops = ['microtec', 'systelec', 'compintegral'];

        foreach ($picked as $k => $u) {
            $type = MaintenanceRecord::TYPES[$k % 3];
            $external = $type === MaintenanceRecord::TYPE_CALIBRATION || $k % 5 === 0;
            // 0-4 en curso, 5-6 anuladas, 7-8 terminan en baja, el resto operativas.
            $status = match (true) {
                $k <= 4 => MaintenanceRecord::STATUS_IN_PROGRESS,
                $k <= 6 => MaintenanceRecord::STATUS_CANCELLED,
                default => MaintenanceRecord::STATUS_FINISHED,
            };
            $writeOff = $k === 7 || $k === 8;
            $done = $status === MaintenanceRecord::STATUS_FINISHED;
            $start = $k <= 4 ? now()->subDays(mt_rand(1, 12)) : now()->subDays(mt_rand(4, 170));
            MaintenanceRecord::create([
                'equipment_unit_id' => $u->id, 'responsible_id' => $panolero->id,
                'maintenance_type' => $writeOff ? MaintenanceRecord::TYPE_CORRECTIVE : $type,
                'execution_mode' => $external ? MaintenanceRecord::MODE_EXTERNAL : MaintenanceRecord::MODE_OWN,
                'external_supplier_id' => $external ? $suppliers[$workshops[$k % 3]]->id : null,
                'repair_cost' => $external && $status !== MaintenanceRecord::STATUS_CANCELLED ? mt_rand(18, 95) * 1000 : 0,
                'spare_parts_used' => $type === MaintenanceRecord::TYPE_CORRECTIVE ? 'Repuestos según diagnóstico' : null,
                'technical_notes' => match (true) {
                    $writeOff => 'Daño irreparable: el costo de reparación supera el valor del equipo. Se da de baja.',
                    $status === MaintenanceRecord::STATUS_CANCELLED => 'Se anula: el equipo se reemplazó por garantía antes de la revisión.',
                    default => $notes[$type],
                },
                'status' => $status,
                'result' => $done ? ($writeOff ? MaintenanceRecord::RESULT_WRITE_OFF : MaintenanceRecord::RESULT_OPERATIONAL) : null,
                'started_at' => $start, 'finished_at' => $done ? $start->copy()->addDays(mt_rand(1, 5)) : null,
                'created_by' => $panolero->id,
            ]);
            if ($writeOff) {
                $u->update(['status' => EquipmentUnit::STATUS_WRITTEN_OFF]);
            } elseif ($done) {
                // Próxima mantención: la mayoría en 6 meses; algunas esta semana y otras ya vencidas (avisos de mantención).
                $next = match ($k % 6) {
                    0 => now()->addDays(mt_rand(1, 7)),
                    1 => now()->subDays(mt_rand(3, 20)),
                    default => $start->copy()->addMonths(6),
                };
                $u->forceFill(['last_maintenance_at' => $start, 'next_maintenance_at' => $next])->save();
            } elseif ($status === MaintenanceRecord::STATUS_IN_PROGRESS) {
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
            ['ap', 'Access point se reinicia solo', 'Se cae la red inalámbrica del laboratorio cada 20 minutos aproximadamente.', 'media', 'en_revision', 'falla'],
            ['fortigate', 'Licencia de firmware vencida', 'No se pueden actualizar las firmas del IPS para la clase de hardening.', 'media', 'reportado', 'mantencion'],
            ['camara', 'Imagen nocturna en blanco', 'El infrarrojo no enciende; la grabación nocturna sale en blanco.', 'leve', 'reparado', 'falla'],
            ['tablet', 'Pantalla trizada', 'La tablet volvió de préstamo con la pantalla trizada en una esquina.', 'media', 'en_reparacion', 'falla'],
            ['ups', 'Batería hinchada', 'La UPS emite pitidos y la batería está deformada. Se retira de uso por seguridad.', 'critica', 'dado_de_baja', 'falla'],
            ['cautin', 'Punta del cautín oxidada', 'No toma temperatura de trabajo; se necesitan puntas de repuesto.', 'leve', 'resuelto', 'reposicion'],
            ['impresora3d', 'Boquilla obstruida', 'La impresora no extruye filamento desde la última clase.', 'media', 'reparado', 'mantencion'],
            ['esp32', 'Faltan cables micro-USB en los kits', 'Tres kits ESP32 llegaron sin cable de programación.', 'leve', 'reportado', 'reposicion'],
            ['notebook', 'Teclado con teclas sueltas', 'Las teclas Ctrl y Alt se desprenden con el uso.', 'leve', 'en_revision', 'falla'],
            ['maq_iot', 'Sensor de movimiento sin lectura', 'El sensor PIR de la maqueta no detecta presencia.', 'media', 'en_reparacion', 'falla'],
            ['tester', 'Certificador sin calibración vigente', 'El certificado de calibración venció el mes pasado.', 'media', 'reportado', 'mantencion'],
            ['router', 'Faltan cables seriales', 'Se necesitan cables seriales DCE/DTE para la práctica de WAN.', 'leve', 'resuelto', 'reposicion'],
            ['nas', 'Disco 2 con sectores defectuosos', 'El NAS avisa SMART crítico en el disco de la bahía 2.', 'critica', 'reportado', 'falla'],
            ['switch', 'Ventilador ruidoso', 'El ventilador del switch del rack 2 hace mucho ruido.', 'leve', 'descartado', 'mantencion'],
        ];
        $statusMap = ['descartado' => IncidentReport::STATUS_DISMISSED];
        $teacherList = $teachers instanceof Collection ? $teachers : collect([$teachers]);

        foreach ($cases as $i => [$key, $title, $desc, $severity, $status, $request]) {
            $product = $products[$key];
            // 1 de cada 6 es de la cuenta de presentación (su «Mis informes» nunca queda vacío).
            $teacher = $i % 6 === 0
                ? ($teacherList->firstWhere('email', 'docente@sgia.cl') ?? $teacherList->random())
                : $teacherList->random();
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
                'problems' => [['type' => [IncidentReport::PROBLEM_EQUIPMENT_FAILURE, IncidentReport::PROBLEM_MISSING_SUPPLIES, IncidentReport::PROBLEM_OTHER][$request === 'reposicion' ? 1 : ($request === 'mantencion' ? 2 : 0)], 'description' => $desc]],
                'suggestion' => $request === 'falla' ? 'Revisar el equipo antes de la próxima clase.' : null,
            ]);
            $report->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
        }
    }

    /** Panel de Analítica armado para los directores (con el mismo servicio que usa la API). */
    private function dashboards(): void
    {
        $charts = app(UserChartService::class);
        $panels = [
            'director@sgia.cl' => ['loans_trend', 'loans_by_status', 'usage_by_career', 'top_supplies', 'maintenance_by_type'],
            'director2@sgia.cl' => ['loans_by_subject', 'equipment_by_condition', 'alerts_by_criticality'],
        ];
        foreach ($panels as $email => $codes) {
            $user = User::where('email', $email)->firstOrFail();
            foreach ($codes as $code) {
                $charts->create($user, ['chart_template_id' => ChartTemplate::where('code', $code)->value('id')]);
            }
        }
    }

    /**
     * Accesos de los últimos 6 días para Monitoreo (AD-01, ventana de 7 días): inicios de sesión de cada usuario activo,
     * claves mal escritas, un docente inactivo intentando entrar y una ráfaga bloqueada desde una IP externa.
     */
    private function access(Collection $teachers): void
    {
        $agents = [
            'web' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0 Safari/537.36',
                'Mozilla/5.0 (X11; Linux x86_64; rv:133.0) Gecko/20100101 Firefox/133.0'],
            'mobile' => ['SGIA/1.0 (Android 14; Samsung SM-A546E)', 'SGIA/1.0 (iOS 18.1; iPhone14,5)'],
        ];
        $at = fn () => now()->subDays(mt_rand(0, 5))->setTime(mt_rand(8, 20), mt_rand(0, 59));

        foreach (User::where('is_active', true)->get() as $user) {
            $device = $user->role === User::ROLE_TEACHER && mt_rand(1, 10) <= 7 ? 'mobile' : 'web';
            $last = null;
            for ($n = mt_rand(2, 9); $n > 0; $n--) {
                $when = $at();
                LoginRecord::create([
                    'user_id' => $user->id, 'device_type' => $device, 'user_agent' => $agents[$device][mt_rand(0, 1)],
                    'ip_address' => '10.20.'.mt_rand(1, 30).'.'.mt_rand(2, 250), 'logged_in_at' => $when,
                ]);
                $last = $last === null || $when->gt($last) ? $when : $last;
            }
            $user->forceFill(['last_login_at' => $last])->saveQuietly();
        }

        $fail = fn (string $email, string $reason, string $ip, ?User $user = null) => FailedLogin::create([
            'email' => $email, 'user_id' => $user?->id, 'reason' => $reason, 'ip_address' => $ip,
            'user_agent' => $agents['web'][0], 'attempted_at' => $at(),
        ]);
        foreach (User::where('is_active', true)->inRandomOrder()->limit(8)->get() as $user) {
            $fail($user->email, FailedLogin::REASON_INVALID_CREDENTIALS, '10.20.'.mt_rand(1, 30).'.'.mt_rand(2, 250), $user);
        }
        foreach (['admin@sgia.com', 'director@inacap.cl', 'panolero@sgia.cl'] as $typo) {
            $fail($typo, FailedLogin::REASON_INVALID_CREDENTIALS, '10.20.4.'.mt_rand(2, 250));
        }
        $inactive = $teachers->firstWhere('is_active', false);
        if ($inactive) {
            foreach (range(1, 3) as $_) {
                $fail($inactive->email, FailedLogin::REASON_INACTIVE, '10.20.12.87', $inactive);
            }
        }
        // Ráfaga contra la cuenta de administración desde fuera de la red de la sede.
        $burst = now()->subDays(2)->setTime(3, 14);
        foreach (range(0, 11) as $k) {
            FailedLogin::create([
                'email' => 'admin@sgia.cl', 'user_id' => User::where('email', 'admin@sgia.cl')->value('id'),
                'reason' => $k < 5 ? FailedLogin::REASON_INVALID_CREDENTIALS : FailedLogin::REASON_THROTTLED,
                'ip_address' => '190.163.45.12', 'user_agent' => 'python-requests/2.32.3', 'attempted_at' => $burst->copy()->addSeconds($k * 7),
            ]);
        }
    }
}
