<?php

namespace App\Http\Controllers;

use App\Models\Poliza;
use App\Models\Reportes;
use App\Support\ReglasPapelera;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;


/**
 * Reporte = aviso del asegurado. Solo se captura y se valida.
 * Estados: nuevo -> validado -> convertido (lo cambia el siniestro) | descartado
 * El estado NO se edita a mano: se cambia con validar() y descartar().
 * Eliminar = mandar a la papelera (soft delete).
 */
class ReportesController extends Controller
{
    private const CANALES = ['telefono', 'email', 'presencial', 'app', 'whatsapp'];
    private const REPORTANTES = ['cliente', 'tercero', 'empleado', 'anonimo'];

    /** Columnas de la papelera: no viajan al front (si no, salen en el modal de detalle). */
    private const OCULTAS = ['deleted_at', 'deleted_by'];

    private function idAseg(Request $request): int
    {
        return (int) $request->user()->id_aseguradora;
    }

    /** Solo reportes de MI aseguradora y fuera de la papelera; si no, responde 404. */
    private function buscar(Request $request, $id): Reportes
    {
        return Reportes::where('id_aseguradora', $this->idAseg($request))
            ->whereNull('deleted_at')
            ->findOrFail($id);
    }

    private function consulta(int $idAseg)
    {
        return DB::table('reportes')
            ->leftJoin('siniestros', 'siniestros.id_reporte', '=', 'reportes.id')
            ->leftJoin('polizas', 'polizas.id', '=', 'reportes.id_poliza')
            ->where('reportes.id_aseguradora', $idAseg)
            ->whereNull('reportes.deleted_at')
            ->select(
                'reportes.*',
                'siniestros.id as id_siniestro',
                'siniestros.numero_siniestro',
                'polizas.numero_poliza as poliza_numero'
            );
    }

    private function reglas(int $idAseg, bool $parcial = false): array
    {
        $reglas = [
            'id_poliza'           => ['required', 'integer', Rule::exists('polizas', 'id')->where('id_aseguradora', $idAseg)],
            'reportado_por'       => ['required', Rule::in(self::REPORTANTES)],
            'nombre_reportante'   => ['nullable', 'string', 'max:150'],
            'telefono_reportante' => ['nullable', 'string', 'max:15'],
            'fecha_reporte'       => ['nullable', 'date'],
            'fecha_ocurrencia'    => ['required', 'date'],
            'canal_reporte'       => ['required', Rule::in(self::CANALES)],
            'ubicacion_accidente' => ['required', 'string', 'max:255'],
            'descripcion'         => ['required', 'string', 'max:500'],
            'observaciones'       => ['nullable', 'string', 'max:255'],
        ];

        return $parcial
            ? collect($reglas)->map(fn ($r) => array_merge(['sometimes'], $r))->all()
            : $reglas;
    }

    public function index(Request $request)
    {
        $reportes = $this->consulta($this->idAseg($request))
            ->orderByDesc('reportes.id')
            ->get()
            ->map(fn ($r) => Arr::except((array) $r, self::OCULTAS));

        return response()->json($reportes);
    }

    /**
     * GET /reportes/buscar-poliza?q=AUT-2026
     * Búsqueda en vivo para la cabina: por número de póliza, RFC o nombre del asegurado.
     * Solo pólizas de MI aseguradora. Devuelve el id interno + datos para confirmar a la persona.
     */
    public function buscarPoliza(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json([]);
        }

        // Escapa los comodines de LIKE para que "%" o "_" se busquen como texto
        $like = '%' . addcslashes($q, '%_\\') . '%';

        $polizas = DB::table('polizas')
            ->leftJoin('clientes', 'clientes.id', '=', 'polizas.id_cliente')
            ->where('polizas.id_aseguradora', $this->idAseg($request))
            ->where(function ($w) use ($like) {
                $w->where('polizas.numero_poliza', 'ilike', $like)
                  ->orWhere('clientes.rfc', 'ilike', $like)
                  ->orWhere('clientes.nombre', 'ilike', $like);
            })
            ->select(
                'polizas.id',
                'polizas.numero_poliza',
                'polizas.estatus',
                'clientes.nombre as cliente',
                'clientes.rfc'
            )
            ->orderBy('polizas.numero_poliza')
            ->limit(15)
            ->get();

        return response()->json($polizas);
    }

    public function show(Request $request, $id)
    {
        $reporte = $this->consulta($this->idAseg($request))->where('reportes.id', $id)->first();

        return $reporte
            ? response()->json(Arr::except((array) $reporte, self::OCULTAS))
            : response()->json(['message' => 'Reporte no encontrado'], 404);
    }

    public function store(Request $request)
    {
        $idAseg = $this->idAseg($request);

        $validator = Validator::make($request->all(), $this->reglas($idAseg));
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Solo lo validado: el cliente no puede forzar aseguradora, estado ni empleado
        $datos = $validator->validated();
        $datos['fecha_reporte']       = $datos['fecha_reporte'] ?? now();
        $datos['id_aseguradora']      = $idAseg;
        $datos['id_empleado']         = $request->user()->empleado?->id;
        $datos['estado']              = 'nuevo';
        $datos['fecha_actualizacion'] = now();

        $reporte = Reportes::create($datos);

        return response()->json(['id' => $reporte->id, 'message' => 'Reporte creado'], 201);
    }

    public function update(Request $request, $id)
    {
        $idAseg  = $this->idAseg($request);
        $reporte = $this->buscar($request, $id);

        if (!in_array($reporte->estado, ['nuevo', 'validado'], true)) {
            return response()->json([
                'message' => $reporte->estado === 'convertido'
                    ? 'Este reporte ya generó un siniestro y no se puede editar.'
                    : 'Un reporte descartado no se puede editar.',
            ], 422);
        }

        $validator = Validator::make($request->all(), $this->reglas($idAseg, true));
        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $datos = $validator->validated();
        $datos['fecha_actualizacion'] = now();

        // Si cambian datos del hecho o la póliza, hay que volver a validarlo
        $cambioRelevante = collect(['id_poliza', 'fecha_ocurrencia'])
            ->contains(fn ($c) => array_key_exists($c, $datos));
        if ($reporte->estado === 'validado' && $cambioRelevante) {
            $datos['estado'] = 'nuevo';
        }

        $reporte->update($datos);

        return response()->json(['message' => 'Reporte actualizado', 'estado' => $reporte->estado]);
    }

    /** nuevo -> validado, si la póliza respalda el hecho. */
    public function validar(Request $request, $id)
    {
        $reporte = $this->buscar($request, $id);

        if ($reporte->estado !== 'nuevo') {
            return response()->json(['message' => 'Solo se pueden validar reportes en estado "nuevo".'], 422);
        }

        $problemas = $this->problemasDePoliza($reporte);
        if ($problemas) {
            return response()->json([
                'message' => 'No se puede validar: ' . implode(' ', $problemas),
                'motivos' => $problemas,
            ], 422);
        }

        $reporte->update(['estado' => 'validado', 'fecha_actualizacion' => now()]);

        return response()->json(['message' => 'Reporte validado', 'estado' => 'validado']);
    }

    /** nuevo | validado -> descartado (motivo obligatorio, queda en observaciones). */
    public function descartar(Request $request, $id)
    {
        $reporte = $this->buscar($request, $id);

        $data = $request->validate(['motivo' => ['required', 'string', 'min:5', 'max:200']]);

        if (!in_array($reporte->estado, ['nuevo', 'validado'], true)) {
            return response()->json(['message' => 'Este reporte ya no se puede descartar.'], 422);
        }

        $reporte->update([
            'estado'              => 'descartado',
            'observaciones'       => Str::limit('Descartado: ' . trim($data['motivo']), 255, ''),
            'fecha_actualizacion' => now(),
        ]);

        return response()->json(['message' => 'Reporte descartado', 'estado' => 'descartado']);
    }

    /** DELETE /reportes/{id} → manda el reporte a la papelera. */
    public function destroy(Request $request, $id)
    {
        $reporte = $this->buscar($request, $id);

        if (!in_array($reporte->estado, ['nuevo', 'descartado'], true)) {
            return response()->json([
                'message' => 'Solo se pueden eliminar reportes nuevos o descartados. Si es válido, descártalo con un motivo.',
            ], 422);
        }

        if ($mensaje = ReglasPapelera::alEliminar('reportes', $reporte)) {
            return response()->json(['message' => $mensaje], 409);
        }

        $reporte->enviarAPapelera();

        return response()->json(['message' => 'Enviado a la papelera']);
    }

    /**
     * Revisión básica de la póliza al validar. La cobertura por tipo de siniestro y el
     * plazo de aviso se revisan al abrir el siniestro (fase 3).
     */
    private function problemasDePoliza(Reportes $reporte): array
    {
        $poliza = Poliza::where('id', $reporte->id_poliza)
            ->where('id_aseguradora', $reporte->id_aseguradora)
            ->first();

        if (!$poliza) {
            return ['La póliza del reporte no existe.'];
        }

        if (in_array($poliza->estatus, ['cancelada', 'suspendida'], true)) {
            return ["La póliza {$poliza->numero_poliza} está {$poliza->estatus}."];
        }

        $ocurrio = Carbon::parse($reporte->fecha_ocurrencia)->startOfDay();
        $inicio  = Carbon::parse($poliza->fecha_inicio)->startOfDay();
        $fin     = Carbon::parse($poliza->fecha_fin)->endOfDay();

        if ($ocurrio->lt($inicio) || $ocurrio->gt($fin)) {
            return [sprintf(
                'El hecho ocurrió el %s, fuera de la vigencia de la póliza (%s a %s).',
                $ocurrio->format('d/m/Y'), $inicio->format('d/m/Y'), $fin->format('d/m/Y')
            )];
        }

        return [];
    }
}