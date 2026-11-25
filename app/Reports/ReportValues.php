<?php

namespace App\Reports;

use App\Models\Cajon;
use App\Models\Location;
use App\Support\PdfLabels;

/**
 * Formato de celdas comunes a los reportes: estados legibles, sí/no y ubicación.
 */
final class ReportValues
{
    /** «en_revision» → «En revisión»; vacío → celda vacía (no «—» como en los PDFs). */
    public static function label(?string $value): ?string
    {
        return $value === null || $value === '' ? null : PdfLabels::label($value);
    }

    public static function yesNo(?bool $value): ?string
    {
        return $value === null ? null : ($value ? 'sí' : 'no');
    }

    /** Sala de un cajón o de la ubicación directa: «L206 Laboratorio de redes». */
    public static function room(?Cajon $cajon, ?Location $location = null): ?string
    {
        $room = $cajon !== null ? $cajon->location : $location;
        if ($room === null) {
            return null;
        }

        return trim(($room->code ? $room->code.' ' : '').($room->nombre ?? $room->sala ?? '')) ?: null;
    }
}
