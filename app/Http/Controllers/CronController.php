<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

class CronController extends Controller
{
    public function suscripciones(Request $request)
    {
        $token = (string) config('services.cron.token');
        abort_unless($token !== '' && hash_equals($token, (string) $request->header('X-Cron-Token')), 403, 'No autorizado');

        Artisan::call('suscripciones:cobrar');
        $cobros = trim(Artisan::output());

        Artisan::call('suscripciones:avisar');
        $avisos = trim(Artisan::output());

        return response()->json(['ok' => true, 'cobros' => $cobros, 'avisos' => $avisos]);
    }
}