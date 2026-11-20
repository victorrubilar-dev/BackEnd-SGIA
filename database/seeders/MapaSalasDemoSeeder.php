<?php

namespace Database\Seeders;

use App\Models\Cajon;
use App\Models\Estante;
use App\Models\Location;
use App\Models\Zona;
use App\Services\RoomMapService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de prueba del mapa de salas (REQ-22, `docs/mapa-salas.md` § Datos de prueba): medidas, zonas A/B/C como la
 * imagen de referencia, siete estantes y todos los cajones asignados en cada ubicación. Repetible y sin pisar lo que
 * ya existe. Solo fuera de producción.
 */
class MapaSalasDemoSeeder extends Seeder
{
    /** Medidas por tipo de ubicación, en cm (ancho × largo). */
    private const DIMENSIONS = [
        Location::TIPO_SALA => [1000, 600],
        Location::TIPO_PANOL => [1200, 800],
        Location::TIPO_TALLER => [1400, 900],
        Location::TIPO_BODEGA => [1600, 1000],
    ];

    /** Zonas en proporción de la sala: [x, y, ancho, largo]. */
    private const ZONAS = [
        'Zona A' => [0.05, 0.08, 0.42, 0.84],
        'Zona B' => [0.52, 0.08, 0.43, 0.36],
        'Zona C' => [0.52, 0.52, 0.43, 0.40],
    ];

    /** Cajones que se crean por estante cuando la ubicación no tiene ninguno. */
    private const CAJONES_POR_ESTANTE = 2;

    public function __construct(private readonly RoomMapService $map) {}

    public function run(): void
    {
        foreach (Location::orderBy('id')->get() as $location) {
            $this->seedLocation($location);
        }
    }

    public function seedLocation(Location $location): void
    {
        DB::transaction(function () use ($location) {
            if (! $location->hasMapDimensions()) {
                [$width, $height] = self::DIMENSIONS[$location->tipo] ?? self::DIMENSIONS[Location::TIPO_SALA];
                $location->update(['map_width_cm' => $width, 'map_height_cm' => $height]);
            }

            $zonas = $this->seedZonas($location);
            $estantes = $this->seedEstantes($location, $zonas);
            $this->seedCajones($location, $estantes);
        });
    }

    /**
     * @return array<string, Zona>
     */
    private function seedZonas(Location $location): array
    {
        $width = $location->mapWidth();
        $height = $location->mapHeight();
        $zonas = [];

        foreach (self::ZONAS as $nombre => [$x, $y, $w, $h]) {
            $zonas[$nombre] = Zona::firstOrCreate(
                ['location_id' => $location->id, 'nombre' => $nombre],
                [
                    'x' => $this->round($width * $x),
                    'y' => $this->round($height * $y),
                    'width' => $this->round($width * $w),
                    'depth' => $this->round($height * $h),
                ],
            );
        }

        return $zonas;
    }

    /**
     * Tres estantes verticales en la zona A y dos horizontales en B y en C, repartidos dentro de su zona.
     *
     * @param  array<string, Zona>  $zonas
     * @return list<Estante>
     */
    private function seedEstantes(Location $location, array $zonas): array
    {
        $a = $zonas['Zona A'];
        $b = $zonas['Zona B'];
        $c = $zonas['Zona C'];
        $vertical = $this->round(min(2000, $a->depth * 0.6));
        $horizontal = $this->round(min(2000, $b->width * 0.4));

        $planned = [];
        foreach ([1, 2, 3] as $i) {
            $planned[] = ['EST-A'.$i, $a, $vertical, 70, 90, $a->x + $a->width * $i / 4 - 35, $a->y + ($a->depth - $vertical) / 2];
        }
        foreach ([[$b, 'B', 0.2], [$c, 'C', 0.55]] as [$zona, $letra, $top]) {
            foreach ([1, 2] as $i) {
                $planned[] = ["EST-{$letra}{$i}", $zona, $horizontal, 80, 0, $zona->x + $zona->width * $i / 3 - $horizontal / 2, $zona->y + $zona->depth * $top];
            }
        }

        foreach ($planned as [$codigo, $zona, $width, $depth, $rotation, $x, $y]) {
            if (Estante::where('location_id', $location->id)->where('codigo', $codigo)->exists()) {
                continue;
            }

            $position = [$this->round($x), $this->round($y)];
            $rect = $this->map->occupiedRect($position[0], $position[1], $width, $depth, $rotation);
            $fits = $this->map->contains($this->map->roomRect($location), $rect)
                && $this->map->overlappingEstante($location->id, $rect) === null;

            if (! $fits) {
                $position = $this->map->placeEstante($location, $width, $depth, $rotation, $zona);
                if ($position === null) {
                    continue;
                }
            }

            Estante::create([
                'location_id' => $location->id,
                'zona_id' => $zona->id,
                'codigo' => $codigo,
                'x' => $position[0],
                'y' => $position[1],
                'width' => $width,
                'depth' => $depth,
                'rotation' => $rotation,
            ]);
        }

        return Estante::where('location_id', $location->id)->orderBy('codigo')->get()->all();
    }

    /**
     * Sin cajones, crea dos por estante; los cajones sueltos se reparten en orden entre los estantes de la sala.
     *
     * @param  list<Estante>  $estantes
     */
    private function seedCajones(Location $location, array $estantes): void
    {
        if ($estantes === []) {
            return;
        }

        if (! $location->cajones()->exists()) {
            foreach ($estantes as $estante) {
                $sufijo = substr($estante->codigo, strrpos($estante->codigo, '-') + 1);
                foreach (range(1, self::CAJONES_POR_ESTANTE) as $n) {
                    Cajon::create([
                        'location_id' => $location->id,
                        'estante_id' => $estante->id,
                        'codigo' => "{$sufijo}-{$n}",
                        'descripcion' => "Cajón {$n} del estante {$estante->codigo}",
                    ]);
                }
            }

            return;
        }

        $sueltos = $location->cajones()->whereNull('estante_id')->orderBy('codigo')->get();
        foreach ($sueltos->values() as $i => $cajon) {
            $cajon->update(['estante_id' => $estantes[$i % count($estantes)]->id]);
        }
    }

    private function round(float $value): int
    {
        return (int) (round($value / RoomMapService::GRID_CM) * RoomMapService::GRID_CM);
    }
}
