<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->foreignId('storeroom_id')->nullable()->constrained('storerooms')->restrictOnDelete();
            $table->string('building', 10)->nullable();
            $table->integer('floor')->nullable();
        });

        $storeroomId = DB::table('storerooms')->where('code', 'PAN-TEMUCO')->value('id');
        if ($storeroomId === null) {
            $storeroomId = DB::table('storerooms')->insertGetId([
                'name' => 'Pañol Temuco', 'code' => 'PAN-TEMUCO', 'general_location' => 'Sede Temuco',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('locations')->whereNull('storeroom_id')->update(['storeroom_id' => $storeroomId]);
        DB::statement('ALTER TABLE locations ALTER COLUMN storeroom_id SET NOT NULL');

        Schema::table('cajones', function (Blueprint $table) {
            $table->string('barcode', 100)->nullable()->unique();
            // MODELS.MD: estantes.sala_id es RESTRICT.
            $table->dropForeign(['location_id']);
            $table->foreign('location_id')->references('id')->on('locations')->restrictOnDelete();
        });

        foreach (DB::table('cajones')->whereNull('barcode')->pluck('id') as $id) {
            do {
                $code = 'EST-'.str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
            } while (DB::table('cajones')->where('barcode', $code)->exists());
            DB::table('cajones')->where('id', $id)->update(['barcode' => $code]);
        }
        DB::statement('ALTER TABLE cajones ALTER COLUMN barcode SET NOT NULL');
    }

    public function down(): void
    {
        Schema::table('cajones', function (Blueprint $table) {
            $table->dropForeign(['location_id']);
            $table->foreign('location_id')->references('id')->on('locations')->cascadeOnDelete();
            $table->dropColumn('barcode');
        });
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('storeroom_id');
            $table->dropColumn(['building', 'floor']);
        });
    }
};
