<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION fn_generar_codigo_sgia(p_prefijo VARCHAR(3), p_carrera VARCHAR(3))
RETURNS VARCHAR AS $$
DECLARE
    v_codigo_formateado VARCHAR(20);
BEGIN
    UPDATE codigos
    SET indice = indice + 1
    WHERE prefijo = UPPER(TRIM(p_prefijo))
      AND carrera = UPPER(TRIM(p_carrera))
    RETURNING prefijo || '-' || carrera || '-' || LPAD(indice::TEXT, 6, '0')
    INTO v_codigo_formateado;

    IF v_codigo_formateado IS NULL THEN
        RAISE EXCEPTION 'La combinación Prefijo (%) y Carrera (%) no existe en la tabla CODIGOS.', p_prefijo, p_carrera;
    END IF;

    RETURN v_codigo_formateado;
END;
$$ LANGUAGE plpgsql;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS fn_generar_codigo_sgia(VARCHAR, VARCHAR);');
    }
};
