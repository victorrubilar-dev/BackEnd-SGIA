<?php

namespace App\Services;

use App\Models\Estante;
use App\Models\Location;
use App\Models\Zona;

/**
 * Geometría del plano de una sala (REQ-22, `docs/mapa-salas.md`). Todo en centímetros enteros, con
 * rectángulos alineados a los ejes `[x, y, ancho, alto]` y el origen en la esquina superior izquierda.
 */
class RoomMapService
{
    /** Paso de la rejilla con que se busca el primer hueco libre. */
    public const GRID_CM = 10;

    /**
     * Rectángulo ocupado por un mueble: con rotación 90 se intercambian largo y fondo.
     *
     * @return array{int, int, int, int}
     */
    public function occupiedRect(int $x, int $y, int $width, int $depth, int $rotation): array
    {
        return $rotation === 90 ? [$x, $y, $depth, $width] : [$x, $y, $width, $depth];
    }

    /**
     * Intersección con área positiva: bordes que se tocan no cuentan.
     *
     * @param  array{int, int, int, int}  $a
     * @param  array{int, int, int, int}  $b
     */
    public function intersects(array $a, array $b): bool
    {
        return $a[0] < $b[0] + $b[2] && $b[0] < $a[0] + $a[2]
            && $a[1] < $b[1] + $b[3] && $b[1] < $a[1] + $a[3];
    }

    /**
     * @param  array{int, int, int, int}  $outer
     * @param  array{int, int, int, int}  $inner
     */
    public function contains(array $outer, array $inner): bool
    {
        return $inner[0] >= $outer[0] && $inner[1] >= $outer[1]
            && $inner[0] + $inner[2] <= $outer[0] + $outer[2]
            && $inner[1] + $inner[3] <= $outer[1] + $outer[3];
    }

    /**
     * Primera posición libre dentro de `$area` recorriendo una rejilla de 10 cm (de arriba abajo y de
     * izquierda a derecha). Solo se prueban el borde del área y los bordes de los obstáculos llevados a
     * la rejilla: da el mismo resultado que recorrer todos los puntos, sin el costo.
     *
     * @param  array{int, int, int, int}  $area
     * @param  list<array{int, int, int, int}>  $obstacles
     * @return array{int, int}|null `[x, y]`
     */
    public function firstFreeSlot(array $area, int $width, int $height, array $obstacles): ?array
    {
        [$ax, $ay, $aw, $ah] = $area;
        if ($width > $aw || $height > $ah) {
            return null;
        }

        $xs = $this->candidates($ax, $ax + $aw - $width, array_map(fn (array $r) => $r[0] + $r[2], $obstacles));
        $ys = $this->candidates($ay, $ay + $ah - $height, array_map(fn (array $r) => $r[1] + $r[3], $obstacles));

        foreach ($ys as $y) {
            foreach ($xs as $x) {
                $rect = [$x, $y, $width, $height];
                $free = true;
                foreach ($obstacles as $obstacle) {
                    if ($this->intersects($rect, $obstacle)) {
                        $free = false;
                        break;
                    }
                }
                if ($free) {
                    return [$x, $y];
                }
            }
        }

        return null;
    }

    /**
     * @param  list<int>  $edges
     * @return list<int>
     */
    private function candidates(int $start, int $max, array $edges): array
    {
        $values = [$start];
        foreach ($edges as $edge) {
            $value = $start + (int) (ceil(max(0, $edge - $start) / self::GRID_CM) * self::GRID_CM);
            if ($value <= $max) {
                $values[] = $value;
            }
        }
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    /**
     * @return array{int, int, int, int}
     */
    public function roomRect(Location $location, ?int $width = null, ?int $height = null): array
    {
        return [0, 0, $width ?? $location->mapWidth(), $height ?? $location->mapHeight()];
    }

    /**
     * @return array{int, int, int, int}
     */
    public function estanteRect(Estante $estante): array
    {
        return $this->occupiedRect($estante->x, $estante->y, $estante->width, $estante->depth, $estante->rotation);
    }

    /**
     * @return array{int, int, int, int}
     */
    public function zonaRect(Zona $zona): array
    {
        return [$zona->x, $zona->y, $zona->width, $zona->depth];
    }

    /** Primer estante de la sala que se superpone con `$rect` (ignorando `$ignoreId`). */
    public function overlappingEstante(int $locationId, array $rect, ?int $ignoreId = null): ?Estante
    {
        return Estante::where('location_id', $locationId)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->orderBy('codigo')
            ->get()
            ->first(fn (Estante $other) => $this->intersects($rect, $this->estanteRect($other)));
    }

    /**
     * Posición para un estante nuevo sin `x`/`y`: dentro de la zona si se indicó y hay lugar; si no, en la sala.
     *
     * @return array{int, int}|null
     */
    public function placeEstante(Location $location, int $width, int $depth, int $rotation, ?Zona $zona = null): ?array
    {
        [, , $w, $h] = $this->occupiedRect(0, 0, $width, $depth, $rotation);
        $obstacles = $location->estantes()->get()->map(fn (Estante $e) => $this->estanteRect($e))->values()->all();
        $room = $this->roomRect($location);

        if ($zona !== null) {
            $area = $this->clip($this->zonaRect($zona), $room);
            if ($area !== null && ($slot = $this->firstFreeSlot($area, $w, $h, $obstacles)) !== null) {
                return $slot;
            }
        }

        return $this->firstFreeSlot($room, $w, $h, $obstacles);
    }

    /**
     * Mensaje si achicar la sala a `$width` × `$height` deja afuera un estante o una zona; `null` si cabe todo.
     */
    public function shrinkConflict(Location $location, int $width, int $height): ?string
    {
        $room = $this->roomRect($location, $width, $height);

        $estante = $location->estantes()->orderBy('codigo')->get()
            ->first(fn (Estante $e) => ! $this->contains($room, $this->estanteRect($e)));
        if ($estante !== null) {
            return "El estante {$estante->codigo} quedaría fuera de la sala.";
        }

        $zona = $location->zonas()->orderBy('nombre')->get()
            ->first(fn (Zona $z) => ! $this->contains($room, $this->zonaRect($z)));

        return $zona !== null ? "La zona {$zona->nombre} quedaría fuera de la sala." : null;
    }

    /**
     * @param  array{int, int, int, int}  $a
     * @param  array{int, int, int, int}  $b
     * @return array{int, int, int, int}|null
     */
    private function clip(array $a, array $b): ?array
    {
        $x = max($a[0], $b[0]);
        $y = max($a[1], $b[1]);
        $right = min($a[0] + $a[2], $b[0] + $b[2]);
        $bottom = min($a[1] + $a[3], $b[1] + $b[3]);

        return $right > $x && $bottom > $y ? [$x, $y, $right - $x, $bottom - $y] : null;
    }
}
