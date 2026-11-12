<?php

namespace App\Support;

/**
 * Catálogo de incumplimientos del asegurado que el ajustador puede registrar en un dictamen.
 *
 *  efecto:
 *    reduce = descuenta una parte del daño evaluado (porcentaje o monto fijo, con motivo obligatorio)
 *    escala = no cambia el monto, pero obliga a que lo autorice por lo menos el nivel indicado
 *             (decisiones que no son del ajustador: se rechazan desde jurídico o suscripción)
 *  detectable: el sistema lo propone solo con los datos que ya tiene
 *  nivel_minimo: nivel de autorización mínimo cuando se aplica (auto | supervisor | gerente)
 *
 * Los textos de ayuda son orientativos: valida con el área legal de cada aseguradora
 * y con las condiciones generales de la póliza.
 */
class Incumplimientos
{
    public static function catalogo(): array
    {
        return [
            'aviso_tardio' => [
                'nombre'       => 'Aviso extemporáneo del siniestro',
                'efecto'       => 'reduce',
                'detectable'   => true,
                'nivel_minimo' => 'auto',
                'ayuda'        => 'La LSCS (art. 66) fija el plazo del aviso. La reducción debe corresponder al perjuicio que causó el retraso, no ser una sanción.',
            ],
            'medidas_omitidas' => [
                'nombre'       => 'No tomó medidas para evitar o reducir el daño',
                'efecto'       => 'reduce',
                'detectable'   => false,
                'nivel_minimo' => 'auto',
                'ayuda'        => 'Parte del daño que pudo evitarse si el asegurado hubiera actuado. Documéntalo con evidencia.',
            ],
            'preexistencia' => [
                'nombre'       => 'Daño preexistente',
                'efecto'       => 'reduce',
                'detectable'   => false,
                'nivel_minimo' => 'auto',
                'ayuda'        => 'Monto del daño que ya existía antes del siniestro. Sube evidencia que lo respalde.',
            ],
            'prima_pendiente' => [
                'nombre'       => 'Prima pendiente de pago',
                'efecto'       => 'escala',
                'detectable'   => false,
                'nivel_minimo' => 'supervisor',
                'ayuda'        => 'Confirma con cobranza si la póliza estaba al corriente a la fecha del hecho (LSCS art. 40).',
            ],
            'agravacion_riesgo' => [
                'nombre'       => 'Agravación del riesgo no avisada',
                'efecto'       => 'escala',
                'detectable'   => false,
                'nivel_minimo' => 'gerente',
                'ayuda'        => 'Puede liberar a la aseguradora del pago. La decisión no es del ajustador: pasa a revisión.',
            ],
            'declaracion_inexacta' => [
                'nombre'       => 'Declaración inexacta u omitida al contratar',
                'efecto'       => 'escala',
                'detectable'   => false,
                'nivel_minimo' => 'gerente',
                'ayuda'        => 'LSCS arts. 8 y 47. La decisión es de jurídico o suscripción.',
            ],
            'dolo_fraude' => [
                'nombre'       => 'Indicios de dolo o fraude',
                'efecto'       => 'escala',
                'detectable'   => false,
                'nivel_minimo' => 'gerente',
                'ayuda'        => 'Documenta los indicios. No lo resuelve el ajustador.',
            ],
        ];
    }

    /** Lo que necesita el formulario (sin datos internos). */
    public static function paraFormulario(): array
    {
        $out = [];
        foreach (self::catalogo() as $codigo => $d) {
            $out[] = [
                'codigo'     => $codigo,
                'nombre'     => $d['nombre'],
                'efecto'     => $d['efecto'],
                'detectable' => $d['detectable'],
                'ayuda'      => $d['ayuda'],
            ];
        }

        return $out;
    }
}
