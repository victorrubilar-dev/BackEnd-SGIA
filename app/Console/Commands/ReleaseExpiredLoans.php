<?php

namespace App\Console\Commands;

use App\Services\LoanService;
use Illuminate\Console\Command;

/**
 * Devuelve a `disponible` las unidades de préstamos cuyo horario de término ya pasó.
 * Programado cada 5 minutos en `routes/console.php`.
 */
class ReleaseExpiredLoans extends Command
{
    protected $signature = 'sgia:release-expired-loans';

    protected $description = 'Libera las unidades prestadas cuyo horario de préstamo terminó y no fueron devueltas.';

    public function handle(LoanService $loans): int
    {
        $this->info("Unidades liberadas: {$loans->releaseExpiredUnits()}.");

        return self::SUCCESS;
    }
}
