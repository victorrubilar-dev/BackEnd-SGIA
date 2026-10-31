<?php

namespace Database\Seeders;

use App\Models\ChartTemplate;
use Illuminate\Database\Seeder;

class ChartTemplateSeeder extends Seeder
{
    /** @var array<string, array{0: string, 1: string}> code => [nombre, tipo de visualización] */
    public const TEMPLATES = [
        'bar' => ['Gráfico de barras', 'bar'],
        'line' => ['Gráfico de líneas', 'line'],
        'pie' => ['Gráfico circular', 'pie'],
        'kpi' => ['Indicador (KPI)', 'kpi'],
    ];

    public function run(): void
    {
        foreach (self::TEMPLATES as $code => [$name, $type]) {
            ChartTemplate::updateOrCreate(['code' => $code], ['name' => $name, 'visualization_type' => $type]);
        }
    }
}
