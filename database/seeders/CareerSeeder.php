<?php

namespace Database\Seeders;

use App\Models\Career;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class CareerSeeder extends Seeder
{
    /** @var array<string, array{0: string, 1: string}> code => [name, level] */
    public const CAREERS = [
        'ISD' => ['Telecomunicaciones', Career::LEVEL_PROFESSIONAL],
        'II' => ['Ingeniería en Informática', Career::LEVEL_PROFESSIONAL],
        'IC' => ['Ingeniería en Ciberseguridad', Career::LEVEL_PROFESSIONAL],
        'AP' => ['Analista Programador', Career::LEVEL_TECHNICIAN],
    ];

    public function run(): void
    {
        $existing = Career::all();

        foreach (self::CAREERS as $code => [$name, $level]) {
            $career = Career::where('code', $code)->first()
                ?? $existing->first(fn (Career $c) => $this->normalize($c->name) === $this->normalize($name));

            if ($career) {
                $career->update(['code' => $code]);
            } else {
                Career::create(['name' => $name, 'level' => $level, 'code' => $code]);
            }
        }
    }

    private function normalize(string $value): string
    {
        return Str::lower(Str::ascii($value));
    }
}
