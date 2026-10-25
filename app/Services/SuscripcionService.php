<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SuscripcionService
{
    /** Fecha en que termina la suscripción (plan pagado o prueba) */
    public static function finDe($s): ?Carbon
    {
        $f = $s->fecha_fin_plan ?: $s->fecha_fin;
        return $f ? Carbon::parse($f) : null;
    }

    public static function estaVigente($s): bool
    {
        if (!$s) {
            return false;
        }
        $fin = self::finDe($s);
        return !$fin || $fin->gt(Carbon::now());
    }

    /** Plan de pago 'activa' más reciente, con los datos de su plan */
    public static function activaActual(int $idAseg)
    {
        return DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->where('suscripciones.id_aseguradora', $idAseg)
            ->whereIn('suscripciones.estatus', ['activa', 'activo'])
            ->select('suscripciones.*', 'planes.nombre as plan_nombre', 'planes.precio', 'planes.precio_anual')
            ->orderByDesc('suscripciones.id')
            ->first();
    }

    public static function programada(int $idAseg)
    {
        return DB::table('suscripciones')
            ->where('id_aseguradora', $idAseg)
            ->where('estatus', 'programada')
            ->orderByDesc('id')
            ->first();
    }

    /** Si el plan actual ya venció y hay un cambio programado que ya le toca, lo activa */
    public static function normalizar(int $idAseg): void
    {
        $actual = self::activaActual($idAseg);
        if ($actual && self::estaVigente($actual)) {
            return;
        }

        $prog = DB::table('suscripciones')
            ->where('id_aseguradora', $idAseg)
            ->where('estatus', 'programada')
            ->where('fecha_inicio_plan', '<=', Carbon::now())
            ->orderBy('fecha_inicio_plan')
            ->first();

        if (!$prog) {
            return;
        }

        DB::transaction(function () use ($idAseg, $prog) {
            DB::table('suscripciones')
                ->where('id_aseguradora', $idAseg)
                ->whereIn('estatus', ['activa', 'activo'])
                ->update(['estatus' => 'renovada']);

            DB::table('suscripciones')->where('id', $prog->id)->update(['estatus' => 'activa']);
        });
    }
}