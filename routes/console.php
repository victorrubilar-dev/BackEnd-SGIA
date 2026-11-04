<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Avisos de préstamos (vencen hoy / atrasados) y mantenciones próximas, cada mañana en Chile.
Schedule::command('sgia:send-reminders')->dailyAt('08:00')->timezone('America/Santiago');
