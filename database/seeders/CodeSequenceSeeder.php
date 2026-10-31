<?php

namespace Database\Seeders;

use App\Models\Career;
use App\Models\CodeSequence;
use Illuminate\Database\Seeder;

class CodeSequenceSeeder extends Seeder
{
    /** No reinicia `current_index` si la combinación ya existe. */
    public function run(): void
    {
        foreach (CodeSequence::PREFIXES as $prefix => $name) {
            foreach (Career::CODES as $career) {
                CodeSequence::firstOrCreate(
                    ['prefix' => $prefix, 'career' => $career],
                    ['name' => $name, 'current_index' => 0]
                );
            }
        }
    }
}
