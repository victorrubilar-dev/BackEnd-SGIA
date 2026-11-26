<?php

namespace App\Reports;

use App\Models\User;

/**
 * Reporte cuyo alcance depende del usuario (préstamos del pañol de PAN-01): el texto va en la fila «Alcance»
 * de la hoja «Info» (docs/centro-reportes.md, decisión 11).
 */
interface ScopedReport extends Report
{
    public function scope(?User $user): string;
}
