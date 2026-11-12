<?php

namespace App\Services;

use App\Events\EvaluacionAprobada;
use App\Models\Evaluacion;
use App\Models\Siniestro;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Ciclo del dictamen:
 *   borrador --enviar--> (monto dentro del límite del ajustador) aprobada  -> siniestro "autorizado"
 *                    \-> en_revision --resolver(aprobar)--> aprobada       -> siniestro "autorizado"
 *                                    \-resolver(devolver)-> rechazada      -> siniestro vuelve a "en_evaluacion"
 * Si la recomendación es "rechazar" y se aprueba, el siniestro pasa a "rechazado" y se avisa al cliente.
 */
class EvaluacionService
{
    public function __construct(
        private CalculoIndemnizacionService $calculo,
        private DocumentosSiniestroService $docs,
        private SiniestroEstadoService $estados,
        private NotificacionSiniestroService $notificaciones
    ) {
    }

    /** Cálculo sin guardar (para mostrar el desglose mientras el ajustador captura). */
    public function previsualizar(Siniestro $s, array $datos): array
    {
        $datos = $this->normalizar($datos);
        $calc  = $this->calculo->calcular($s, $datos, $datos['id_cobertura'] ?? null);

        return [
            'calculo'  => $calc,
            'nivel'    => $this->calculo->nivel((float) $calc['monto_final'], (int) $s->id_aseguradora, $calc),
            'catalogo' => \App\Support\Incumplimientos::paraFormulario(), // [INCUMPLIMIENTOS]
        ];
    }

    public function guardar(Siniestro $siniestro, array $datos, Usuario $user, ?Evaluacion $evaluacion = null): Evaluacion
    {
        return DB::transaction(function () use ($siniestro, $datos, $user, $evaluacion) {
            $s = Siniestro::whereKey($siniestro->id)->lockForUpdate()->firstOrFail();

            if ($s->estatus !== 'en_evaluacion') {
                $this->falla('El siniestro debe estar "En evaluación" (hoy: ' . SiniestroEstadoService::etiqueta($s->estatus)
                    . '). Primero se deben aprobar sus documentos obligatorios.');
            }

            $esNuevo = $evaluacion === null;

            if ($evaluacion) {
                $evaluacion = Evaluacion::whereKey($evaluacion->id)->lockForUpdate()->firstOrFail();
                if ($evaluacion->estatus !== 'borrador') {
                    $this->falla('Solo se puede editar un dictamen en borrador.');
                }
            } elseif (Evaluacion::where('id_siniestro', $s->id)->whereIn('estatus', ['borrador', 'en_revision'])->exists()) {
                $this->falla('Este siniestro ya tiene un dictamen en curso.');
            }

            $datos = $this->normalizar($datos);
            $calc  = $this->calculo->calcular($s, $datos, $datos['id_cobertura'] ?? null);
            $nivel = $this->calculo->nivel((float) $calc['monto_final'], (int) $s->id_aseguradora, $calc);

            $campos = [
                'fecha_inspeccion'   => $datos['fecha_inspeccion'],
                'dictamen'           => $datos['dictamen'],
                'causas'             => $datos['causas'],
                'responsable'        => $datos['responsable'],
                'recomendacion'      => $datos['recomendacion'],
                'costo_reparacion'   => $datos['costo_reparacion'],
                'costo_remplazo'     => $datos['costo_remplazo'],
                'valor_residual'     => $datos['valor_residual'],
                'valor_real_bien'    => $datos['valor_real_bien'],
                'gastos_procedentes' => $datos['gastos_procedentes'],
                'conserva_salvamento' => $datos['conserva_salvamento'],
                'incumplimientos'    => $datos['incumplimientos'], // [INCUMPLIMIENTOS]
                'monto_evaluado'     => $calc['monto_evaluado'],
                'monto_recomendado'  => $calc['monto_final'],
                'id_cobertura'       => $calc['id_cobertura'],
                'calculo'            => $calc,
                'nivel_autorizacion' => $nivel,
                'fecha_dictamen'     => now(),
                'estatus'            => 'borrador',
            ];

            if ($evaluacion) {
                $evaluacion->update($campos);
            } else {
                $evaluacion = Evaluacion::create($campos + [
                    'id_siniestro' => $s->id,
                    'id_empleado'  => $user->empleado?->id ?? $s->id_empleado,
                ]);
            }

            $s->update(['deducible_aplicado' => $calc['deducible'], 'fecha_actualizacion' => now()]);

            $this->estados->registrarEvento($s, $user->id, $esNuevo ? 'dictamen_creado' : 'dictamen_editado',
                "Dictamen #{$evaluacion->id} en borrador", [
                    'id_evaluacion' => $evaluacion->id, 'recomendacion' => $datos['recomendacion'],
                    'monto' => (float) $calc['monto_final'], 'nivel' => $nivel,
                ]);

            return $evaluacion->fresh();
        });
    }

    public function enviar(Evaluacion $evaluacion, Usuario $user): Evaluacion
    {
        return DB::transaction(function () use ($evaluacion, $user) {
            $e = Evaluacion::whereKey($evaluacion->id)->lockForUpdate()->firstOrFail();
            $s = Siniestro::whereKey($e->id_siniestro)->lockForUpdate()->firstOrFail();

            if ($e->estatus !== 'borrador') {
                $this->falla('Este dictamen ya fue enviado.');
            }
            if ($s->estatus !== 'en_evaluacion') {
                $this->falla('El siniestro debe estar "En evaluación" para enviar el dictamen.');
            }

            $resumen = $this->docs->resumen($s);
            if (!$resumen['completo']) {
                $this->falla("Faltan {$resumen['faltantes']} documento(s) obligatorio(s) por aprobar. No se puede dictaminar sin ellos.");
            }

            // Se recalcula con los datos de hoy (pagos previos, endosos) antes de enviar
            $calc = $this->calculo->calcular($s, $this->normalizar([
                'recomendacion'    => $e->recomendacion,
                'costo_reparacion' => $e->costo_reparacion,
                'costo_remplazo'   => $e->costo_remplazo,
                'valor_residual'   => $e->valor_residual,
                'valor_real_bien'  => $e->valor_real_bien,
                'gastos_procedentes' => $e->gastos_procedentes,
                'conserva_salvamento' => $e->conserva_salvamento,
                'incumplimientos'  => $e->incumplimientos, // [INCUMPLIMIENTOS] sin esto el monto cambiaría en silencio al enviar
                'id_cobertura'     => $e->id_cobertura,
            ] + $e->only(['fecha_inspeccion', 'dictamen', 'causas', 'responsable'])), $e->id_cobertura);
            $nivel = $this->calculo->nivel((float) $calc['monto_final'], (int) $s->id_aseguradora, $calc);

            $e->update([
                'monto_evaluado'     => $calc['monto_evaluado'],
                'monto_recomendado'  => $calc['monto_final'],
                'calculo'            => $calc,
                'nivel_autorizacion' => $nivel,
                'fecha_envio'        => now(),
                'fecha_dictamen'     => now(),
            ]);
            $s->update(['deducible_aplicado' => $calc['deducible'], 'fecha_actualizacion' => now()]);

            $this->estados->transicionar($s->id, 'dictaminado', $user->id, null, [
                'id_evaluacion' => $e->id, 'monto' => $calc['monto_final'], 'nivel' => $nivel,
            ]);

            if ($nivel === 'auto') {
                $this->aprobar($e->fresh(), null, 'Autorización automática: el monto está dentro del límite del ajustador.');
            } else {
                $e->update(['estatus' => 'en_revision']);
            }

            return $e->fresh();
        });
    }

    /** @param string $accion aprobar | devolver */
    public function resolver(Evaluacion $evaluacion, Usuario $user, string $accion, ?string $motivo): Evaluacion
    {
        $motivo = $motivo !== null ? trim($motivo) : null;

        return DB::transaction(function () use ($evaluacion, $user, $accion, $motivo) {
            $e = Evaluacion::whereKey($evaluacion->id)->lockForUpdate()->firstOrFail();
            $s = Siniestro::whereKey($e->id_siniestro)->lockForUpdate()->firstOrFail();

            if ($e->estatus !== 'en_revision' || $s->estatus !== 'dictaminado') {
                $this->falla('Este dictamen no está pendiente de autorización.');
            }

            abort_if(
                !$this->puedeAutorizar($user, (string) ($e->nivel_autorizacion ?: 'supervisor')),
                403, 'No tienes permiso para autorizar este monto.'
            );
            abort_if(
                $user->empleado && (int) $user->empleado->id === (int) $e->id_empleado,
                403,
                'No puedes autorizar tu propio dictamen.'
            );

            if ($accion === 'devolver') {
                if (!$motivo || mb_strlen($motivo) < 10) {
                    $this->falla('Indica por qué se devuelve el dictamen (mínimo 10 caracteres).', 'motivo');
                }

                $e->update([
                    'estatus'            => 'rechazada',
                    'id_autorizador'     => $user->id,
                    'fecha_autorizacion' => now(),
                    'motivo_resolucion'  => $motivo,
                ]);

                $this->estados->transicionar($s->id, 'en_evaluacion', $user->id, Str::limit('Dictamen devuelto: ' . $motivo, 255, ''), [
                    'id_evaluacion' => $e->id,
                ]);
            } else {
                $this->aprobar($e, $user->id, $motivo);
            }

            return $e->fresh();
        });
    }

    /** Permiso mínimo que pide cada nivel de autorización (null = no requiere a nadie, ej. "auto"). */
    public static function permisoRequerido(string $nivel): ?string
    {
        return match ($nivel) {
            'supervisor' => 'evaluaciones.autorizar',
            'gerente'    => 'evaluaciones.autorizar_alto',
            default      => null,
        };
    }

    public function puedeAutorizar(Usuario $user, string $nivel): bool
    {
        return match ($nivel) {
            'auto'       => true,
            'supervisor' => $user->tienePermiso('evaluaciones.autorizar') || $user->tienePermiso('evaluaciones.autorizar_alto'),
            'gerente'    => $user->tienePermiso('evaluaciones.autorizar_alto'),
            default      => false,
        };
    }

    private function aprobar(Evaluacion $e, ?int $idUsuario, ?string $motivo): void
    {
        $e->update([
            'estatus'            => 'aprobada',
            'id_autorizador'     => $idUsuario,
            'fecha_autorizacion' => now(),
            'motivo_resolucion'  => $motivo,
        ]);

        if ($e->recomendacion === 'rechazar') {
            $this->estados->transicionar($e->id_siniestro, 'rechazado', $idUsuario, Str::limit('Dictamen: ' . $e->dictamen, 255, ''), [
                'id_evaluacion' => $e->id,
            ]);
            DB::afterCommit(fn () => $this->notificaciones->programar($e->id_siniestro, 'siniestro_rechazado', ['motivo' => $e->dictamen]));
            return;
        }

        $this->estados->transicionar($e->id_siniestro, 'autorizado', $idUsuario, $motivo, [
            'id_evaluacion' => $e->id, 'monto' => (float) $e->monto_recomendado, 'nivel' => $e->nivel_autorizacion,
        ]);

        // La Fase 6 escucha este evento para generar la indemnización
        DB::afterCommit(fn () => event(new EvaluacionAprobada($e->id)));
    }

    private function normalizar(array $d): array
    {
        $d['costo_reparacion'] = (float) ($d['costo_reparacion'] ?? 0);
        $d['costo_remplazo']   = (float) ($d['costo_remplazo'] ?? 0);
        $d['valor_residual']   = (float) ($d['valor_residual'] ?? 0);
        $d['responsable']      = $d['responsable'] ?? null;
        $d['valor_real_bien']  = isset($d['valor_real_bien']) && $d['valor_real_bien'] !== '' ? (float) $d['valor_real_bien'] : null;
        $d['gastos_procedentes'] = isset($d['gastos_procedentes']) && $d['gastos_procedentes'] !== '' ? (float) $d['gastos_procedentes'] : null;
        $d['conserva_salvamento'] = in_array($d['conserva_salvamento'] ?? null, ['aseguradora', 'asegurado'], true) ? $d['conserva_salvamento'] : null;
        $d['incumplimientos']  = array_values(array_filter((array) ($d['incumplimientos'] ?? []), 'is_array')); // [INCUMPLIMIENTOS]

        return $d;
    }

    private function falla(string $mensaje, string $campo = 'evaluacion'): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}