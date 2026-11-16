<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Campos del maestro de artículos de Oracle en los productos: `master_code` (columna `Art`,
 * p. ej. OF33075) y la descripción de categoría (`Descr Categoria`) como catálogo editable.
 * Ambos son opcionales. Valores iniciales tomados de "Maestro Alumnos.xlsx".
 */
return new class extends Migration
{
    /** @var list<string> */
    private const INITIAL_CATEGORIES = [
        'INSUMOS.MATERIALES',
        'ACTIVO FIJO',
        'OBRAS EN CURSO',
        'MKT.FOLLETERIA Y OTROS SIMILARES',
        'MKT.MARKETING CORPORATIVO',
        'INSUMOS.ABARROTES',
        'TI.SOFTWARE',
        'SERVICIOS.SERVICIOS DE PERSONAS',
        'MANTENCIONES.INFRAESTRUCTURA',
        'MANTENCIONES.MAQUINARIA Y EQUIPOS',
        'SERVICIOS.ENTRADA FERIA Y EVENTOS',
        'TI.LICENCIAS ADMINISTRATIVAS',
        'SERVICIOS.ASEO',
        'TI.LICENCIAS ACADEMICAS',
        'TI.RECURSOS DIGITALES ACADEMICOS',
        'SERVICIOS.COFFE BREACK',
        'RRHH.CAPACITACION AL PERSONAL',
        'ARRIENDOS.INMUEBLES',
        'MANTENCIONES.OPERACION EDIFICIO',
        'RRHH.ROPA DE TRABAJO',
        'SUSCRIPCIONES.CON FINES ADMINISTRATIVOS',
        'TI.SOFTWARE EN DESARROLLO',
        'TI.SISTEMAS DESARROLLO ADM',
        'SERVICIOS.GASTOS NOTARIALES',
        'SERVICIOS.FOTOCOPIA',
        'TI.RECURSOS DIGITALES ADMINISTRATIVOS',
        'MANTENCIONES.EQUIPAMIENTO COMPUTACIONAL',
        'ARRIENDOS.INSTALACIONES',
        'ARRIENDOS.MAQUINARIAS',
        'COMBUSTIBLES',
        'ARRIENDOS.VEHICULOS',
        'SERVICIOS.VIGILANCIA',
        'SERVICIOS.ABOGADOS',
        'MKT.PUBLICIDAD REVISTAS - DIARIOS',
        'SERVICIOS.AUDITORIA',
        'MKT.PRODUCCION DE EVENTOS',
        'TI.TELECUMINICACION Y DATOS',
        'SERVICIOS.AGUA',
        'SERVICIOS.COMBUSTIBLE',
        'ASESORIAS.INFORMATICAS',
        'MKT.MARKETING DIRECTO',
        'MKT.PREMIOS Y CONCURSOS',
        'TI.ENTORNO VIRTUAL',
        'SERVICIOS.GAS',
        'RRHH.BECAS AL PERSONAL',
        'MKT.PUBLICIDAD EN TV- CINE',
        'SERVICIOS.PASAJES Y TRASLADOS NACIONAL',
        'PROYECTOS.GASTOS EXTERNOS',
        'MKT.AGENDAS Y CREDENCIALES',
        'SUSCRIPCIONES.CON FINES ACADEMICO',
        'ASESORIAS.ACREDITACION',
        'SERVICIOS.RADIO TAXI',
        'MKT.ARTICULOS DE MERCHANDISING',
        'INSUMOS.LIBRERIA',
        'SERVICIOS.TRANSPORTE DE VALORES',
        'MKT.AUSPICIOS',
        'SERVICIOS.CAMPOS CLINICOS',
        'SERVICIOS.RESIDUOS PELIGROSOS',
        'ASESORIAS.ADMINISTRATIVAS',
        'SERVICIOS.ALUMNO EN PRACTICA',
        'ARRIENDOS.LEASING IFRS',
        'ASESORIAS.ACADEMICAS',
    ];

    public function up(): void
    {
        Schema::create('oracle_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('oracle_categories')->insert(array_map(
            fn (string $name) => ['name' => $name, 'created_at' => $now, 'updated_at' => $now],
            self::INITIAL_CATEGORIES,
        ));

        Schema::table('products', function (Blueprint $table) {
            $table->string('master_code', 30)->nullable()->unique();
            $table->foreignId('oracle_category_id')->nullable()->constrained('oracle_categories')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('oracle_category_id');
            $table->dropUnique(['master_code']);
            $table->dropColumn('master_code');
        });

        Schema::dropIfExists('oracle_categories');
    }
};
