<?php

namespace App\Support;

use DateTimeInterface;

/**
 * Una hoja de `XlsxWriter::workbook()`.
 */
final class XlsxSheet
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<array<int, string|int|float|bool|DateTimeInterface|null>>  $rows
     * @param  array<int, string>  $types  Tipo por índice de columna (`XlsxWriter::DATE`, `DATETIME`, `MONEY`, `INTEGER`, `TEXT`).
     * @param  array<int, float|int>  $widths  Ancho (en caracteres) por índice de columna.
     * @param  list<int>  $totals  Columnas con fila de totales al pie (`SUBTOTAL(109, …)`, fuera del autofiltro).
     */
    public function __construct(
        public readonly string $name,
        public readonly array $headers,
        public readonly iterable $rows,
        public readonly array $types = [],
        public readonly array $widths = [],
        public readonly bool $filter = true,
        public readonly bool $freezeHeader = true,
        public readonly array $totals = [],
    ) {}
}
