<?php

namespace Tests\Unit;

use App\Services\RoomMapService;
use PHPUnit\Framework\TestCase;

class RoomMapServiceTest extends TestCase
{
    private RoomMapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoomMapService;
    }

    public function test_rotation_90_swaps_width_and_depth(): void
    {
        $this->assertSame([10, 20, 100, 40], $this->service->occupiedRect(10, 20, 100, 40, 0));
        $this->assertSame([10, 20, 40, 100], $this->service->occupiedRect(10, 20, 100, 40, 90));
    }

    public function test_touching_edges_do_not_overlap(): void
    {
        $this->assertFalse($this->service->intersects([0, 0, 100, 40], [100, 0, 100, 40]));
        $this->assertFalse($this->service->intersects([0, 0, 100, 40], [0, 40, 100, 40]));
        $this->assertTrue($this->service->intersects([0, 0, 100, 40], [99, 39, 10, 10]));
    }

    public function test_contains_checks_room_limits(): void
    {
        $room = [0, 0, 1000, 600];
        $this->assertTrue($this->service->contains($room, [900, 560, 100, 40]));
        $this->assertFalse($this->service->contains($room, [901, 560, 100, 40]));
        $this->assertFalse($this->service->contains($room, [-1, 0, 100, 40]));
    }

    public function test_first_free_slot_scans_top_to_bottom_left_to_right(): void
    {
        $room = [0, 0, 300, 200];

        $this->assertSame([0, 0], $this->service->firstFreeSlot($room, 100, 40, []));
        $this->assertSame([100, 0], $this->service->firstFreeSlot($room, 100, 40, [[0, 0, 100, 40]]));
        // Borde que no cae en la rejilla: se sube al siguiente múltiplo de 10.
        $this->assertSame([110, 0], $this->service->firstFreeSlot($room, 100, 40, [[0, 0, 105, 40]]));
        // Fila llena: baja bajo el obstáculo más bajo que lo impide.
        $this->assertSame([0, 40], $this->service->firstFreeSlot($room, 100, 40, [[0, 0, 300, 40]]));
    }

    public function test_first_free_slot_matches_a_full_grid_scan(): void
    {
        $area = [0, 0, 400, 300];
        $obstacles = [[0, 0, 130, 45], [150, 0, 90, 120], [260, 30, 140, 60], [0, 60, 70, 200]];

        $expected = null;
        for ($y = 0; $y <= 300 - 50 && $expected === null; $y += 10) {
            for ($x = 0; $x <= 400 - 120; $x += 10) {
                $hit = array_filter($obstacles, fn ($o) => $this->service->intersects([$x, $y, 120, 50], $o));
                if ($hit === []) {
                    $expected = [$x, $y];
                    break;
                }
            }
        }

        $this->assertSame($expected, $this->service->firstFreeSlot($area, 120, 50, $obstacles));
    }

    public function test_first_free_slot_inside_an_offset_area(): void
    {
        $this->assertSame([55, 35], $this->service->firstFreeSlot([55, 35, 200, 200], 100, 40, []));
        $this->assertSame([155, 35], $this->service->firstFreeSlot([55, 35, 200, 200], 100, 40, [[0, 0, 150, 80]]));
    }

    public function test_no_slot_when_it_does_not_fit(): void
    {
        $this->assertNull($this->service->firstFreeSlot([0, 0, 100, 100], 120, 40, []));
        $this->assertNull($this->service->firstFreeSlot([0, 0, 100, 100], 100, 40, [[0, 0, 100, 100]]));
    }
}
