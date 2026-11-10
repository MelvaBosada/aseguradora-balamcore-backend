<?php

namespace App\Console\Commands;

use App\Models\Evidencias;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Pasa los archivos que ya existen en storage/app/public al disco privado.
 * La ruta guardada en la base no cambia, así que no hay que tocar datos.
 * Se puede correr varias veces: solo mueve lo que falta.
 *
 *   php artisan evidencias:mover-privado --simular   (solo cuenta)
 *   php artisan evidencias:mover-privado
 */
class MoverEvidenciasAPrivado extends Command
{
    protected $signature = 'evidencias:mover-privado {--simular : Solo muestra lo que haría}';
    protected $description = 'Mueve las evidencias del disco público al disco privado configurado.';

    public function handle(): int
    {
        $nombreDestino = config('evidencias.disk', 'local');

        if ($nombreDestino === 'public') {
            $this->error('EVIDENCIAS_DISK apunta al disco público. Cámbialo a uno privado.');
            return self::FAILURE;
        }

        $origen  = Storage::disk('public');
        $destino = Storage::disk($nombreDestino);
        $simular = (bool) $this->option('simular');

        $c = ['movidos' => 0, 'ya_estaban' => 0, 'faltantes' => 0, 'errores' => 0];

        Evidencias::withTrashed()->orderBy('id')->chunkById(100, function ($lote) use ($origen, $destino, $simular, &$c) {
            foreach ($lote as $e) {
                $ruta = $e->ruta_archivo;
                if (!$ruta) {
                    continue;
                }

                if (!$origen->exists($ruta)) {
                    if ($destino->exists($ruta)) {
                        $c['ya_estaban']++;
                    } else {
                        $c['faltantes']++;
                        $this->warn("Evidencia #{$e->id}: el archivo no existe en ningún disco ({$ruta}).");
                    }
                    continue;
                }

                if ($simular) {
                    $c['movidos']++;
                    continue;
                }

                try {
                    $flujo = $origen->readStream($ruta);
                    $destino->writeStream($ruta, $flujo);
                    if (is_resource($flujo)) {
                        fclose($flujo);
                    }

                    // Solo se borra el original si la copia quedó completa
                    if (!$destino->exists($ruta) || $destino->size($ruta) !== $origen->size($ruta)) {
                        $c['errores']++;
                        $this->error("Evidencia #{$e->id}: la copia no coincide, se conserva el original.");
                        continue;
                    }

                    $origen->delete($ruta);
                    $c['movidos']++;
                } catch (\Throwable $ex) {
                    $c['errores']++;
                    $this->error("Evidencia #{$e->id}: {$ex->getMessage()}");
                }
            }
        });

        $this->info(($simular ? '[SIMULACIÓN] ' : '') . sprintf(
            'Movidos: %d · Ya estaban: %d · Sin archivo: %d · Errores: %d',
            $c['movidos'], $c['ya_estaban'], $c['faltantes'], $c['errores']
        ));

        return $c['errores'] ? self::FAILURE : self::SUCCESS;
    }
}
