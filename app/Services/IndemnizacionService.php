<?php

namespace App\Services;

use App\Models\AseguradoraConfiguracion;
use App\Models\Evaluacion;
use App\Models\Indemnizaciones;
use App\Models\Siniestro;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Del dictamen aprobado al pago:
 *
 *   dictamen aprobado (siniestro "autorizado")
 *     -> generarDesdeEvaluacion : indemnización PENDIENTE con el monto calculado (nadie lo captura)
 *     -> confirmarPago          : AUTORIZADA, con forma y datos de pago; siniestro "en pago"
 *     -> pagar                  : PAGADA con referencia; siniestro "pagado" y cierre automático "cerrado"
 *   cancelar: RECHAZADA (anulada); el siniestro vuelve a "autorizado" para generar otra.
 *
 * Si el monto neto es $0 (deducible, mínimo o ya pagado antes) no hay indemnización: el siniestro se cierra.
 */
class IndemnizacionService
{
    public function __construct(
        private SiniestroEstadoService $estados,
        private NotificacionSiniestroService $notificaciones
    ) {
    }

    /** Idempotente: si ya hay una vigente o el siniestro ya avanzó, no hace nada. */
    public function generarDesdeEvaluacion(int $idEvaluacion): ?Indemnizaciones
    {
        return DB::transaction(function () use ($idEvaluacion) {
            $e = Evaluacion::whereKey($idEvaluacion)->lockForUpdate()->firstOrFail();
            $s = Siniestro::whereKey($e->id_siniestro)->lockForUpdate()->firstOrFail();

            if ($e->estatus !== 'aprobada' || $e->recomendacion === 'rechazar' || $s->estatus !== 'autorizado') {
                return null;
            }

            $vigente = Indemnizaciones::where('id_evaluaciones', $e->id)->where('estatus', '!=', 'rechazada')->first();
            if ($vigente) {
                return $vigente;
            }

            $monto = round((float) $e->monto_recomendado, 2);

            if ($monto <= 0) {
                $this->estados->transicionar(
                    $s->id, 'cerrado', null,
                    'Cierre automático: el monto a indemnizar es $0.00 (deducible, monto mínimo o pagos previos).',
                    ['id_evaluacion' => $e->id]
                );
                DB::afterCommit(fn () => $this->notificaciones->programar($s->id, 'sin_indemnizacion'));
                return null;
            }

            $calculo = $e->calculo ?? [];

            $indemnizacion = Indemnizaciones::create([
                'id_siniestro'       => $s->id,
                'id_empleado'        => $s->id_empleado,
                'id_evaluaciones'    => $e->id,
                'monto_aprobado'     => $monto,
                'fecha_autorizacion' => today()->toDateString(),
                'estatus'            => 'pendiente',
                'fecha_generacion'   => now(),
                'desglose'           => Arr::only($calculo, [
                    'monto_evaluado', 'cobertura', 'deducible', 'porcentaje_cobertura', 'coaseguro',
                    'topes', 'monto_bruto', 'pagos_siniestro', 'monto_final',
                    'incumplimientos', 'reduccion_incumplimientos', // [INCUMPLIMIENTOS]
                ]),
            ]);

            DB::afterCommit(fn () => $this->notificaciones->programar($s->id, 'siniestro_aprobado', ['monto' => $monto]));

            return $indemnizacion;
        });
    }

    /** pendiente -> autorizada. El siniestro pasa a "en pago". */
    public function confirmarPago(Indemnizaciones $indemnizacion, array $datos, Usuario $user): Indemnizaciones
    {
        return DB::transaction(function () use ($indemnizacion, $datos, $user) {
            [$i, $s] = $this->bloquear($indemnizacion);

            if ($i->estatus !== 'pendiente') {
                $this->falla('Solo se pueden confirmar los datos de una indemnización pendiente.');
            }
            if ($s->estatus !== 'autorizado') {
                $this->falla('El siniestro debe estar "Autorizado" (hoy: ' . SiniestroEstadoService::etiqueta($s->estatus) . ').');
            }

            $i->update([
                'forma_pago'          => $datos['forma_pago'],
                'banco'               => $datos['banco'] ?? null,
                'cuenta_bancaria'     => $datos['cuenta_bancaria'] ?? null,
                'titular_cuenta'      => $datos['titular_cuenta'],
                'observaciones'       => isset($datos['observaciones']) ? Str::limit($datos['observaciones'], 255, '') : null,
                'estatus'             => 'autorizada',
                'id_autorizador_pago' => $user->id,
            ]);

            $this->estados->transicionar($s->id, 'en_pago', $user->id, null, [
                'id_indemnizacion' => $i->id, 'monto' => (float) $i->monto_aprobado,
            ]);

            return $i->fresh();
        });
    }

    /** autorizada -> pagada. El siniestro pasa a "pagado" y se cierra solo. */
    public function pagar(Indemnizaciones $indemnizacion, array $datos, Usuario $user): Indemnizaciones
    {
        return DB::transaction(function () use ($indemnizacion, $datos, $user) {
            [$i, $s] = $this->bloquear($indemnizacion);

            if ($i->estatus !== 'autorizada') {
                $this->falla('Primero se deben confirmar los datos de pago.');
            }
            if ($s->estatus !== 'en_pago') {
                $this->falla('El siniestro debe estar "En pago" (hoy: ' . SiniestroEstadoService::etiqueta($s->estatus) . ').');
            }

            $config = AseguradoraConfiguracion::de((int) $s->id_aseguradora);
            abort_if(
                $config->doble_control_pago && (int) $i->id_autorizador_pago === (int) $user->id,
                403,
                'Por control interno, quien confirmó los datos de pago no puede registrar el pago.'
            );

            $fecha = Carbon::parse($datos['fecha_pago']);

            $i->update([
                'estatus'         => 'pagada',
                'fecha_pago'      => $fecha,
                'referencia_pago' => $datos['referencia_pago'],
                'id_pagador'      => $user->id,
            ]);

            $meta = ['id_indemnizacion' => $i->id, 'monto' => (float) $i->monto_aprobado, 'referencia' => $datos['referencia_pago']];

            $this->estados->transicionar($s->id, 'pagado', $user->id, null, $meta);
            $this->estados->transicionar($s->id, 'cerrado', $user->id, Str::limit('Indemnización pagada. Ref.: ' . $datos['referencia_pago'], 255, ''), $meta);

            DB::afterCommit(fn () => $this->notificaciones->programar($s->id, 'indemnizacion_pagada', [
                'monto'      => (float) $i->monto_aprobado,
                'forma_pago' => $i->forma_pago,
                'fecha_pago' => $fecha->format('d/m/Y'),
                'referencia' => $datos['referencia_pago'],
            ]));

            return $i->fresh();
        });
    }

    /** pendiente | autorizada -> rechazada (anulada). El siniestro vuelve a "autorizado". */
    public function cancelar(Indemnizaciones $indemnizacion, string $motivo, Usuario $user): Indemnizaciones
    {
        $motivo = trim($motivo);

        return DB::transaction(function () use ($indemnizacion, $motivo, $user) {
            [$i, $s] = $this->bloquear($indemnizacion);

            if (!in_array($i->estatus, ['pendiente', 'autorizada'], true)) {
                $this->falla('Solo se puede cancelar una indemnización pendiente o autorizada.');
            }
            if (mb_strlen($motivo) < 10) {
                $this->falla('Indica el motivo de la cancelación (mínimo 10 caracteres).', 'motivo');
            }

            $i->update(['estatus' => 'rechazada', 'motivo_cancelacion' => $motivo]);

            if ($s->estatus === 'en_pago') {
                $this->estados->transicionar($s->id, 'autorizado', $user->id, Str::limit('Pago cancelado: ' . $motivo, 255, ''), [
                    'id_indemnizacion' => $i->id,
                ]);
            } else {
                $this->estados->registrarEvento($s, $user->id, 'indemnizacion_cancelada',
                    Str::limit('Indemnización anulada: ' . $motivo, 255, ''), ['id_indemnizacion' => $i->id]);
            }

            return $i->fresh();
        });
    }

    /** Genera de nuevo la indemnización de la última evaluación aprobada (por una cancelación o un fallo). */
    public function regenerar(Siniestro $siniestro): ?Indemnizaciones
    {
        $evaluacion = Evaluacion::where('id_siniestro', $siniestro->id)
            ->where('estatus', 'aprobada')->orderByDesc('id')->first();

        if (!$evaluacion) {
            $this->falla('Este siniestro no tiene un dictamen aprobado.');
        }

        return $this->generarDesdeEvaluacion($evaluacion->id);
    }

    private function bloquear(Indemnizaciones $indemnizacion): array
    {
        $i = Indemnizaciones::whereKey($indemnizacion->id)->lockForUpdate()->firstOrFail();
        $s = Siniestro::whereKey($i->id_siniestro)->lockForUpdate()->firstOrFail();

        return [$i, $s];
    }

    private function falla(string $mensaje, string $campo = 'indemnizacion'): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}