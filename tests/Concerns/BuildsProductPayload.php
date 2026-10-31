<?php

namespace Tests\Concerns;

use App\Models\Cajon;
use App\Models\Career;
use App\Models\Category;

/**
 * Datos de catálogo que exige el alta de productos con unidades (equipo/maqueta).
 */
trait BuildsProductPayload
{
    protected function careerWithCode(string $code = 'IC'): Career
    {
        return Career::where('code', $code)->first() ?? Career::factory()->create(['code' => $code]);
    }

    /**
     * @return array{category_id: int, career_id: int, cajon_id: int}
     */
    protected function unitPayload(string $careerCode = 'IC'): array
    {
        return [
            'category_id' => Category::factory()->create()->id,
            'career_id' => $this->careerWithCode($careerCode)->id,
            'cajon_id' => Cajon::factory()->create()->id,
        ];
    }
}
