<?php

namespace App\Http\Controllers;

use App\Exports\CatalogoExport;
use App\Models\Evaluacion;
use App\Models\Evidencias;
use App\Models\Indemnizaciones;
use App\Models\Siniestro;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    private const PREVIEW_FILAS = 10;
    private const LIMITE_PDF    = 2000;

    /** Modelos que cuelgan de un siniestro: se exportan solo los siniestros que el usuario puede ver. */
    private const RELACION_SINIESTRO = [
        Evaluacion::class      => 'siniestro',
        Indemnizaciones::class => 'siniestro',
        Evidencias::class      => 'siniestro',
    ];

    /** GET .../exportar/{catalogo}/vista */
    public function vista(Request $request): JsonResponse
    {
        $cfg   = $this->config($request);
        $query = $this->query($request, $cfg);
        $total = (clone $query)->count();

        $completo = $this->columnasPara($cfg, 'xlsx');
        $pdf      = $this->columnasPara($cfg, 'pdf');

        // Una sola consulta; de ahí salen las dos vistas previas
        $registros = (clone $query)->limit(self::PREVIEW_FILAS)->get();

        return response()->json([
            'total'      => $total,
            'limite_pdf' => self::LIMITE_PDF,
            'completo'   => [
                'columnas' => array_keys($completo),
                'filas'    => $registros->map(fn ($r) => $this->fila($r, $completo))->values(),
            ],
            'pdf'        => [
                'columnas' => array_keys($pdf),
                'filas'    => $registros->map(fn ($r) => $this->fila($r, $pdf))->values(),
            ],
        ]);
    }

    /** GET .../exportar/{catalogo}?formato=pdf|xlsx|csv */
    public function descargar(Request $request)
    {
        $cfg     = $this->config($request);
        $formato = strtolower((string) $request->query('formato', 'xlsx'));
        $nombre  = $cfg['archivo'] . '_' . now()->format('Y-m-d');

        if ($formato === 'pdf') {
            $columnas = $this->columnasPara($cfg, 'pdf');
            $query    = $this->query($request, $cfg);

            if ((clone $query)->count() > self::LIMITE_PDF) {
                return response()->json([
                    'message' => 'El PDF admite hasta ' . self::LIMITE_PDF . ' registros. Usa Excel o CSV, o filtra el listado.',
                ], 422);
            }

            $filas = $query->get()
                ->map(fn ($r) => CatalogoExport::fila($r, $columnas))
                ->all();

            return Pdf::loadView('exports.catalogo-pdf', [
                'titulo'  => $cfg['titulo'],
                'fecha'   => now()->format('d/m/Y H:i'),
                'headers' => array_keys($columnas),
                'filas'   => $filas,
                'filtros' => '',
            ])->setPaper('a4', 'landscape')->download($nombre . '.pdf');
        }

        $columnas = $this->columnasPara($cfg, 'xlsx');
        $export   = new CatalogoExport($this->query($request, $cfg), $columnas, $cfg['titulo']);

        if ($formato === 'csv') {
            return Excel::download($export, $nombre . '.csv', \Maatwebsite\Excel\Excel::CSV);
        }

        return Excel::download($export, $nombre . '.xlsx', \Maatwebsite\Excel\Excel::XLSX);
    }

    /* ───────────────────────── Núcleo ───────────────────────── */

    private function config(Request $request): array
    {
        // Toma el primer parámetro de la ruta, sin importar cómo se llame ({catalogo}, {tipo}...)
        $clave = collect($request->route()->parameters())->first();
        $cfg   = config("exportables.$clave");
        abort_unless($cfg, 404, 'Catálogo no encontrado.');

        $user = $request->user();

        if (!empty($cfg['solo_superadmin']) && !$this->esSuperadmin($user)) {
            abort(403, 'Sin acceso a este catálogo.');
        }
        if (!empty($cfg['permiso']) && !$this->esSuperadmin($user) && !$user->tienePermiso($cfg['permiso'])) {
            abort(403, 'No tienes permiso para exportar este catálogo.');
        }

        return $cfg;
    }

    private function esSuperadmin($user): bool
    {
        return (bool) ($user->es_superadmin ?? false);
    }

    private function columnasPara(array $cfg, string $formato): array
    {
        $todas = $cfg['columnas'];
        if ($formato !== 'pdf') {
            return $todas;
        }

        $encabezados = $cfg['pdf'] ?? array_slice(array_keys($todas), 0, 8);
        $out = [];
        foreach ($encabezados as $h) {
            if (isset($todas[$h])) {
                $out[$h] = $todas[$h];
            }
        }
        return $out;
    }

    private function fila($registro, array $columnas): array
    {
        return array_map(
            fn ($v) => is_scalar($v) || $v === null ? $v : json_encode($v, JSON_UNESCAPED_UNICODE),
            CatalogoExport::fila($registro, $columnas)
        );
    }

    private function query(Request $request, array $cfg): Builder
    {
        $q = ($cfg['modelo'])::query();

        if (!empty($cfg['with'])) {
            $q->with($cfg['with']);
        }

        if (!empty($cfg['tenant'])) {
            $this->whereRuta($q, $cfg['tenant'], $request->user()->id_aseguradora);
        }

        $this->aplicarVisibilidad($q, $cfg['modelo'], $request->user());

        foreach ($cfg['donde'] ?? [] as [$col, $op, $val]) {
            $op === 'in' ? $q->whereIn($col, $val) : $q->where($col, $op, $val);
        }

        if (!empty($cfg['ventana'])) {
            $v    = $cfg['ventana'];
            $dias = max(0, (int) $request->query($v['param'], $v['default']));
            $q->whereDate($v['columna'], '<=', now()->addDays($dias)->toDateString());
            if (empty($v['incluir_vencidas'])) {
                $q->whereDate($v['columna'], '>=', now()->toDateString());
            }
        }

        foreach ($cfg['filtros'] ?? [] as $param => $columna) {
            $valor = $request->query($param);
            if ($valor === null || $valor === '' || strcasecmp((string) $valor, 'Todos') === 0) {
                continue;
            }
            $q->where($columna, $valor);
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '' && !empty($cfg['busqueda'])) {
            $q->where(function (Builder $w) use ($cfg, $search) {
                foreach ($cfg['busqueda'] as $campo) {
                    $this->orWhereRuta($w, $campo, "%{$search}%");
                }
            });
        }

        [$col, $dir] = $cfg['orden'] ?? ['id', 'desc'];
        return $q->orderBy($col, $dir);
    }

    /** "Mis casos": sin permiso de vista total, solo lo que cuelga de los siniestros del propio usuario. */
    private function aplicarVisibilidad(Builder $q, string $modelo, $user): void
    {
        if ($modelo === Siniestro::class) {
            $q->visiblePara($user);
            return;
        }

        $relacion = self::RELACION_SINIESTRO[$modelo] ?? null;
        if ($relacion) {
            $q->whereHas($relacion, fn ($s) => $s->visiblePara($user));
        }
    }

    /** where sobre 'rel1.rel2.columna' (whereHas para las relaciones). */
    private function whereRuta(Builder $q, string $ruta, $valor): void
    {
        if (!str_contains($ruta, '.')) {
            $q->where($ruta, $valor);
            return;
        }
        $pos = strrpos($ruta, '.');
        $q->whereHas(substr($ruta, 0, $pos), fn ($s) => $s->where(substr($ruta, $pos + 1), $valor));
    }

    private function orWhereRuta(Builder $q, string $ruta, string $patron): void
    {
        if (!str_contains($ruta, '.')) {
            $q->orWhere($ruta, 'ILIKE', $patron);
            return;
        }
        $pos = strrpos($ruta, '.');
        $q->orWhereHas(substr($ruta, 0, $pos), fn ($s) => $s->where(substr($ruta, $pos + 1), 'ILIKE', $patron));
    }
}
