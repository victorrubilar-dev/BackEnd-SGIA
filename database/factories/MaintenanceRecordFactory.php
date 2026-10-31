<?php

namespace Database\Factories;

use App\Models\EquipmentUnit;
use App\Models\MaintenanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MaintenanceRecord>
 */
class MaintenanceRecordFactory extends Factory
{
    protected $model = MaintenanceRecord::class;

    public function definition(): array
    {
        return [
            'equipment_unit_id' => EquipmentUnit::factory(),
            'responsible_id' => User::factory(),
            'maintenance_type' => MaintenanceRecord::TYPE_PREVENTIVE,
        ];
    }
}
