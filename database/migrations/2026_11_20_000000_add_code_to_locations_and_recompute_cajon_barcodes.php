<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Códigos de ubicación (`docs/codigos-ubicacion.md`, Fase 1): `locations.code` corto e inmutable
 * y `cajones.barcode` = `{locations.code}-{codigo normalizado}` en lugar del `EST-…` aleatorio.
 *
 * La regla de sugerencia y la normalización se copian aquí (y no se llama al modelo) para que
 * la migración no cambie si el modelo cambia después.
 */
return new class extends Migration
{
    /** Códigos aprobados para las salas existentes, por nombre exacto. */
    private const APPROVED = [
        'Pañol Central' => 'PCENTRAL',
        'Laboratorio E-301' => 'E301',
        'Taller T-102' => 'T102',
        'Pañol de Redes' => 'PREDES',
        'Bodega Edificio E' => 'BODEGAE',
        'Laboratorio de Redes L-204' => 'L204',
        'Laboratorio de Ciberseguridad L-206' => 'L206',
        'Laboratorio de Programación E-205' => 'E205',
    ];

    private const STOPWORDS = ['DE', 'DEL', 'LA', 'EL', 'LOS', 'LAS', 'Y'];

    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('code', 10)->nullable()->unique();
        });

        $locations = DB::table('locations')->orderBy('id')->get(['id', 'nombre', 'sala'])->all();

        foreach ($this->assignCodes($locations) as $id => $code) {
            DB::table('locations')->where('id', $id)->update(['code' => $code]);
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->string('code', 10)->nullable(false)->change();
        });

        $this->recomputeCajonBarcodes();
    }

    public function down(): void
    {
        foreach (DB::table('cajones')->orderBy('id')->pluck('id') as $id) {
            DB::table('cajones')->where('id', $id)->update([
                'barcode' => 'EST-'.str_pad((string) $id, 8, '0', STR_PAD_LEFT),
            ]);
        }

        Schema::table('locations', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }

    /**
     * Código de cada sala: primero las de la tabla aprobada (para que una sugerida no les quite
     * el código) y luego la regla de sugerencia con sufijo numérico si choca.
     *
     * @param  array<int, object{id: int, nombre: ?string, sala: ?string}>  $locations
     * @return array<int, string> id => code
     */
    private function assignCodes(array $locations): array
    {
        $codes = [];
        $used = [];

        foreach ($locations as $location) {
            $code = self::APPROVED[$location->nombre] ?? null;
            if ($code !== null && ! isset($used[$code])) {
                $codes[$location->id] = $code;
                $used[$code] = true;
            }
        }

        foreach ($locations as $location) {
            if (isset($codes[$location->id])) {
                continue;
            }

            $code = $this->unique($this->baseCode((string) ($location->nombre ?? $location->sala)), $used, 10);
            $codes[$location->id] = $code;
            $used[$code] = true;
        }

        return $codes;
    }

    private function recomputeCajonBarcodes(): void
    {
        $codes = DB::table('locations')->pluck('code', 'id');
        $cajones = DB::table('cajones')->orderBy('id')->get(['id', 'codigo', 'location_id']);

        // Libera los valores actuales para no chocar con el índice único durante el recálculo.
        DB::table('cajones')->update(['barcode' => DB::raw("'TMP-' || id")]);

        $usedByLocation = [];

        foreach ($cajones as $cajon) {
            $segment = $this->normalize((string) $cajon->codigo);
            if ($segment === '') {
                $segment = 'CAJON';
            }
            if (strlen($segment) > 12) {
                $segment = rtrim(substr($segment, 0, 12), '-');
            }

            $segment = $this->unique($segment, $usedByLocation[$cajon->location_id] ?? [], 12);
            $usedByLocation[$cajon->location_id][$segment] = true;

            DB::table('cajones')->where('id', $cajon->id)->update([
                'barcode' => $codes[$cajon->location_id].'-'.$segment,
            ]);
        }
    }

    /**
     * @param  array<string, bool>  $used
     */
    private function unique(string $base, array $used, int $max): string
    {
        $code = $base;
        for ($suffix = 2; isset($used[$code]); $suffix++) {
            $code = substr($base, 0, $max - strlen((string) $suffix)).$suffix;
        }

        return $code;
    }

    private function baseCode(string $nombre): string
    {
        $name = Str::upper(Str::ascii($nombre));

        if (preg_match('/(?<![A-Z0-9])([A-Z]{1,2})[- ]?(\d{2,4})(?![0-9])/', $name, $match)) {
            return $match[1].$match[2];
        }

        $words = array_values(array_filter(
            preg_split('/[^A-Z0-9]+/', $name) ?: [],
            fn (string $word) => $word !== '' && ! in_array($word, self::STOPWORDS, true),
        ));

        $code = match (count($words)) {
            0 => '',
            1 => substr($words[0], 0, 8),
            default => substr($words[0][0].implode('', array_slice($words, 1)), 0, 10),
        };

        return strlen($code) >= 2 ? $code : 'SALA';
    }

    private function normalize(string $codigo): string
    {
        return trim((string) preg_replace('/[^A-Z0-9]+/', '-', Str::upper(Str::ascii($codigo))), '-');
    }
};
