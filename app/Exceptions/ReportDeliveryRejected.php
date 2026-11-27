<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * La ruta del reporte rechazó el envío (`403`, `422`…): reintentar no sirve y la suscripción se pausa
 * (docs/suscripcion-reportes.md, decisión 9).
 */
class ReportDeliveryRejected extends RuntimeException {}
