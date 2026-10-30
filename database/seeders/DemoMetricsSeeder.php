<?php

namespace Database\Seeders;

use App\Models\Career;
use App\Models\IncidentReport;
use App\Models\Loan;
use App\Models\LoanItem;
use App\Models\Location;
use App\Models\Product;
use App\Models\StockAlert;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;
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

        mt_srand(42);

        $admin = User::where('email', 'admin@sgia.cl')->firstOrFail();
        $panolero = User::where('email', 'panolero@sgia.cl')->first() ?? $admin;
        $password = Hash::make(config('sgia.seed_admin_password') ?? 'Admin1234!');

        $this->call(DatabaseSeeder::class);

        $locations = Location::with('cajones')->get();
        $supplier = Supplier::firstOrCreate(
            ['name' => 'Proveedor Demo SpA'],
            ['contact_name' => 'Contacto Demo', 'email' => 'demo@proveedor.cl', 'phone' => '+56 9 1111 1111', 'category' => 'General', 'is_active' => true]
        );

        // ---- Productos ---------------------------------------------------
        $equipment = [
            ['Multímetro Fluke 117', 'Electricidad', 12, 1, 0],
            ['Osciloscopio Rigol DS1054Z', 'Electrónica', 6, 2, 1],
            ['Notebook Lenovo ThinkPad', 'Computación', 25, 3, 2],
            ['Router Cisco 2901', 'Redes', 8, 0, 0],
            ['Switch Cisco Catalyst 2960', 'Redes', 10, 1, 1],
            ['Raspberry Pi 4', 'Electrónica', 18, 0, 0],
            ['Fuente de poder variable', 'Electricidad', 9, 2, 0],
            ['Crimpeadora RJ45', 'Redes', 15, 0, 1],
            ['Proyector Epson', 'Audiovisual', 5, 1, 0],
            ['Kit Arduino Uno', 'Electrónica', 30, 4, 0],
        ];
        $supplies = [
            ['Cable UTP Cat6 (metro)', 'Redes', 400, 100],
            ['Conectores RJ45 (unidad)', 'Redes', 250, 80],
            ['Estaño 60/40 (rollo)', 'Electrónica', 14, 5],
            ['Resistencias 1/4W (pack)', 'Electrónica', 60, 20],
            ['Cinta aislante', 'Electricidad', 35, 10],
            ['Pasta térmica', 'Computación', 9, 6],
            ['Fusibles 5A (caja)', 'Electricidad', 22, 8],
            ['Tornillos M3 (caja)', 'Electricidad', 7, 6],
        ];

        $products = collect();
        $n = 0;

        foreach ($equipment as [$name, $area, $qty, $repair, $off]) {
            $products->push($this->product(++$n, $name, Product::TYPE_EQUIPMENT, $area, $qty, $repair, $off, 3, $locations, $supplier, $admin));
        }
        foreach ($supplies as [$name, $area, $qty, $min]) {
            $products->push($this->product(++$n, $name, Product::TYPE_SUPPLY, $area, $qty, 0, 0, $min, $locations, $supplier, $admin));
        }

        // ---- Carreras y docentes ----------------------------------------
        $careers = Career::pluck('name')->all();
        $teachers = collect();
        foreach ([
            ['Carlos Muñoz', 0], ['Patricia Soto', 1], ['Andrés Rojas', 2],
            ['Daniela Fuentes', 0], ['Felipe Vargas', 1], ['Camila Reyes', 2],
        ] as $i => [$name, $careerIdx]) {
            $teachers->push(User::updateOrCreate(
                ['email' => 'docente'.($i + 1).'@sgia.cl'],
                ['name' => $name, 'password' => $password, 'role' => User::ROLE_TEACHER, 'area' => $careers[$careerIdx], 'is_active' => true]
            ));
        }

        // ---- Préstamos (últimos ~300 días) ------------------------------
        $subjects = ['Redes de Computadores', 'Electrónica Digital', 'Taller de Electricidad', 'Ciberseguridad Aplicada', 'Programación de Dispositivos', 'Instalaciones Eléctricas'];
        $rooms = ['Sala 201', 'Lab E-301', 'Taller T-102'];

        if (Loan::where('code', 'like', 'DEMO-%')->exists()) {
            $this->command?->info('Préstamos DEMO ya existentes; se omiten.');
        } else {
            for ($i = 1; $i <= 160; $i++) {
                $loanDate = now()->subDays(mt_rand(0, 300))->startOfDay();
                $teacher = $teachers->random();
                $roll = mt_rand(1, 100);
                $status = match (true) {
                    $loanDate->isFuture() => Loan::STATUS_PENDING,
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

                $loan = Loan::create([
                    'code' => sprintf('DEMO-%04d', $i),
                    'type' => mt_rand(0, 1) ? Loan::TYPE_REMOTE : Loan::TYPE_DIRECT,
                    'requested_by' => $teacher->id,
                    'borrower_name' => $teacher->name,
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
                    LoanItem::create([
                        'loan_id' => $loan->id,
                        'product_id' => $p->id,
                        'name' => $p->name,
                        'quantity' => $p->type === Product::TYPE_SUPPLY ? mt_rand(1, 6) : mt_rand(1, 2),
                        'returned_at' => $returned,
                        'return_condition' => $returned ? ['bueno', 'bueno', 'bueno', 'regular', 'danado'][mt_rand(0, 4)] : null,
                    ]);
                }
            }
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

    private function product(int $n, string $name, string $type, string $area, int $qty, int $repair, int $off, int $min, $locations, Supplier $supplier, User $admin): Product
    {
        $location = $locations->random();
        $cajon = $location->cajones->isNotEmpty() ? $location->cajones->random() : null;

        return Product::updateOrCreate(
            ['barcode' => sprintf('SGIA-DEMO-%04d', $n)],
            [
                'name' => $name,
                'description' => "{$name} (dato de demostración)",
                'type' => $type,
                'quantity' => $qty,
                'quantity_in_repair' => $repair,
                'quantity_written_off' => $off,
                'stock_minimo' => $min,
                'supplier_id' => $supplier->id,
                'cajon_id' => $cajon?->id,
                'location_id' => $location->id,
                'area' => $area,
                'is_active' => true,
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]
        );
    }
}
