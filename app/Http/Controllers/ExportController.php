<?php

namespace App\Http\Controllers;

use App\Exports\CatalogoExport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Excel as ExcelFormat;
use Maatwebsite\Excel\Facades\Excel;

class ExportController extends Controller
{
    // dompdf es pesado con tablas grandes: 1000 filas es un límite seguro
    private const LIMITE_PDF = 1000;
    private const FILAS_VISTA = 6;

    public function exportar(Request $request, string $catalogo)
    {
        $cfg = config("exportables.{$catalogo}");
        abort_if(!$cfg, 404, 'Catálogo no exportable');

        // Tablas globales (sin aseguradora): solo superadmin
        if (!empty($cfg['solo_superadmin'])) {
            abort_unless($request->user()->es_superadmin, 403, 'No autorizado');
        }

        $formato = $request->get('formato', 'xlsx');
        if (!in_array($formato, ['xlsx', 'csv', 'pdf'])) {
            return response()->json(['message' => 'Formato no válido'], 422);
        }

        $query  = $this->construirQuery($cfg, $request);
        $nombre = $cfg['archivo'] . '_' . now()->format('Ymd_His');

        /* ───── PDF ───── */
        if ($formato === 'pdf') {
            // reorder() quita el ORDER BY para que el count funcione también en PostgreSQL
            $total = (clone $query)->reorder()->count();

            if ($total > self::LIMITE_PDF) {
                return response()->json([
                    'message' => 'El PDF admite hasta ' . number_format(self::LIMITE_PDF)
                        . ' registros y tu listado tiene ' . number_format($total)
                        . '. Usa Excel o CSV.',
                ], 422);
            }

            // Margen extra de memoria y tiempo solo para esta petición
            ini_set('memory_limit', '512M');
            set_time_limit(120);

            $colsPdf = isset($cfg['pdf'])
                ? Arr::only($cfg['columnas'], $cfg['pdf'])
                : array_slice($cfg['columnas'], 0, 8, true);

            // lazy() trae los registros por bloques (respeta el with)
            // y evita tener todos los modelos cargados a la vez
            $filas = [];
            foreach ($query->lazy(200) as $r) {
                $filas[] = CatalogoExport::fila($r, $colsPdf);
            }

            $pdf = Pdf::loadView('exports.catalogo', [
                'titulo'  => $cfg['titulo'],
                'headers' => array_keys($colsPdf),
                'filas'   => $filas, // arreglo normal (no Collection)
                'fecha'   => now()->format('d/m/Y H:i'),
                'filtros' => $this->textoFiltros($cfg, $request),
            ])
                ->setPaper('a4', 'landscape')
                ->setOptions([
                    'isRemoteEnabled'      => false,
                    'isHtml5ParserEnabled' => true,
                    'dpi'                  => 72,
                ]);

            return $pdf->download("{$nombre}.pdf");
        }

        /* ───── Excel / CSV ───── */
        return Excel::download(
            new CatalogoExport($query, $cfg['columnas'], $cfg['titulo']),
            "{$nombre}.{$formato}",
            $formato === 'csv' ? ExcelFormat::CSV : ExcelFormat::XLSX
        );
    }

    /** Vista previa: primeras filas + total, para el modal de descarga */
    public function vista(Request $request, string $catalogo)
    {
        $cfg = config("exportables.{$catalogo}");
        abort_if(!$cfg, 404, 'Catálogo no exportable');

        if (!empty($cfg['solo_superadmin'])) {
            abort_unless($request->user()->es_superadmin, 403, 'No autorizado');
        }

        $query = $this->construirQuery($cfg, $request);

        $total     = (clone $query)->reorder()->count();
        $registros = $query->limit(self::FILAS_VISTA)->get();

        $colsPdf = isset($cfg['pdf'])
            ? Arr::only($cfg['columnas'], $cfg['pdf'])
            : array_slice($cfg['columnas'], 0, 8, true);

        return response()->json([
            'total'      => $total,
            'limite_pdf' => self::LIMITE_PDF,
            'completo'   => [
                'columnas' => array_keys($cfg['columnas']),
                'filas'    => $registros
                    ->map(fn ($r) => CatalogoExport::fila($r, $cfg['columnas']))
                    ->values(),
            ],
            'pdf' => [
                'columnas' => array_keys($colsPdf),
                'filas'    => $registros
                    ->map(fn ($r) => CatalogoExport::fila($r, $colsPdf))
                    ->values(),
            ],
        ]);
    }

    /** Mismos filtros que usan los listados, pero sin paginar */
    private function construirQuery(array $cfg, Request $request)
    {
        // Seguridad: cada catálogo debe declarar su 'tenant' (columna, ruta o false)
        abort_unless(array_key_exists('tenant', $cfg), 500, 'Catálogo mal configurado');
        abort_if(
            !$cfg['tenant'] && empty($cfg['solo_superadmin']),
            500,
            'Catálogo sin filtro de aseguradora'
        );

        $query = $cfg['modelo']::query();

        if (!empty($cfg['with'])) {
            $query->with($cfg['with']);
        }

        // Multi-tenant: solo datos de la aseguradora del usuario
        if ($cfg['tenant']) {
            $idAseg = $request->user()->id_aseguradora;

            if (str_contains($cfg['tenant'], '.')) {
                // 'siniestro.id_aseguradora' → a través de la relación
                $rel = Str::beforeLast($cfg['tenant'], '.');
                $col = Str::afterLast($cfg['tenant'], '.');
                $query->whereHas($rel, fn ($q) => $q->where($col, $idAseg));
            } else {
                $query->where($cfg['tenant'], $idAseg);
            }
        }

        // Condiciones fijas del catálogo: [columna, operador, valor]
        foreach ($cfg['donde'] ?? [] as [$col, $op, $val]) {
            $op === 'in'
                ? $query->whereIn($col, $val)
                : $query->where($col, $op, $val);
        }

        // Rango de días hacia adelante (pólizas por vencer / por renovar)
        if (!empty($cfg['ventana'])) {
            $v    = $cfg['ventana'];
            $dias = max(0, (int) $request->get($v['param'], $v['default']));

            if (empty($v['incluir_vencidas'])) {
                $query->whereDate($v['columna'], '>=', now()->toDateString());
            }
            $query->whereDate($v['columna'], '<=', now()->addDays($dias)->toDateString());
        }

        // Buscador (columnas propias o 'relacion.columna')
        if ($request->filled('search') && !empty($cfg['busqueda'])) {
            $search = $request->search;

            $query->where(function ($q) use ($cfg, $search) {
                foreach ($cfg['busqueda'] as $col) {
                    if (str_contains($col, '.')) {
                        $rel   = Str::beforeLast($col, '.');
                        $campo = Str::afterLast($col, '.');
                        $q->orWhereHas($rel, fn ($r) => $r->where($campo, 'LIKE', "%{$search}%"));
                    } else {
                        $q->orWhere($col, 'LIKE', "%{$search}%");
                    }
                }
            });
        }

        // Filtros (estatus, etc.)
        foreach ($cfg['filtros'] ?? [] as $param => $col) {
            $valor = $request->get($param);
            if ($valor !== null && $valor !== '' && $valor !== 'Todos') {
                $query->where($col, $valor);
            }
        }

        [$colOrden, $dir] = $cfg['orden'] ?? ['id', 'desc'];

        return $query->orderBy($colOrden, $dir);
    }

    /** Texto de los filtros activos, para el encabezado del PDF */
    private function textoFiltros(array $cfg, Request $request): string
    {
        $txt = [];

        if ($request->filled('search')) {
            $txt[] = 'Búsqueda: "' . $request->search . '"';
        }

        foreach ($cfg['filtros'] ?? [] as $param => $col) {
            $valor = $request->get($param);
            if ($valor !== null && $valor !== '' && $valor !== 'Todos') {
                $txt[] = ucfirst($param) . ': ' . $valor;
            }
        }

        if (!empty($cfg['ventana'])) {
            $v     = $cfg['ventana'];
            $txt[] = 'Próximos ' . (int) $request->get($v['param'], $v['default']) . ' días';
        }

        return implode(' · ', $txt);
    }
}