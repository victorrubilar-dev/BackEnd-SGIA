<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->restrictOnDelete();
            $table->foreignId('cajon_id')->constrained('cajones')->restrictOnDelete();
            $table->string('code', 100)->unique();
            $table->string('serial_number', 100)->nullable()->unique();
            $table->string('status', 50)->default('disponible');
            $table->timestamp('last_maintenance_at')->nullable();
            $table->timestamp('next_maintenance_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['product_id', 'status']);
        });
        DB::statement('CREATE INDEX equipment_units_next_maintenance_idx ON equipment_units (next_maintenance_at) WHERE next_maintenance_at IS NOT NULL');

        $this->backfill();
    }

    /**
     * Crea las unidades de cada producto `equipo` a partir de sus contadores.
     * Los códigos heredados quedan como `{barcode}-{nnn}`.
     */
    private function backfill(): void
    {
        $products = DB::table('products')->where('type', 'equipo')->orderBy('id')->get();
        if ($products->isEmpty()) {
            return;
        }

        $fallbackCajonId = null;
        $now = now();

        foreach ($products as $product) {
            $borrowed = (int) DB::table('loan_items')
                ->join('loans', 'loans.id', '=', 'loan_items.loan_id')
                ->where('loan_items.product_id', $product->id)
                ->whereIn('loans.status', ['en_proceso', 'procesado'])
                ->whereNull('loan_items.returned_at')
                ->sum('loan_items.quantity');

            $plan = [
                'disponible' => (int) $product->quantity,
                'prestado' => $borrowed,
                'en_reparacion' => (int) $product->quantity_in_repair,
                'dado_de_baja' => (int) $product->quantity_written_off,
            ];
            if (array_sum($plan) === 0) {
                continue;
            }

            $cajonId = $product->cajon_id ?? ($fallbackCajonId ??= $this->fallbackCajonId());

            $n = 0;
            $rows = [];
            foreach ($plan as $status => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $n++;
                    $rows[] = [
                        'product_id' => $product->id,
                        'cajon_id' => $cajonId,
                        'code' => sprintf('%s-%03d', $product->barcode, $n),
                        'status' => $status,
                        'created_by' => $product->created_by,
                        'updated_by' => $product->updated_by,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('equipment_units')->insert($chunk);
            }
        }
    }

    private function fallbackCajonId(): int
    {
        $storeroomId = DB::table('storerooms')->where('code', 'PAN-TEMUCO')->value('id');
        $now = now();

        $locationId = DB::table('locations')->where('nombre', 'Sin asignar')->value('id')
            ?? DB::table('locations')->insertGetId([
                'nombre' => 'Sin asignar', 'sala' => 'Sin asignar', 'tipo' => 'bodega',
                'descripcion' => 'Ubicación temporal de equipos migrados sin cajón',
                'storeroom_id' => $storeroomId, 'created_at' => $now, 'updated_at' => $now,
            ]);

        return DB::table('cajones')->where('location_id', $locationId)->where('codigo', 'SIN-ASIGNAR')->value('id')
            ?? DB::table('cajones')->insertGetId([
                'codigo' => 'SIN-ASIGNAR', 'barcode' => 'EST-SIN-ASIGNAR', 'location_id' => $locationId,
                'created_at' => $now, 'updated_at' => $now,
            ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_units');
    }
};
