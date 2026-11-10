<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Número de siniestro: SIN-AÑO-OFICINA-CONSECUTIVO (SIN-2026-001-000042).
 *
 * Oficina = id de la aseguradora a 3 dígitos. Antes el número no la incluía y
 * siniestros.numero_siniestro es único en toda la base, así que dos aseguradoras
 * habrían chocado en el SIN-2026-000001.
 *
 * El consecutivo sale de un contador con bloqueo de fila: no se repite aunque dos
 * personas abran siniestros al mismo tiempo. Solo se llama dentro de una transacción.
 */
class SiniestroNumeroService
{
    public function siguiente(int $idAseguradora): string
    {
        if (DB::transactionLevel() < 1) {
            throw new \LogicException('SiniestroNumeroService debe usarse dentro de una transacción.');
        }

        $anio    = (int) now()->year;
        $prefijo = sprintf('SIN-%d-%03d-', $anio, $idAseguradora);
        $llave   = ['id_aseguradora' => $idAseguradora, 'anio' => $anio];

        DB::table('siniestros_secuencias')->insertOrIgnore($llave + [
            'ultimo'     => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fila   = DB::table('siniestros_secuencias')->where($llave)->lockForUpdate()->first();
        $ultimo = (int) $fila->ultimo;

        // Contador nuevo: continúa después del mayor número que ya exista con este prefijo
        if ($ultimo === 0) {
            $ultimo = (int) DB::table('siniestros')
                ->where('id_aseguradora', $idAseguradora)
                ->whereRaw('numero_siniestro ~ ?', ['^' . $prefijo . '[0-9]{6}$'])
                ->selectRaw('MAX(CAST(RIGHT(numero_siniestro, 6) AS INTEGER)) AS maximo')
                ->value('maximo');
        }

        $siguiente = $ultimo + 1;

        if ($siguiente > 999999) {
            throw new \RuntimeException("Se agotó la numeración de siniestros de {$anio}.");
        }

        DB::table('siniestros_secuencias')->where($llave)->update([
            'ultimo'     => $siguiente,
            'updated_at' => now(),
        ]);

        return $prefijo . str_pad((string) $siguiente, 6, '0', STR_PAD_LEFT);
    }
}
