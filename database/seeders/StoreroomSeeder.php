<?php

namespace Database\Seeders;

use App\Models\Storeroom;
use Illuminate\Database\Seeder;

class StoreroomSeeder extends Seeder
{
    public function run(): void
    {
        Storeroom::default();
    }
}
