<?php

namespace Database\Seeders;

use App\Models\Career;
use App\Models\Category;
use App\Models\EquipmentUnit;
use App\Models\IncidentReport;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Location;
use App\Models\MaintenanceRecord;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockAlert;
use App\Models\Student;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CodeSequenceService;
use App\Services\EquipmentUnitService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de demostración para probar los gráficos (/api/metrics y /api/dashboard/*).
 * Idempotente: si ya hay préstamos DEMO-*, no vuelve a generarlos.
 * Uso: php artisan db:seed --class=DemoMetricsSeeder
 */
class DemoMetricsSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('Datos de demostración deshabilitados en producción.');

            return;
        }

        if (Loan::where('code', 'like', 'DEMO-%')->exists()) {
            $this->command?->info('Datos DEMO ya existentes; se omiten.');

            return;
        }

        mt_srand(42);

        $this->call(DatabaseSeeder::class);

        $admin = User::where('email', 'admin@sgia.cl')->firstOrFail();
        $panolero = User::where('email', 'panol@sgia.cl')->first() ?? $admin;
        $password = Hash::make(config('sgia.seed_admin_password') ?? 'Admin1234!');

        $locations = Location::with('cajones')->get();
        $supplier = Supplier::firstOrCreate(
            ['name' => 'Proveedor Demo SpA'],
            ['contact_name' => 'Contacto Demo', 'email' => 'demo@proveedor.cl', 'phone' => '+56 9 1111 1111', 'category' => 'General', 'is_active' => true]
        );

        $units = app(EquipmentUnitService::class);
        $codes = app(CodeSequenceService::class);
        $careerIds = Career::pluck('id', 'code');
        $categories = collect(['Electricidad', 'Electrónica', 'Computación', 'Redes', 'Audiovisual', 'Ciberseguridad'])
            ->mapWithKeys(fn ($n) => [$n => Category::firstOrCreate(['name' => $n])->id]);

        // ---- Productos: [nombre, tipo, carrera, categoría, cantidad/unidades, stock mínimo, precio] ---
        $catalog = [
            ['Multímetro Fluke 117', 'equipo', 'AP', 'Electricidad', 12, 3, 189000],
            ['Osciloscopio Rigol DS1054Z', 'equipo', 'ISD', 'Electrónica', 6, 2, 420000],
            ['Notebook Lenovo ThinkPad', 'equipo', 'II', 'Computación', 14, 3, 750000],
            ['Router Cisco 2901', 'equipo', 'ISD', 'Redes', 8, 2, 520000],
            ['Switch Cisco Catalyst 2960', 'equipo', 'ISD', 'Redes', 10, 2, 380000],
            ['Raspberry Pi 4', 'equipo', 'IC', 'Electrónica', 12, 3, 95000],
            ['Kali Linux Pentest Kit (WiFi Pineapple)', 'equipo', 'IC', 'Ciberseguridad', 5, 1, 210000],
            ['Proyector Epson', 'equipo', 'II', 'Audiovisual', 5, 1, 450000],
            ['Kit Arduino Uno', 'equipo', 'AP', 'Electrónica', 16, 3, 32000],
            ['Maqueta de red LAN', 'maqueta', 'ISD', 'Redes', 5, 1, 150000],
            ['Maqueta de torre de telecomunicaciones', 'maqueta', 'ISD', 'Electrónica', 4, 1, 180000],
            ['Maqueta de centro de datos', 'maqueta', 'II', 'Computación', 4, 1, 220000],
            ['Maqueta de firewall perimetral', 'maqueta', 'IC', 'Ciberseguridad', 4, 1, 175000],
            ['Maqueta de aplicación móvil (display)', 'maqueta', 'AP', 'Computación', 3, 1, 90000],
            ['Cable UTP Cat6 (metro)', 'insumo', 'ISD', 'Redes', 400, 100, 650],
            ['Conectores RJ45 (unidad)', 'insumo', 'ISD', 'Redes', 250, 80, 250],
            ['Estaño 60/40 (rollo)', 'insumo', 'AP', 'Electrónica', 14, 5, 9500],
            ['Resistencias 1/4W (pack)', 'insumo', 'AP', 'Electrónica', 60, 20, 1800],
            ['Cinta aislante', 'insumo', 'II', 'Electricidad', 35, 10, 1200],
            ['Pasta térmica', 'insumo', 'II', 'Computación', 9, 6, 3500],
            ['Fusibles 5A (caja)', 'insumo', 'AP', 'Electricidad', 22, 8, 2900],
            ['Tornillos M3 (caja)', 'insumo', 'IC', 'Electricidad', 7, 6, 2200],
            ['Pendrive 32GB (arranque forense)', 'insumo', 'IC', 'Ciberseguridad', 18, 6, 5900],
            ['Cable de consola RJ45-USB', 'insumo', 'ISD', 'Redes', 5, 5, 7800],
        ];

        $products = collect();
        foreach ($catalog as [$name, $type, $career, $category, $qty, $min, $price]) {
            $location = $locations->random();
            $cajon = $location->cajones->isNotEmpty() ? $location->cajones->random() : null;
            $hasUnits = $type !== Product::TYPE_SUPPLY;
            $product = Product::create([
                'barcode' => $codes->forType($type, $careerIds[$career]),
                'name' => $name,
                'description' => "{$name} (dato de demostración)",
                'type' => $type,
                'quantity' => $hasUnits ? 0 : $qty,
                'stock_minimo' => $min,
                'supplier_id' => $supplier->id,
                'cajon_id' => $cajon?->id,
                'location_id' => $location->id,
                'area' => $category,
                'category_id' => $categories[$category],
                'career_id' => $careerIds[$career],
                'reference_price' => $price,
                'maintenance_priority' => ['A', 'B', 'C'][mt_rand(0, 2)],
                'requires_calibration' => $category === 'Electrónica',
                'maintenance_frequency_months' => $hasUnits ? [6, 12][mt_rand(0, 1)] : null,
                'is_active' => true,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);

            if ($hasUnits) {
                $created = $units->createUnits($product, $qty, ['cajon_id' => $cajon?->id ?? $units->unassignedCajon()->id], $admin->id);
                // Estados variados: unas en reparación, alguna dada de baja.
                foreach ($created->shuffle()->take(mt_rand(0, 2)) as $u) {
                    $u->update(['status' => mt_rand(0, 2) ? EquipmentUnit::STATUS_IN_REPAIR : EquipmentUnit::STATUS_WRITTEN_OFF]);
                }
                $units->syncProductCounters($product);
            }
            $products->push($product->fresh());
        }

        // ---- Carreras y docentes ----------------------------------------
        $careers = Career::pluck('name')->values()->all();
        $teachers = collect();
        foreach ([
            ['Carlos Muñoz', 0], ['Patricia Soto', 1], ['Andrés Rojas', 2],
            ['Daniela Fuentes', 3], ['Felipe Vargas', 1], ['Camila Reyes', 2],
        ] as $i => [$name, $careerIdx]) {
            $teachers->push(User::updateOrCreate(
                ['email' => 'docente'.($i + 1).'@sgia.cl'],
                ['name' => $name, 'password' => $password, 'role' => User::ROLE_TEACHER, 'area' => $careers[$careerIdx], 'is_active' => true]
            ));
        }

        $students = collect([
            ['21345678-9', 'Matías Parra Lagos', 'IC'], ['20987654-3', 'Sofía Contreras Díaz', 'II'],
            ['21111222-K', 'Benjamín Navarro Silva', 'AP'], ['20555444-1', 'Valentina Ortiz Ruiz', 'ISD'],
            ['21666777-5', 'Joaquín Mella Soto', 'IC'], ['20222333-7', 'Antonia Vega Cid', 'AP'],
        ])->map(fn ($s) => Student::firstOrCreate(['rut' => $s[0]], ['full_name' => $s[1], 'email' => strtolower(explode(' ', $s[1])[0]).'@alumnos.inacap.cl', 'career_id' => $careerIds[$s[2]]]));

        // ---- Préstamos (últimos ~300 días) ------------------------------
        $subjects = ['Redes de Computadores', 'Electrónica Digital', 'Taller de Electricidad', 'Ciberseguridad Aplicada', 'Programación de Dispositivos', 'Instalaciones Eléctricas'];
        $rooms = ['Sala 201', 'Lab E-301', 'Taller T-102'];
        $busy = []; // unidades ya prestadas en préstamos abiertos

        for ($i = 1; $i <= 160; $i++) {
            $loanDate = now()->subDays(mt_rand(0, 300))->startOfDay();
            $teacher = $teachers->random();
            $roll = mt_rand(1, 100);
            $status = match (true) {
                // Lo antiguo ya se resolvió: solo lo reciente queda abierto (evita atrasos irreales).
                $loanDate->lt(now()->subDays(14)) => $roll <= 88 ? Loan::STATUS_PROCESSED : Loan::STATUS_REJECTED,
                $roll <= 62 => Loan::STATUS_PROCESSED,
                $roll <= 78 => Loan::STATUS_IN_PROGRESS,
                $roll <= 90 => Loan::STATUS_PENDING,
                default => Loan::STATUS_REJECTED,
            };

            $due = $loanDate->copy()->addDays(mt_rand(1, 7));
            $returned = null;
            $open = $status === Loan::STATUS_PROCESSED && $loanDate->gte(now()->subDays(14));
            if ($open) {
                $due = now()->addDays(mt_rand(1, 5))->startOfDay();
            }
            if ($status === Loan::STATUS_PROCESSED && ! $open && mt_rand(1, 100) <= 99) {
                $returned = $due->copy()->addDays(mt_rand(-3, 2))->setTime(mt_rand(9, 17), 0);
                if ($returned->isFuture()) {
                    $returned = now();
                }
            }

            $direct = mt_rand(0, 1) === 1;
            $loan = Loan::create([
                'code' => sprintf('DEMO-%04d', $i),
                'type' => $direct ? Loan::TYPE_DIRECT : Loan::TYPE_REMOTE,
                'requested_by' => $teacher->id,
                'borrower_name' => $teacher->name,
                'student_id' => $direct && mt_rand(0, 1) ? $students->random()->id : null,
                'subject' => $subjects[array_rand($subjects)],
                'room' => $rooms[array_rand($rooms)],
                'loan_date' => $loanDate->toDateString(),
                'due_date' => $due->toDateString(),
                'time_block' => 'Bloque 1-3',
                'status' => $status,
                'rejection_reason' => $status === Loan::STATUS_REJECTED ? 'Material reservado para otra sección.' : null,
                'approved_by' => $status === Loan::STATUS_PENDING ? null : $panolero->id,
                'approved_at' => $status === Loan::STATUS_PENDING ? null : $loanDate,
                'processed_by' => $status === Loan::STATUS_PROCESSED ? $panolero->id : null,
                'processed_at' => $status === Loan::STATUS_PROCESSED ? $loanDate : null,
                'returned_at' => $returned,
                'returned_by' => $returned ? $panolero->id : null,
            ]);
            $loan->forceFill(['created_at' => $loanDate, 'updated_at' => $loanDate])->saveQuietly();

            foreach ($products->random(mt_rand(1, 3)) as $p) {
                $qty = $p->type === Product::TYPE_SUPPLY ? mt_rand(1, 6) : 1;
                $item = LoanItem::create([
                    'loan_id' => $loan->id,
                    'product_id' => $p->id,
                    'name' => $p->name,
                    'quantity' => $qty,
                    'delivered_quantity' => $status === Loan::STATUS_PROCESSED ? $qty : 0,
                    'returned_quantity' => $returned ? $qty : 0,
                    'returned_at' => $returned,
                    'return_condition' => $returned ? ['bueno', 'bueno', 'bueno', 'regular', 'danado'][mt_rand(0, 4)] : null,
                ]);

                if ($status === Loan::STATUS_PROCESSED && $p->hasUnits()) {
                    $unit = EquipmentUnit::where('product_id', $p->id)->where('status', EquipmentUnit::STATUS_AVAILABLE)
                        ->whereNotIn('id', $busy)->inRandomOrder()->first();
                    if ($unit) {
                        DB::table('loan_item_units')->insert([
                            'loan_item_id' => $item->id, 'equipment_unit_id' => $unit->id,
                            'returned_at' => $returned, 'return_condition' => $item->return_condition,
                            'created_at' => $loanDate, 'updated_at' => $loanDate,
                        ]);
                        if ($open) {
                            $busy[] = $unit->id;
                            $unit->update(['status' => EquipmentUnit::STATUS_ON_LOAN]);
                        }
                    }
                }
            }
        }

        // ---- Mantenciones, compras y presupuesto -------------------------
        $unitsList = EquipmentUnit::whereIn('status', [EquipmentUnit::STATUS_AVAILABLE, EquipmentUnit::STATUS_IN_REPAIR])->inRandomOrder()->limit(14)->get();
        foreach ($unitsList as $k => $u) {
            $done = $k % 3 !== 0;
            $start = now()->subDays(mt_rand(5, 200));
            MaintenanceRecord::create([
                'equipment_unit_id' => $u->id,
                'responsible_id' => $panolero->id,
                'maintenance_type' => MaintenanceRecord::TYPES[$k % 3],
                'execution_mode' => $k % 4 === 0 ? MaintenanceRecord::MODE_EXTERNAL : MaintenanceRecord::MODE_OWN,
                'external_supplier_id' => $k % 4 === 0 ? $supplier->id : null,
                'repair_cost' => $k % 4 === 0 ? mt_rand(15, 90) * 1000 : 0,
                'technical_notes' => 'Mantención de demostración.',
                'status' => $done ? MaintenanceRecord::STATUS_FINISHED : MaintenanceRecord::STATUS_IN_PROGRESS,
                'started_at' => $start,
                'finished_at' => $done ? $start->copy()->addDays(2) : null,
                'created_by' => $panolero->id,
            ]);
            if ($done) {
                $u->forceFill(['last_maintenance_at' => $start, 'next_maintenance_at' => $start->copy()->addMonths(6)])->save();
            }
        }


        foreach (Product::where('type', '!=', Product::TYPE_SUPPLY)->get() as $p) {
            $units->syncProductCounters($p);
        }

        // ---- Alertas de stock e incidentes ------------------------------
        if (! StockAlert::where('message', 'like', '[DEMO]%')->exists()) {
            foreach ($products->random(12) as $p) {
                $critical = mt_rand(0, 1) === 1;
                $at = now()->subDays(mt_rand(0, 120));
                $alert = StockAlert::create([
                    'product_id' => $p->id,
                    'alert_type' => $critical ? StockAlert::TYPE_CRITICAL : StockAlert::TYPE_WARNING,
                    'current_stock' => $critical ? $p->stock_minimo : $p->stock_minimo + 5,
                    'stock_minimo' => $p->stock_minimo,
                    'message' => "[DEMO] Stock bajo en {$p->name}",
                    'is_resolved' => mt_rand(1, 100) <= 40,
                ]);
                $alert->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
            }
        }

        if (! IncidentReport::where('code', 'like', 'DEMO-%')->exists()) {
            $severities = IncidentReport::SEVERITIES;
            $statuses = IncidentReport::STATUSES;
            for ($i = 1; $i <= 18; $i++) {
                $p = $products->where('type', Product::TYPE_EQUIPMENT)->random();
                $at = now()->subDays(mt_rand(0, 150));
                $report = IncidentReport::create([
                    'product_id' => $p->id,
                    'equipment_unit_id' => EquipmentUnit::where('product_id', $p->id)->value('id'),
                    'code' => sprintf('DEMO-NV-%03d', $i),
                    'reported_by' => $teachers->random()->id,
                    'title' => "Falla en {$p->name}",
                    'description' => 'Reporte de demostración generado para visualizar métricas.',
                    'severity' => $severities[array_rand($severities)],
                    'status' => $statuses[array_rand($statuses)],
                ]);
                $report->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
            }
        }

        $this->command?->info('Datos demo: '.Product::count().' productos, '.Loan::count().' préstamos, '.StockAlert::count().' alertas, '.IncidentReport::count().' incidentes.');
    }
}
