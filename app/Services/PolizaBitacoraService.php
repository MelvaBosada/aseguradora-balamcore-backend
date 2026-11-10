<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Bitácora de movimientos de una póliza: qué cambió, quién lo hizo y cuándo.
 * Se escribe con DB::table para no depender de $fillable de ningún modelo y para que nadie la edite por accidente.
 */
class PolizaBitacoraService
{
    /**
     * @param array|null $cambios  { campo: { antes: x, despues: y } }
     */
    public function registrar(int $idPoliza, int $idAseguradora, string $accion, string $resumen, ?array $cambios = null): void
    {
        $user = auth()->user();

        DB::table('polizas_bitacora')->insert([
            'id_poliza'      => $idPoliza,
            'id_aseguradora' => $idAseguradora,
            'id_usuario'     => $user?->id,
            'usuario_nombre' => $this->nombreDe($user),
            'accion'         => $accion,
            'resumen'        => mb_substr($resumen, 0, 255),
            'cambios'        => $cambios ? json_encode($cambios, JSON_UNESCAPED_UNICODE) : null,
            'ip'             => app()->runningInConsole() ? null : request()->ip(),
            'created_at'     => now(),
        ]);
    }

    /** Movimientos de la póliza, del más reciente al más antiguo. */
    public function historial(int $idPoliza, int $idAseguradora, int $limite = 200): array
    {
        return DB::table('polizas_bitacora')
            ->where('id_poliza', $idPoliza)
            ->where('id_aseguradora', $idAseguradora)
            ->orderByDesc('id')
            ->limit($limite)
            ->get()
            ->map(fn ($r) => [
                'id'      => $r->id,
                'accion'  => $r->accion,
                'resumen' => $r->resumen,
                'usuario' => $r->usuario_nombre ?: 'Sistema',
                'fecha'   => Carbon::parse($r->created_at)->format('Y-m-d H:i'),
                'cambios' => $r->cambios ? json_decode($r->cambios, true) : null,
            ])
            ->values()
            ->all();
    }

    /** [id_usuario => nombre] tomado de la propia bitácora (sirve para mostrar quién emitió cada endoso). */
    public function nombresPorUsuario(array $idsUsuario, int $idAseguradora): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $idsUsuario))));
        if (!$ids) {
            return [];
        }

        return DB::table('polizas_bitacora')
            ->where('id_aseguradora', $idAseguradora)
            ->whereIn('id_usuario', $ids)
            ->whereNotNull('usuario_nombre')
            ->orderByDesc('id')
            ->get(['id_usuario', 'usuario_nombre'])
            ->unique('id_usuario')
            ->pluck('usuario_nombre', 'id_usuario')
            ->all();
    }

    /** Nombre del trabajador (empleado ligado al usuario); si no hay, el nombre o correo del usuario. */
    private function nombreDe($user): ?string
    {
        if (!$user) {
            return null;
        }

        $emp = $user->empleado ?? null;
        $nombre = trim(($emp->nombre ?? '') . ' ' . ($emp->apellidos ?? ''));

        return $nombre !== '' ? $nombre : ($user->name ?? $user->email ?? null);
    }
}
