<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;


Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('suscripciones:avisar')->dailyAt('15:00'); // 09:00 en Mérida
Schedule::command('suscripciones:cobrar')->hourly();
Schedule::command('siniestros:recordar-documentos')->dailyAt('15:00'); // 09:00 en Mérida

