<?php

namespace App\Services;

use App\Models\Siniestro;
use App\Models\SiniestroHistorial;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Única puerta para cambiar el estatus de un siniestro.
 * Valida la transición, la aplica con el registro bloqueado y deja la bitácora.
 */
class SiniestroEstadoService
{
    public const ETIQUETAS = [
        'abierto'                 => 'Abierto',
        'documentacion_pendiente' => 'Documentación pendiente',
        'en_proceso'              => 'En proceso',
        'en_evaluacion'           => 'En evaluación',
        'dictaminado'             => 'Dictaminado',
        'autorizado'              => 'Autorizado',
        'en_pago'                 => 'En pago',
        'pagado'                  => 'Pagado',
        'rechazado'               => 'Rechazado',
        'cancelado'               => 'Cancelado',
        'cerrado'                 => 'Cerrado',
        'reabierto'               => 'Reabierto',
    ];

    public const TRANSICIONES = [
        'abierto'                 => ['documentacion_pendiente', 'en_evaluacion', 'en_proceso', 'rechazado', 'cancelado'],
        'documentacion_pendiente' => ['en_evaluacion', 'en_proceso', 'rechazado', 'cancelado'],
        'en_proceso'              => ['documentacion_pendiente', 'en_evaluacion', 'dictaminado', 'rechazado', 'cancelado'],
        'en_evaluacion'           => ['documentacion_pendiente', 'en_proceso', 'dictaminado', 'rechazado', 'cancelado'],
        'dictaminado'             => ['autorizado', 'rechazado', 'en_evaluacion'],
        'autorizado'              => ['en_pago', 'cancelado', 'cerrado'], // cerrado: monto neto $0
        'en_pago'                 => ['pagado', 'autorizado'],
        'pagado'                  => ['cerrado'],
        'rechazado'               => ['en_evaluacion', 'cerrado'],   // reconsideración o cierre
        'cerrado'                 => ['reabierto'],
        'reabierto'               => ['documentacion_pendiente', 'en_evaluacion', 'en_proceso', 'cerrado'],
        'cancelado'               => [],
    ];

    /**
     * Los que una persona puede pedir desde la API. "documentacion_pendiente", "en_proceso" (se llega
     * con registrarArribo), "dictaminado", "autorizado", "en_pago" y "pagado" los mueven los procesos.
     * "en_evaluacion" solo se pide a mano para reconsiderar un siniestro rechazado; en el resto es
     * automático al completar documentos.
     */
    public const MANUALES = ['en_evaluacion', 'rechazado', 'cancelado', 'reabierto', 'cerrado'];

    public const MOTIVO_OBLIGATORIO = ['rechazado', 'cancelado', 'reabierto'];

    public static function permitidas(string $desde): array
    {
        return self::TRANSICIONES[$desde] ?? [];
    }

    public static function puede(string $desde, string $hacia): bool
    {
        return in_array($hacia, self::permitidas($desde), true);
    }

    public static function etiqueta(?string $estatus): string
    {
        return self::ETIQUETAS[$estatus] ?? (string) $estatus;
    }

    /**
     * @param array $metadata datos extra para la bitácora (p. ej. id de evaluación)
     * @throws ValidationException (422) si la transición no es válida
     */
    public function transicionar(
        int $idSiniestro,
        string $nuevo,
        ?int $idUsuario = null,
        ?string $motivo = null,
        array $metadata = []
    ): Siniestro {
        $motivo = $motivo !== null ? trim($motivo) : null;

        return DB::transaction(function () use ($idSiniestro, $nuevo, $idUsuario, $motivo, $metadata) {
            $siniestro = Siniestro::whereKey($idSiniestro)->lockForUpdate()->firstOrFail();
            $anterior  = $siniestro->estatus;

            if (!isset(self::TRANSICIONES[$nuevo])) {
                $this->falla("El estatus \"{$nuevo}\" no existe.");
            }

            if ($anterior === $nuevo) {
                $this->falla('El siniestro ya está en ese estatus.');
            }

            if (!self::puede($anterior, $nuevo)) {
                $this->falla(sprintf(
                    'No se puede pasar de "%s" a "%s".',
                    self::etiqueta($anterior),
                    self::etiqueta($nuevo)
                ));
            }

            $requiereMotivo = in_array($nuevo, self::MOTIVO_OBLIGATORIO, true)
                || ($anterior === 'rechazado' && $nuevo === 'en_evaluacion');

            if ($requiereMotivo && ($motivo === null || mb_strlen($motivo) < 5)) {
                $this->falla('Indica el motivo del cambio (mínimo 5 caracteres).', 'motivo');
            }

            $cambios = ['estatus' => $nuevo, 'fecha_actualizacion' => now()];

            if (in_array($nuevo, ['cerrado', 'cancelado'], true)) {
                $cambios['fecha_cierre']  = now();
                $cambios['motivo_cierre'] = Str::limit($motivo ?: 'Cierre del siniestro', 255, '');
            } elseif ($nuevo === 'reabierto') {
                $cambios['fecha_cierre']  = null;
                $cambios['motivo_cierre'] = null;
            }

            $siniestro->update($cambios);

            SiniestroHistorial::create([
                'id_aseguradora'  => $siniestro->id_aseguradora,
                'id_siniestro'    => $siniestro->id,
                'estado_anterior' => $anterior,
                'estado_nuevo'    => $nuevo,
                'id_usuario'      => $idUsuario,
                'motivo'          => $motivo ? Str::limit($motivo, 255, '') : null,
                'metadata'        => $metadata ?: null,
            ]);

            return $siniestro;
        });
    }

    /** Primer registro de la bitácora al abrir el siniestro. */
    public function registrarCreacion(Siniestro $siniestro, ?int $idUsuario, string $motivo = 'Siniestro abierto', array $metadata = []): void
    {
        SiniestroHistorial::create([
            'id_aseguradora'  => $siniestro->id_aseguradora,
            'id_siniestro'    => $siniestro->id,
            'estado_anterior' => null,
            'estado_nuevo'    => $siniestro->estatus,
            'id_usuario'      => $idUsuario,
            'motivo'          => $motivo,
            'metadata'        => $metadata ?: null,
        ]);
    }

    /** Evento en la bitácora que no cambia el estatus (reasignación, arribo, etc.). */
    public function registrarEvento(Siniestro $s, ?int $idUsuario, string $evento, string $motivo, array $metadata = []): void
    {
        SiniestroHistorial::create([
            'id_aseguradora'  => $s->id_aseguradora,
            'id_siniestro'    => $s->id,
            'estado_anterior' => $s->estatus,
            'estado_nuevo'    => $s->estatus,
            'id_usuario'      => $idUsuario,
            'motivo'          => Str::limit($motivo, 255, ''),
            'metadata'        => ['evento' => $evento] + $metadata,
        ]);
    }

    private function falla(string $mensaje, string $campo = 'estatus'): never
    {
        throw ValidationException::withMessages([$campo => $mensaje]);
    }
}