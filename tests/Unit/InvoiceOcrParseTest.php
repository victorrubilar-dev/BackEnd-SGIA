<?php

namespace Tests\Unit;

use App\Services\InvoiceOcrService;
use Tests\TestCase;

/**
 * Líneas tal como las devolvió Tesseract para pruebas-facturas/prueba1..3.
 */
class InvoiceOcrParseTest extends TestCase
{
    /**
     * @return array<int, array{0: string, 1: int, 2: float}>
     */
    private function items(string $text): array
    {
        return array_map(
            fn ($i) => [$i['name'], $i['quantity'], $i['unit_price']],
            app(InvoiceOcrService::class)->parse($text)['items'],
        );
    }

    public function test_quantity_comes_from_total_and_price_wherever_it_is(): void
    {
        $this->assertSame([
            ['PILA DURACELL AAA ALKALINA', 20, 1190.0],
            ['COÍGAFAS DE SEGURIDAD RAPTOR TECH CLAROS', 20, 620.0],
            ['A «e oolciwra SEGURIDAD PELIGRO AMARILLA-NEGRA 100MTS X 48MM', 4, 3468.0],
        ], $this->items(implode("\n", [
            'PILA DURACELL AAA ALKALINA 20 1.190 23.800',
            '20, COÍGAFAS DE SEGURIDAD RAPTOR TECH CLAROS 620,00 12.400 _-',
            'A «e oolciwra SEGURIDAD PELIGRO AMARILLA-NEGRA 100MTS X 48MM 3.468,00 13.872',
        ])));
    }

    public function test_description_on_next_line_format_still_works(): void
    {
        $this->assertSame(
            [['PILA DURACELL AAA ALKALINA', 20, 1190.0]],
            $this->items("20 1234 1.190 23.800\nPILA DURACELL AAA ALKALINA"),
        );
    }

    public function test_totals_and_misread_amounts_are_not_items(): void
    {
        $this->assertSame([], $this->items(implode("\n", [
            '| AO ER] TOTAL 5 2044',
            'MONTO NETO 24.740 24.740',
            'INTA SEGURIDAD PELIGRO 3.463,00 13.87 t -',
            'ANSELL NITRILO HYFLEX 4.632,00 231-602 a',
        ])));
    }

    public function test_readings_are_merged_without_duplicating_items(): void
    {
        $first = "PILA AAA 20 1.190 23.800\nGAFAS 620,00 12.400";
        $second = "PILA AAA 20 1.190 23.800\nCINTA 3.468,00 13.872";

        $this->assertSame(
            [['PILA AAA', 20, 1190.0], ['GAFAS', 20, 620.0], ['CINTA', 4, 3468.0]],
            $this->items("$first\f$second"),
        );
    }
}
