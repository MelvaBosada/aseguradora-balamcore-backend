<?php

namespace App\Support;

use App\Models\Bienes;
use App\Models\Cliente;
use App\Models\Evaluacion;
use App\Models\Evidencias;
use App\Models\Indemnizaciones;
use App\Models\Poliza;
use App\Models\Reportes;
use App\Models\Siniestro;
use Illuminate\Database\Eloquent\Model;

/**
 * Cada método devuelve el mensaje de error si NO se puede, o null si sí se puede.
 *
 * Idea general (se mantiene siempre):
 *  - Un registro solo va a la papelera si ya no tiene hijos activos.
 *  - Un registro solo se restaura si su padre NO está en la papelera.
 *  - Solo se borra definitivo si ya no tiene hijos (ni siquiera en la papelera).
 */
class ReglasPapelera
{
    /** ¿Se puede mandar a la papelera? */
    public static function alEliminar(string $modulo, Model $m): ?string
    {
        switch ($modulo) {
            case 'clientes':
                if (Poliza::where('id_cliente', $m->id)->exists()) {
                    return 'No se puede eliminar el cliente porque tiene pólizas. Elimina primero sus pólizas.';
                }
                if (Bienes::where('id_cliente', $m->id)->exists()) {
                    return 'No se puede eliminar el cliente porque tiene bienes registrados. Elimina primero sus bienes.';
                }
                return null;

            case 'polizas':
                if (Reportes::where('id_poliza', $m->id)->exists()) {
                    return 'No se puede eliminar la póliza porque tiene reportes. Elimina primero sus reportes.';
                }
                return null;

            case 'reportes':
                if (Siniestro::where('id_reporte', $m->id)->exists()) {
                    return 'No se puede eliminar el reporte porque ya se convirtió en siniestro.';
                }
                return null;

            case 'siniestros':
                if (Indemnizaciones::where('id_siniestro', $m->id)->exists()) {
                    return 'No se puede eliminar el siniestro porque tiene indemnizaciones.';
                }
                if (Evaluacion::where('id_siniestro', $m->id)->exists()) {
                    return 'No se puede eliminar el siniestro porque tiene evaluaciones.';
                }
                if (Evidencias::where('id_siniestro', $m->id)->exists()) {
                    return 'No se puede eliminar el siniestro porque tiene evidencias. Elimina primero sus evidencias.';
                }
                return null;

            case 'indemnizaciones':
                if ($m->estatus === 'pagada') {
                    return 'No se puede eliminar una indemnización que ya fue pagada.';
                }
                return null;

            case 'bienes':
                // Un bien solo puede estar en pólizas de su mismo cliente; el filtro por
                // id_cliente evita falsos bloqueos por un id_poliza viejo o por defecto.
                $enPolizaActiva = $m->id_poliza && Poliza::where('id', $m->id_poliza)
                    ->where('id_cliente', $m->id_cliente)
                    ->whereNotIn('estatus', ['cancelada', 'vencida'])
                    ->exists();

                if ($enPolizaActiva) {
                    return 'El bien está asegurado en una póliza vigente. Quítalo de la póliza primero.';
                }
                if (Evidencias::where('id_bien', $m->id)->exists()) {
                    return 'No se puede eliminar el bien porque tiene evidencias ligadas.';
                }
                return null;
        }

        return null; // evidencias, coberturas: sin restricción
    }

    /** ¿Se puede sacar de la papelera? */
    public static function alRestaurar(string $modulo, Model $m): ?string
    {
        switch ($modulo) {
            case 'clientes':
                $otros = Cliente::where('id_aseguradora', $m->id_aseguradora)->where('id', '!=', $m->id);

                if ((clone $otros)->where('rfc', $m->rfc)->exists()) {
                    return 'Ya existe un cliente activo con ese RFC. Cámbiale el RFC al otro o elimina este definitivamente.';
                }
                if ((clone $otros)->where('correo', $m->correo)->exists()) {
                    return 'Ya existe un cliente activo con ese correo.';
                }
                if ((clone $otros)->where('curp', $m->curp)->exists()) {
                    return 'Ya existe un cliente activo con esa CURP.';
                }
                return null;

            case 'polizas':
                $cliente = Cliente::withTrashed()->find($m->id_cliente);
                if (!$cliente || $cliente->trashed()) {
                    return 'Primero restaura al cliente de esta póliza.';
                }
                if (Poliza::where('id_aseguradora', $m->id_aseguradora)
                    ->where('numero_poliza', $m->numero_poliza)
                    ->where('id', '!=', $m->id)
                    ->exists()) {
                    return 'Ya existe una póliza activa con ese número.';
                }
                return null;

            case 'reportes':
                $poliza = Poliza::withTrashed()->find($m->id_poliza);
                if (!$poliza || $poliza->trashed()) {
                    return 'Primero restaura la póliza de este reporte.';
                }
                return null;

            case 'siniestros':
                $reporte = Reportes::withTrashed()->find($m->id_reporte);
                if (!$reporte || $reporte->trashed()) {
                    return 'Primero restaura el reporte de este siniestro.';
                }
                if (Siniestro::where('id_reporte', $m->id_reporte)->where('id', '!=', $m->id)->exists()
                    || ($reporte->id_siniestro && (int) $reporte->id_siniestro !== (int) $m->id)) {
                    return 'Ese reporte ya tiene otro siniestro activo.';
                }
                return null;

            case 'indemnizaciones':
            case 'evidencias':
                $siniestro = Siniestro::withTrashed()->find($m->id_siniestro);
                if (!$siniestro || $siniestro->trashed()) {
                    return 'Primero restaura el siniestro de este registro.';
                }
                return null;

            case 'bienes':
                $cliente = Cliente::withTrashed()->find($m->id_cliente);
                if (!$cliente || $cliente->trashed()) {
                    return 'Primero restaura al cliente de este bien.';
                }
                return null;
        }

        return null; // coberturas
    }

    /** ¿Se puede borrar de forma definitiva (desde la papelera)? */
    public static function alBorrarDefinitivo(string $modulo, Model $m): ?string
    {
        switch ($modulo) {
            case 'clientes':
                if (Poliza::withTrashed()->where('id_cliente', $m->id)->exists()
                    || Bienes::withTrashed()->where('id_cliente', $m->id)->exists()) {
                    return 'Este cliente aún tiene pólizas o bienes (incluso en la papelera). Bórralos definitivamente primero.';
                }
                return null;

            case 'polizas':
                if (Reportes::withTrashed()->where('id_poliza', $m->id)->exists()) {
                    return 'Esta póliza aún tiene reportes (incluso en la papelera). Bórralos definitivamente primero.';
                }
                return null;

            case 'reportes':
                if (Siniestro::withTrashed()->where('id_reporte', $m->id)->exists()) {
                    return 'Este reporte aún tiene un siniestro (incluso en la papelera). Bórralo definitivamente primero.';
                }
                return null;

            case 'siniestros':
                if (Indemnizaciones::withTrashed()->where('id_siniestro', $m->id)->exists()
                    || Evaluacion::where('id_siniestro', $m->id)->exists()
                    || Evidencias::withTrashed()->where('id_siniestro', $m->id)->exists()) {
                    return 'Este siniestro aún tiene indemnizaciones, evaluaciones o evidencias (incluso en la papelera). Bórralas definitivamente primero.';
                }
                return null;
        }

        return null; // indemnizaciones, evidencias, bienes, coberturas
    }
}
