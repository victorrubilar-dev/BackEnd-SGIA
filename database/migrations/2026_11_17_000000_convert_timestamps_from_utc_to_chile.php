<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La app pasa de UTC a America/Santiago. Las columnas `timestamp` no guardan zona, así que
 * las fechas ya escritas en UTC se convierten para no leerse 3 o 4 horas adelantadas.
 * Las columnas `date` y `time` no se tocan.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->shift('UTC', 'America/Santiago');
    }

    public function down(): void
    {
        $this->shift('America/Santiago', 'UTC');
    }

    private function shift(string $from, string $to): void
    {
        $columns = DB::select(<<<'SQL'
            select table_name, column_name
            from information_schema.columns
            where table_schema = current_schema()
              and data_type = 'timestamp without time zone'
        SQL);

        foreach (collect($columns)->groupBy('table_name') as $table => $tableColumns) {
            $sets = $tableColumns
                ->map(fn ($c) => sprintf('"%s" = ("%s" at time zone ?) at time zone ?', $c->column_name, $c->column_name))
                ->implode(', ');

            $bindings = $tableColumns->flatMap(fn () => [$from, $to])->all();

            DB::update(sprintf('update "%s" set %s', $table, $sets), $bindings);
        }
    }
};
