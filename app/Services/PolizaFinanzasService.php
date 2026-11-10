<?php

namespace App\Services;

use App\Models\AseguradoraConfiguracion;
use Carbon\Carbon;

/**
 * Números de la póliza que SIEMPRE calcula el servidor (el front solo los muestra):
 *   subtotal = prima neta + derecho de póliza
 *   IVA      = tasa de la aseguradora sobre el subtotal (0 en los ramos exentos; por defecto solo "vida")
 *   total    = subtotal + IVA
 *
 * No incluye recargo por pago fraccionado (no existe en el modelo): el pago periódico es total / número de pagos.
 *
 * Endosos: cuando cambia la prima de una póliza vigente, la diferencia se cobra (o devuelve) a prorrata,
 * es decir, solo por los días que faltan desde la fecha de efecto hasta el fin de la vigencia.
 */
class PolizaFinanzasService
{
    public const MESES_POR_FORMA_PAGO = ['mensual' => 1, 'trimestral' => 3, 'semestral' => 6, 'anual' => 12];

    public function parametros(int $idAseguradora): array
    {
        $cfg = AseguradoraConfiguracion::de($idAseguradora);

        return [
            'tasa_iva'          => (float) $cfg->tasa_iva,
            'ramos_exentos_iva' => $cfg->ramos_exentos_iva ?? [],
        ];
    }

    public function calcular(string $ramo, $primaNeta, $derecho, int $idAseguradora): array
    {
        $p        = $this->parametros($idAseguradora);
        $subtotal = round((float) $primaNeta + (float) $derecho, 2);
        $exento   = in_array($ramo, $p['ramos_exentos_iva'], true);
        $iva      = $exento ? 0.0 : round($subtotal * $p['tasa_iva'] / 100, 2);

        return [
            'subtotal'    => $subtotal,
            'iva'         => $iva,
            'iva_exento'  => $exento,
            'tasa_iva'    => $p['tasa_iva'],
            'prima_total' => round($subtotal + $iva, 2),
        ];
    }

    /** Cuántos pagos tiene la vigencia según la forma de pago (contado y anual = 1). */
    public function numeroPagos(string $formaPago, $fechaInicio, $fechaFin): int
    {
        $meses = self::MESES_POR_FORMA_PAGO[$formaPago] ?? null;
        if (!$meses) {
            return 1;
        }

        $duracion = max(1, (int) round(Carbon::parse($fechaInicio)->floatDiffInMonths(Carbon::parse($fechaFin))));

        return max(1, intdiv($duracion, $meses));
    }

    public function plan(string $formaPago, float $total, $fechaInicio, $fechaFin): array
    {
        $pagos = $this->numeroPagos($formaPago, $fechaInicio, $fechaFin);

        return [
            'numero_pagos'  => $pagos,
            'pago_periodico' => round($total / $pagos, 2),
        ];
    }

    /**
     * Ajuste de prima de un endoso, a prorrata.
     *
     *   ajuste = (prima total nueva - prima total anterior) × días que faltan desde la fecha de efecto / días de la vigencia
     *
     * Tipo de endoso (las aseguradoras usan letras parecidas; el significado puede variar un poco entre ellas):
     *   A = aumenta la prima (se cobra)    B = disminuye la prima (se devuelve)    D = no mueve la prima
     *
     * @return array{tipo:string, ajuste_prima:float, dias_restantes:int, dias_totales:int}
     */
    public function ajusteEndoso(float $totalAnterior, float $totalNuevo, $fechaEfecto, $fechaInicio, $fechaFin): array
    {
        $inicio = Carbon::parse($fechaInicio)->startOfDay();
        $fin    = Carbon::parse($fechaFin)->startOfDay();
        $efecto = Carbon::parse($fechaEfecto)->startOfDay();

        // Días entre dos fechas (sin depender de la versión de Carbon)
        $dias = fn (Carbon $a, Carbon $b): int => (int) round(($b->getTimestamp() - $a->getTimestamp()) / 86400);

        $totales    = max(1, $dias($inicio, $fin));
        $restantes  = max(0, min($totales, $dias($efecto, $fin)));
        $ajuste     = round(($totalNuevo - $totalAnterior) * ($restantes / $totales), 2);

        return [
            'tipo'           => $ajuste > 0.004 ? 'A' : ($ajuste < -0.004 ? 'B' : 'D'),
            'ajuste_prima'   => $ajuste,
            'dias_restantes' => $restantes,
            'dias_totales'   => $totales,
        ];
    }
}
