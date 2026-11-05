<?php

namespace Database\Factories;

use App\Models\IncidentReport;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IncidentReport>
 */
class IncidentReportFactory extends Factory
{
    protected $model = IncidentReport::class;

    public function definition(): array
    {
        return [
            'product_id' => Product::factory(),
            'code' => 'NV-'.date('Y').'-'.strtoupper(fake()->unique()->bothify('??????')),
            'reported_by' => User::factory(),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'severity' => IncidentReport::SEVERITY_MEDIUM,
            'status' => IncidentReport::STATUS_REPORTED,
            'attachment' => null,
            'attachment_original_name' => null,
        ];
    }

    /** Informe del formulario del docente (no legacy). */
    public function teacherForm(): static
    {
        return $this->state(fn () => [
            'teacher_name' => fake()->name(),
            'subject' => 'Redes',
            'report_date' => today()->toDateString(),
            'location_id' => Location::factory(),
            'problems' => [['type' => IncidentReport::PROBLEM_OTHER, 'description' => 'Proyector sin imagen.']],
            'suggestion' => null,
        ]);
    }

    public function critical(): static
    {
        return $this->state(fn () => [
            'severity' => IncidentReport::SEVERITY_HIGH,
        ]);
    }
}
