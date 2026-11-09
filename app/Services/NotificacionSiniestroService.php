<?php

namespace App\Services;

use App\Models\Aseguradora;
use App\Models\Siniestro;
use App\Models\SiniestroDocumento;
use App\Models\SiniestroNotificacion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Avisos al cliente (y alertas al ajustador) por correo. Cada envío queda en
 * siniestro_notificaciones. Un correo que falla NUNCA tumba el trámite.
 *
 * Tipos: apertura, documentos_faltantes, documento_rechazado, documentos_completos,
 *        recordatorio, siniestro_rechazado (al cliente) y alerta_vencidos (al ajustador).
 *
 * WhatsApp u otro canal: se agrega otro método de envío dentro de enviar(); el resto no cambia.
 */
class NotificacionSiniestroService
{
    private const INTERNAS = ['alerta_vencidos'];

    /**
     * Envía DESPUÉS de responder la petición, para que el correo (lento con SMTP)
     * no retrase la pantalla. En consola (comandos) se ejecuta al terminar el comando.
     */
    public function programar(int $idSiniestro, string $tipo, array $extra = []): void
    {
        app()->terminating(fn () => $this->enviar($idSiniestro, $tipo, $extra));
    }

    public function enviar(int $idSiniestro, string $tipo, array $extra = []): void
    {
        $siniestro = Siniestro::with('reporte.poliza.cliente', 'empleado')->find($idSiniestro);
        if (!$siniestro) {
            return;
        }

        $interna  = in_array($tipo, self::INTERNAS, true);
        $cliente  = data_get($siniestro, 'reporte.poliza.cliente');
        $destino  = $interna ? $siniestro->empleado?->correo : $cliente?->correo;
        $aseg     = Aseguradora::find($siniestro->id_aseguradora);
        $contenido = $this->contenido($tipo, $siniestro, $aseg, $cliente, $extra);

        $registro = [
            'id_aseguradora' => $siniestro->id_aseguradora,
            'id_siniestro'   => $siniestro->id,
            'tipo'           => $tipo,
            'canal'          => 'correo',
            'destinatario'   => $destino,
            'asunto'         => $contenido['asunto'],
        ];

        if (!$destino) {
            SiniestroNotificacion::create($registro + ['estado' => 'fallida', 'error' => 'Sin correo de destino.']);
            return;
        }

        try {
            Mail::send('emails.siniestro', $contenido['vista'], function ($m) use ($destino, $contenido, $aseg) {
                $m->to($destino)->subject($contenido['asunto']);
                if ($aseg?->correo) {
                    $m->replyTo($aseg->correo, $aseg->nombre);
                }
            });

            SiniestroNotificacion::create($registro + ['estado' => 'enviada']);
        } catch (\Throwable $e) {
            Log::error("Correo de siniestro {$siniestro->numero_siniestro} ({$tipo}): " . $e->getMessage());
            SiniestroNotificacion::create($registro + ['estado' => 'fallida', 'error' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }

    private function contenido(string $tipo, Siniestro $s, ?Aseguradora $aseg, $cliente, array $extra): array
    {
        $numero = $s->numero_siniestro;
        $poliza = data_get($s, 'reporte.poliza.numero_poliza');
        $docs   = [];
        $p      = [];
        $cierre = [];

        switch ($tipo) {
            case 'apertura':
                $asunto = "Siniestro {$numero}: hemos registrado su reporte";
                $titulo = 'Registramos su siniestro';
                $p[] = "Hemos registrado su siniestro con el número {$numero}" . ($poliza ? ", relacionado con la póliza {$poliza}." : '.');
                if ($s->empleado) {
                    $p[] = "Su ajustador asignado es {$s->empleado->nombre_completo}.";
                }
                $docs = $this->faltantes($s->id);
                $p[] = $docs
                    ? 'Para continuar con el trámite necesitamos los siguientes documentos:'
                    : 'Por ahora no necesitamos documentos adicionales de su parte.';
                break;

            case 'documentos_faltantes':
                $asunto = "Siniestro {$numero}: documentos pendientes";
                $titulo = 'Necesitamos documentos para continuar';
                $p[] = "Para continuar con su siniestro {$numero} necesitamos los siguientes documentos:";
                $docs = $this->faltantes($s->id);
                break;

            case 'documento_rechazado':
                $doc = SiniestroDocumento::find($extra['id_documento'] ?? 0);
                $asunto = "Siniestro {$numero}: un documento necesita corrección";
                $titulo = 'Un documento necesita corrección';
                $p[] = $doc
                    ? "No pudimos aceptar el documento «{$doc->nombre}» de su siniestro {$numero}. Motivo: {$doc->motivo_rechazo}"
                    : "Uno de los documentos de su siniestro {$numero} no pudo ser aceptado.";
                $p[] = 'Por favor envíelo nuevamente. Documentos pendientes:';
                $docs = $this->faltantes($s->id);
                break;

            case 'documentos_completos':
                $asunto = "Siniestro {$numero}: documentación completa";
                $titulo = 'Recibimos su documentación';
                $p[] = "Revisamos toda la documentación de su siniestro {$numero}. Su caso continúa con la evaluación.";
                $cierre[] = 'Le avisaremos cuando tengamos un resultado.';
                break;

            case 'recordatorio':
                $asunto = "Recordatorio: siniestro {$numero} con documentos pendientes";
                $titulo = 'Recordatorio de documentos pendientes';
                $p[] = "Le recordamos que su siniestro {$numero} sigue detenido por falta de documentos:";
                $docs = $this->faltantes($s->id);
                $cierre[] = 'Si ya los envió, ignore este mensaje o contacte a su ajustador.';
                break;

            case 'siniestro_rechazado':
                $asunto = "Siniestro {$numero}: resultado de la evaluación";
                $titulo = 'Resultado de la evaluación';
                $p[] = "Después de evaluar su siniestro {$numero}, no es posible proceder con la indemnización.";
                if (!empty($extra['motivo'])) {
                    $p[] = 'Motivo: ' . $extra['motivo'];
                }
                $cierre[] = 'Si tiene información adicional que pueda cambiar este resultado, comuníquese con nosotros.';
                break;

            case 'siniestro_aprobado':
                $asunto = "Siniestro {$numero}: su reclamación fue aprobada";
                $titulo = 'Su reclamación fue aprobada';
                $p[] = "Su siniestro {$numero} fue aprobado. El monto a indemnizar es de $" . number_format((float) ($extra['monto'] ?? 0), 2) . ' MXN.';
                $cierre[] = 'Nos pondremos en contacto con usted para coordinar el pago y confirmar sus datos.';
                break;

            case 'indemnizacion_pagada':
                $asunto = "Siniestro {$numero}: indemnización pagada";
                $titulo = 'Realizamos el pago de su indemnización';
                $p[] = "Se pagó la indemnización de su siniestro {$numero} por $" . number_format((float) ($extra['monto'] ?? 0), 2) . ' MXN.';
                $detalle = array_filter([
                    !empty($extra['forma_pago']) ? 'Forma de pago: ' . $extra['forma_pago'] : null,
                    !empty($extra['fecha_pago']) ? 'Fecha: ' . $extra['fecha_pago'] : null,
                    !empty($extra['referencia']) ? 'Referencia: ' . $extra['referencia'] : null,
                ]);
                foreach ($detalle as $linea) {
                    $p[] = $linea;
                }
                $cierre[] = 'Con este pago su siniestro queda cerrado. Gracias por su confianza.';
                break;

            case 'sin_indemnizacion':
                $asunto = "Siniestro {$numero}: resultado de la evaluación";
                $titulo = 'Resultado de la evaluación';
                $p[] = "Su siniestro {$numero} fue evaluado y es procedente, pero después de aplicar el deducible y los límites de su póliza el monto a pagar es de $0.00.";
                $cierre[] = 'Si tiene dudas sobre este resultado, comuníquese con nosotros.';
                break;

            case 'alerta_vencidos':
                $asunto = "Siniestro {$numero}: el cliente no entregó documentos";
                $titulo = 'Documentos vencidos sin respuesta';
                $p[] = "Se agotaron los recordatorios y el cliente no ha entregado todos los documentos del siniestro {$numero}.";
                $p[] = 'Revisa el caso: contacta al cliente o cancélalo con el motivo correspondiente.';
                $docs = $this->faltantes($s->id);
                break;

            default:
                $asunto = "Siniestro {$numero}: actualización";
                $titulo = 'Actualización de su siniestro';
                $p[] = "Hay una actualización en su siniestro {$numero}.";
        }

        if ($aseg && !in_array($tipo, self::INTERNAS, true)) {
            $contacto = array_filter([$aseg->telefono ? "Tel. {$aseg->telefono}" : null, $aseg->correo]);
            if ($contacto) {
                $cierre[] = 'Contacto: ' . implode(' · ', $contacto);
            }
        }

        return [
            'asunto' => $asunto,
            'vista'  => [
                'aseguradora' => $aseg->nombre ?? 'BalamCore',
                'titulo'      => $titulo,
                'saludo'      => $tipo === 'alerta_vencidos'
                    ? ($s->empleado->nombre ?? 'equipo')
                    : trim(($cliente->nombre ?? 'cliente') . ' ' . ($cliente->apellidos ?? '')),
                'parrafos'    => $p,
                'documentos'  => $docs,
                'cierre'      => $cierre,
                'numero'      => $numero,
            ],
        ];
    }

    /** Documentos obligatorios que todavía no se resuelven. */
    private function faltantes(int $idSiniestro): array
    {
        return SiniestroDocumento::where('id_siniestro', $idSiniestro)
            ->where('obligatorio', true)
            ->whereIn('estado', ['pendiente', 'rechazado'])
            ->orderBy('id')
            ->get()
            ->map(fn ($d) => [
                'nombre'  => $d->nombre,
                'detalle' => $d->estado === 'rechazado' && $d->motivo_rechazo ? 'Rechazado: ' . $d->motivo_rechazo : null,
                'limite'  => $d->fecha_limite?->format('d/m/Y'),
                'vencido' => $d->estaVencido(),
            ])->all();
    }
}
