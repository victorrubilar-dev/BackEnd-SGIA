<?php

namespace Database\Factories;

use App\Models\Student;
use App\Rules\ChileanRut;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Student>
 */
class StudentFactory extends Factory
{
    protected $model = Student::class;

    public function definition(): array
    {
        $body = (string) fake()->unique()->numberBetween(10000000, 29999999);

        return [
            'rut' => $body.'-'.ChileanRut::checkDigit($body),
            'full_name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
        ];
    }
}
