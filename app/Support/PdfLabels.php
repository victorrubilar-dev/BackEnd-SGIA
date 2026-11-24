<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Textos y tonos de los estados, tipos y condiciones que aparecen en los PDFs (docs/pdf-estilo-trazo.md).
 * Los tonos son los de la web: `ok` (verde), `info` (azul), `warn` (ámbar), `danger` (rojo) y `neutral`.
 */
final class PdfLabels
{
    private const LABELS = [
        // Unidades, productos y préstamos
        'disponible' => 'Disponible',
        'prestado' => 'En préstamo',
        'en_reparacion' => 'En reparación',
        'dado_de_baja' => 'Dado de baja',
        'equipo' => 'Equipo',
        'insumo' => 'Insumo',
        'maqueta' => 'Maqueta',
        'pendiente' => 'Pendiente',
        'en_proceso' => 'En proceso',
        'procesado' => 'Procesado',
        'rechazado' => 'Rechazado',
        'remoto' => 'Remoto',
        'presencial' => 'Presencial',
        'bueno' => 'Bueno',
        'regular' => 'Regular',
        'danado' => 'Dañado',
        'extraviado' => 'Extraviado',
        'atrasado' => 'Atrasado',
        // Informes de novedades
        'falla' => 'Falla',
        'reposicion' => 'Reposición',
        'mantencion' => 'Mantención',
        'reportado' => 'Reportado',
        'en_revision' => 'En revisión',
        'reparado' => 'Reparado',
        'resuelto' => 'Resuelto',
        'descartado' => 'Descartado',
        'leve' => 'Leve',
        'media' => 'Media',
        'critica' => 'Crítica',
        // Mantenciones y documentos
        'preventiva' => 'Preventiva',
        'correctiva' => 'Correctiva',
        'calibracion' => 'Calibración',
        'medios_propios' => 'Medios propios',
        'externa' => 'Externa',
        'en_curso' => 'En curso',
        'finalizada' => 'Finalizada',
        'anulada' => 'Anulada',
        'operativo' => 'Operativo',
        'baja' => 'Baja',
        'certificado_calibracion' => 'Certificado de calibración',
        'informe_tecnico' => 'Informe técnico',
        'manual' => 'Manual',
        'garantia' => 'Garantía',
        'otro' => 'Otro',
    ];

    private const TONES = [
        'ok' => ['disponible', 'procesado', 'resuelto', 'reparado', 'bueno', 'finalizada', 'operativo', 'leve'],
        'info' => ['prestado', 'en_revision', 'en_proceso', 'en_curso', 'reportado'],
        'warn' => ['en_reparacion', 'pendiente', 'regular', 'media', 'mantencion'],
        'danger' => ['rechazado', 'danado', 'extraviado', 'critica', 'dado_de_baja', 'atrasado', 'baja', 'falla'],
    ];

    /** «en_revision» → «En revisión»; lo desconocido, con mayúscula inicial y sin guiones bajos. */
    public static function label(?string $value): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        return self::LABELS[$value] ?? Str::ucfirst(str_replace('_', ' ', $value));
    }

    public static function tone(?string $value): string
    {
        foreach (self::TONES as $tone => $values) {
            if (in_array($value, $values, true)) {
                return $tone;
            }
        }

        return 'neutral';
    }
}
