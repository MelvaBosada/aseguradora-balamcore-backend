<?php

namespace App\Http\Controllers;

use App\Models\Bienes;
use App\Models\Cliente;
use App\Models\Cobertura;
use App\Models\Evidencias;
use App\Models\Indemnizaciones;
use App\Models\Poliza;
use App\Models\Reportes;
use App\Models\Siniestro;
use App\Support\ReglasPapelera;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PapeleraController extends Controller
{
    /** Módulos con papelera y las columnas donde busca el buscador. */
    private const MODULOS = [
        'clientes'        => ['modelo' => Cliente::class,         'buscar' => ['nombre', 'apellidos', 'rfc', 'correo']],
        'polizas'         => ['modelo' => Poliza::class,          'buscar' => ['numero_poliza']],
        'reportes'        => ['modelo' => Reportes::class,        'buscar' => ['nombre_reportante', 'descripcion']],
        'siniestros'      => ['modelo' => Siniestro::class,       'buscar' => ['numero_siniestro', 'tipo']],
        'indemnizaciones' => ['modelo' => Indemnizaciones::class, 'buscar' => ['titular_cuenta', 'observaciones']],
        'evidencias'      => ['modelo' => Evidencias::class,      'buscar' => ['nombre_archivo', 'descripcion']],
        'bienes'          => ['modelo' => Bienes::class,          'buscar' => ['descripcion', 'marca', 'modelo']],
        'coberturas'      => ['modelo' => Cobertura::class,       'buscar' => ['nombre']],
    ];

    /* ───────── Listado ───────── */

    /** GET /papelera/resumen → cuántos registros hay en la papelera por módulo. */
    public function resumen(Request $request)
    {
        $this->soloAdmin($request);
        $aseguradora = $this->aseguradora($request);

        $conteos = [];
        foreach (array_keys(self::MODULOS) as $modulo) {
            $conteos[$modulo] = $this->consulta($modulo, $aseguradora)->count();
        }

        return response()->json($conteos);
    }

    /** GET /papelera/{modulo}?search=&page=&per_page= */
    public function index(Request $request, string $modulo)
    {
        $this->soloAdmin($request);
        $this->validarModulo($modulo);

        $query = $this->consulta($modulo, $this->aseguradora($request));

        if ($request->filled('search')) {
            $texto = '%' . $request->input('search') . '%';

            $query->where(function ($q) use ($modulo, $texto) {
                foreach (self::MODULOS[$modulo]['buscar'] as $columna) {
                    $q->orWhere($columna, 'ILIKE', $texto);
                }
            });
        }

        $paginado = $query->orderByDesc('deleted_at')->paginate($request->integer('per_page', 8));

        // Correo de quien eliminó cada registro
        $idsUsuarios = $paginado->getCollection()->pluck('deleted_by')->filter()->unique()->values();
        $usuarios = DB::table('usuarios')->whereIn('id', $idsUsuarios)->pluck('correo', 'id')->all();

        return response()->json([
            'data'         => $paginado->getCollection()->map(fn ($m) => $this->fila($modulo, $m, $usuarios))->values(),
            'current_page' => $paginado->currentPage(),
            'last_page'    => $paginado->lastPage(),
            'per_page'     => $paginado->perPage(),
            'total'        => $paginado->total(),
        ]);
    }

    /* ───────── Acciones ───────── */

    /** POST /papelera/{modulo}/{id}/restaurar */
    public function restaurar(Request $request, string $modulo, $id)
    {
        $registro = $this->encontrar($request, $modulo, $id);

        if ($mensaje = ReglasPapelera::alRestaurar($modulo, $registro)) {
            return response()->json(['message' => $mensaje], 422);
        }

        DB::transaction(function () use ($modulo, $registro) {
            $registro->sacarDePapelera();

            // Un siniestro restaurado vuelve a quedar ligado a su reporte
            if ($modulo === 'siniestros') {
                Reportes::where('id', $registro->id_reporte)->update(['id_siniestro' => $registro->id]);
            }
        });

        return response()->json(['message' => 'Registro restaurado correctamente.']);
    }

    /** DELETE /papelera/{modulo}/{id} → borrado DEFINITIVO (solo desde la papelera). */
    public function destroy(Request $request, string $modulo, $id)
    {
        $registro = $this->encontrar($request, $modulo, $id);

        if ($mensaje = ReglasPapelera::alBorrarDefinitivo($modulo, $registro)) {
            return response()->json(['message' => $mensaje], 422);
        }

        try {
            DB::transaction(function () use ($modulo, $registro) {
                if ($modulo === 'polizas') {
                    // Los bienes se quedan, solo se desligan de la póliza
                    Bienes::withTrashed()->where('id_poliza', $registro->id)->update(['id_poliza' => null]);
                }

                $registro->forceDelete();
            });
        } catch (QueryException $e) {
            // 23503 = violación de llave foránea (otra tabla todavía lo usa)
            if ((string) $e->getCode() === '23503') {
                return response()->json([
                    'message' => 'No se puede borrar definitivamente: otros registros todavía dependen de este.',
                ], 409);
            }
            throw $e;
        }

        // El archivo físico solo se borra cuando ya no existe el registro
        if ($modulo === 'evidencias' && $registro->ruta_archivo) {
            Storage::disk('public')->delete($registro->ruta_archivo);
        }

        return response()->json(['message' => 'Registro eliminado definitivamente.']);
    }

    /* ───────── Helpers ───────── */

    private function aseguradora(Request $request): int
    {
        return (int) $request->user()->id_aseguradora;
    }

    /** La papelera es solo para el Administrador (o el superadmin). */
    private function soloAdmin(Request $request): void
    {
        $usuario = $request->user();

        $esAdmin = $usuario->es_superadmin
            || DB::table('roles')->where('id', $usuario->id_rol)->value('nombre') === 'Administrador';

        abort_unless($esAdmin, 403, 'Solo un administrador puede usar la papelera.');
    }

    private function validarModulo(string $modulo): void
    {
        abort_unless(isset(self::MODULOS[$modulo]), 404, 'Módulo no válido.');
    }

    /** Solo registros en papelera y SOLO de mi aseguradora. */
    private function consulta(string $modulo, int $aseguradora): Builder
    {
        $query = self::MODULOS[$modulo]['modelo']::onlyTrashed();

        $deMisClientes = Cliente::withTrashed()->where('id_aseguradora', $aseguradora)->select('id');
        $deMisSiniestros = Siniestro::withTrashed()->where('id_aseguradora', $aseguradora)->select('id');

        return match ($modulo) {
            'polizas' => $query->where('id_aseguradora', $aseguradora)
                ->with(['cliente' => fn ($q) => $q->withTrashed()]),

            // bienes / indemnizaciones / evidencias no traen id_aseguradora confiable:
            // se filtran por su cliente o por su siniestro
            'bienes' => $query->whereIn('id_cliente', $deMisClientes)
                ->select(['id', 'id_cliente', 'id_poliza', 'tipo', 'descripcion', 'marca', 'modelo', 'deleted_at', 'deleted_by'])
                ->with(['cliente' => fn ($q) => $q->withTrashed()]),

            'indemnizaciones' => $query->whereIn('id_siniestro', $deMisSiniestros),

            'evidencias' => $query->whereIn('id_siniestro', $deMisSiniestros)
                ->with(['siniestro' => fn ($q) => $q->withTrashed()]),

            default => $query->where('id_aseguradora', $aseguradora),
        };
    }

    private function encontrar(Request $request, string $modulo, $id)
    {
        $this->soloAdmin($request);
        $this->validarModulo($modulo);

        return $this->consulta($modulo, $this->aseguradora($request))->whereKey($id)->firstOrFail();
    }

    /** Arma la fila genérica que pinta el front. */
    private function fila(string $modulo, $m, array $usuarios): array
    {
        $nombre = fn ($c) => $c ? trim($c->nombre . ' ' . $c->apellidos) : '—';

        [$titulo, $subtitulo, $detalle] = match ($modulo) {
            'clientes' => [
                $nombre($m),
                'RFC ' . $m->rfc,
                $m->correo,
            ],
            'polizas' => [
                $m->numero_poliza,
                Str::headline((string) $m->tipo),
                'Cliente: ' . $nombre($m->cliente),
            ],
            'reportes' => [
                'REP-' . str_pad($m->id, 6, '0', STR_PAD_LEFT),
                $m->nombre_reportante ?: 'Sin nombre',
                Str::limit((string) $m->descripcion, 80),
            ],
            'siniestros' => [
                $m->numero_siniestro,
                Str::headline((string) $m->tipo),
                'Monto reclamado $' . number_format((float) $m->monto_reclamado, 2),
            ],
            'indemnizaciones' => [
                'Indemnización #' . $m->id,
                '$' . number_format((float) $m->monto_aprobado, 2),
                Str::headline((string) $m->estatus),
            ],
            'evidencias' => [
                $m->nombre_archivo,
                Str::headline((string) $m->tipo),
                'Siniestro: ' . ($m->siniestro->numero_siniestro ?? $m->id_siniestro),
            ],
            'bienes' => [
                $m->descripcion ?: trim(Str::headline((string) $m->tipo) . ' ' . $m->marca),
                Str::headline((string) $m->tipo),
                'Cliente: ' . $nombre($m->cliente),
            ],
            'coberturas' => [
                $m->nombre,
                Str::headline((string) $m->estatus),
                Str::limit((string) $m->descripcion, 80),
            ],
        };

        return [
            'id'            => $m->id,
            'titulo'        => $titulo,
            'subtitulo'     => $subtitulo,
            'detalle'       => $detalle,
            'eliminado_por' => $usuarios[$m->deleted_by] ?? '—',
            'eliminado_el'  => $m->deleted_at ? $m->deleted_at->format('d-m-Y H:i') : '—',
        ];
    }
}
