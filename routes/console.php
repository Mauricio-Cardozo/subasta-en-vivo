<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Red de seguridad del cierre perezoso (4.4): liquida los lotes que nadie
// volvió a consultar. Requiere `php artisan schedule:work` (o el cron de
// `schedule:run`); sin él, el cierre sigue funcionando al interactuar.
Schedule::command('auction:close-expired')->everyMinute()->withoutOverlapping();
