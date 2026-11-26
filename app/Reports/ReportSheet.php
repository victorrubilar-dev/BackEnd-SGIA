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
     * @param  int|null  $yearOf  Índice de la columna de fecha de la operación: `ReportExporter` agrega «Año» justo después.
     * @param  list<string>  $summary  Cabeceras por las que la hoja «Resumen» cuenta las filas (docs/centro-reportes.md).
     * @param  string|null  $summaryAmount  Cabecera del monto que «Resumen» suma por grupo.
     * @param  list<string>  $totals  Cabeceras con fila de totales al pie.
     */
    public function __construct(
        public readonly string $name,
        public readonly array $headers,
        public readonly Builder|iterable $source,
        public readonly Closure $map,
        public readonly array $types = [],
        public readonly array $widths = [],
        public readonly bool $expand = false,
        public readonly ?int $yearOf = null,
        public readonly array $summary = [],
        public readonly ?string $summaryAmount = null,
        public readonly array $totals = [],
    ) {}

    /** Copia con el resumen y los totales indicados (para no repetir los argumentos posicionales). */
    public function summarized(array $summary, ?string $amount = null, array $totals = []): self
    {
        return new self($this->name, $this->headers, $this->source, $this->map, $this->types, $this->widths, $this->expand, $this->yearOf, $summary, $amount, $totals);
    }

    /**
     * Hoja con una fila por cada fila devuelta por `$map` (ítems de una solicitud, unidades de un préstamo).
     *
     * @param  list<string>  $headers
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|iterable<mixed>  $source
     * @param  Closure(mixed): iterable<array<int, mixed>>  $map
     * @param  array<int, string>  $types
     * @param  array<int, float|int>  $widths
     */
    public static function expanded(string $name, array $headers, Builder|iterable $source, Closure $map, array $types = [], array $widths = [], ?int $yearOf = null): self
    {
        return new self($name, $headers, $source, $map, $types, $widths, true, $yearOf);
    }
}
