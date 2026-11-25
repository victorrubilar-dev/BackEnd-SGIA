<?php

namespace App\Reports;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hoja de datos de un reporte: la fuente (consulta o lista), cómo se convierte cada registro en fila
 * y el tipo y ancho de cada columna (`XlsxWriter::DATE`, `MONEY`…).
 */
final class ReportSheet
{
    /**
     * @param  list<string>  $headers
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|iterable<mixed>  $source  Una consulta se lee por bloques (`lazy`).
     * @param  Closure(mixed): array<int, mixed>  $map  Registro → fila; con `$expand`, registro → lista de filas.
     * @param  array<int, string>  $types
     * @param  array<int, float|int>  $widths
     */
    public function __construct(
        public readonly string $name,
        public readonly array $headers,
        public readonly Builder|iterable $source,
        public readonly Closure $map,
        public readonly array $types = [],
        public readonly array $widths = [],
        public readonly bool $expand = false,
    ) {}

    /**
     * Hoja con una fila por cada fila devuelta por `$map` (ítems de una solicitud, unidades de un préstamo).
     *
     * @param  list<string>  $headers
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|iterable<mixed>  $source
     * @param  Closure(mixed): iterable<array<int, mixed>>  $map
     * @param  array<int, string>  $types
     * @param  array<int, float|int>  $widths
     */
    public static function expanded(string $name, array $headers, Builder|iterable $source, Closure $map, array $types = [], array $widths = []): self
    {
        return new self($name, $headers, $source, $map, $types, $widths, true);
    }
}
