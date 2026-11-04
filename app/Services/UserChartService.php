<?php

namespace App\Services;

use App\Models\ChartTemplate;
use App\Models\User;
use App\Models\UserChart;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard personalizado (REQ-15): hasta 5 gráficos por usuario, uno por posición. Lo que el
 * gráfico no envía lo hereda de su plantilla; la combinación final se valida contra los mismos
 * valores que acepta `customMetric` (MetricsService).
 */
class UserChartService
{
    /** Campos de la consulta que se heredan de la plantilla. */
    private const CONFIG_FIELDS = ['type', 'entity', 'metric', 'group_by', 'period'];

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function create(User $user, array $data): UserChart
    {
        return DB::transaction(function () use ($user, $data) {
            $taken = UserChart::where('user_id', $user->id)->lockForUpdate()->pluck('position')->map(fn ($p) => (int) $p)->all();

            if (count($taken) >= UserChart::MAX_POSITION) {
                throw ValidationException::withMessages([
                    'position' => 'Ya tienes '.UserChart::MAX_POSITION.' gráficos; borra uno o edítalo.',
                ]);
            }

            $position = isset($data['position'])
                ? (int) $data['position']
                : min(array_diff(range(1, UserChart::MAX_POSITION), $taken));
            if (in_array($position, $taken, true)) {
                throw ValidationException::withMessages(['position' => "La posición {$position} ya está ocupada."]);
            }

            $template = ChartTemplate::findOrFail($data['chart_template_id']);

            return UserChart::create([
                'user_id' => $user->id,
                'chart_template_id' => $template->id,
                'position' => $position,
                'is_active' => $data['is_active'] ?? true,
                ...$this->resolveConfig($template, $data),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public function update(UserChart $chart, array $data): UserChart
    {
        return DB::transaction(function () use ($chart, $data) {
            if (isset($data['position'])) {
                $data['position'] = (int) $data['position'];
            }
            if (isset($data['position']) && $data['position'] !== $chart->position) {
                $occupied = UserChart::where('user_id', $chart->user_id)
                    ->where('position', $data['position'])
                    ->whereKeyNot($chart->id)
                    ->exists();
                if ($occupied) {
                    throw ValidationException::withMessages([
                        'position' => "La posición {$data['position']} ya está ocupada; usa PUT /api/me/charts/positions para intercambiar.",
                    ]);
                }
            }

            $template = isset($data['chart_template_id']) && (int) $data['chart_template_id'] !== $chart->chart_template_id
                ? ChartTemplate::findOrFail($data['chart_template_id'])
                : null;

            $chart->fill(array_filter([
                'chart_template_id' => $template?->id,
                'position' => $data['position'] ?? null,
                'is_active' => $data['is_active'] ?? null,
            ], fn ($value) => $value !== null));
            $chart->fill($this->resolveConfig($template, $data, $chart));
            $chart->save();

            return $chart;
        });
    }

    /**
     * Reordena en bloque. La restricción `unique(user_id, position)` es diferida, así que se
     * pueden intercambiar posiciones dentro de la transacción.
     *
     * @param  array<int, array{id: int, position: int}>  $positions
     *
     * @throws ValidationException
     */
    public function reorder(User $user, array $positions): void
    {
        DB::transaction(function () use ($user, $positions) {
            $charts = UserChart::where('user_id', $user->id)->lockForUpdate()->get()->keyBy('id');

            $final = $charts->mapWithKeys(fn (UserChart $chart) => [$chart->id => $chart->position])->all();
            foreach ($positions as $index => $entry) {
                if (! $charts->has($entry['id'])) {
                    throw ValidationException::withMessages(["positions.{$index}.id" => 'El gráfico no existe.']);
                }
                $final[$entry['id']] = (int) $entry['position'];
            }

            if (count($final) !== count(array_unique($final))) {
                throw ValidationException::withMessages([
                    'positions' => 'Dos gráficos quedarían en la misma posición; incluye todos los que cambian.',
                ]);
            }

            foreach ($positions as $entry) {
                $charts[$entry['id']]->update(['position' => (int) $entry['position']]);
            }

            // Verifica la unicidad ya (también dentro de transacciones anidadas) y vuelve a diferirla.
            DB::statement('SET CONSTRAINTS user_charts_user_position_unique IMMEDIATE');
            DB::statement('SET CONSTRAINTS user_charts_user_position_unique DEFERRED');
        });
    }

    /**
     * Configuración final del gráfico: valores actuales (o los de la plantilla al crear o cambiar
     * de plantilla) sobrescritos por los enviados.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function resolveConfig(?ChartTemplate $template, array $data, ?UserChart $current = null): array
    {
        $config = $current?->only([...self::CONFIG_FIELDS, 'custom_title', 'date_from', 'date_to']) ?? [];
        $config['date_from'] = $current?->date_from?->toDateString();
        $config['date_to'] = $current?->date_to?->toDateString();

        if ($template !== null) {
            $defaults = [
                'type' => $template->chart_type,
                'entity' => $template->entity,
                'metric' => $template->metric,
                'group_by' => $template->group_by,
                'period' => $template->period,
            ];
            // Al crear se toma todo de la plantilla; al cambiar de plantilla, solo lo que ella define.
            $config = $current === null
                ? [...$defaults, 'custom_title' => $template->name]
                : [...$config, ...array_filter($defaults, fn ($value) => $value !== null)];
        }

        foreach ([...self::CONFIG_FIELDS, 'custom_title', 'date_from', 'date_to'] as $field) {
            if (array_key_exists($field, $data) && ($data[$field] !== null || in_array($field, ['group_by', 'date_from', 'date_to'], true))) {
                $config[$field] = $data[$field];
            }
        }

        $this->validateConfig($config);

        if ($config['period'] !== 'CUSTOM') {
            $config['date_from'] = null;
            $config['date_to'] = null;
        }

        return $config;
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws ValidationException
     */
    private function validateConfig(array $config): void
    {
        $missing = fn (string $label) => "Falta {$label}: envíalo o elige una plantilla que lo traiga.";
        $errors = [];

        if (empty($config['custom_title'])) {
            $errors['custom_title'] = $missing('el título');
        }
        if (empty($config['type'])) {
            $errors['type'] = $missing('el tipo de gráfico');
        }
        if (empty($config['metric'])) {
            $errors['metric'] = $missing('la métrica');
        }
        if (empty($config['period'])) {
            $errors['period'] = $missing('el período');
        } elseif ($config['period'] === 'CUSTOM' && empty($config['date_from'])) {
            $errors['date_from'] = 'El período CUSTOM requiere date_from (y opcionalmente date_to).';
        }

        $entity = $config['entity'] ?? null;
        if (empty($entity)) {
            $errors['entity'] = $missing('la entidad');
        } elseif (($config['group_by'] ?? null) !== null && ! in_array($config['group_by'], MetricsService::GROUP_BY[$entity], true)) {
            $errors['group_by'] = "group_by \"{$config['group_by']}\" no es válido para {$entity}. Opciones: ".implode(', ', MetricsService::GROUP_BY[$entity]).' (o vacío para la serie en el tiempo).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
