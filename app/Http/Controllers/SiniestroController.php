<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\Empleado;
use App\Models\Poliza;
use App\Models\Reportes;
use App\Models\Siniestro;
use App\Models\SiniestroHistorial;
use App\Models\Evaluacion;
use App\Models\Indemnizaciones;
use App\Services\DocumentosSiniestroService;
use App\Services\NotificacionSiniestroService;
use App\Services\SiniestroEstadoService;
use App\Services\SiniestroNumeroService;
use App\Services\ValidacionPolizaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SiniestroController extends Controller
{
    private const TIPOS = ['robo', 'accidente', 'incendio', 'inundacion', 'responsabilidad_civil', 'fallecimiento', 'invalidez', 'otro'];
    private const PRIORIDADES = ['baja', 'media', 'alta', 'urgente'];

    /** Con el siniestro en estos estatus ya no se editan sus datos. */
    private const NO_EDITABLES = ['autorizado', 'en_pago', 'pagado', 'cerrado', 'cancelado'];

    /** Estatus desde los que se puede registrar el arribo del ajustador. */
    private const ESTATUS_ARRIBO = ['abierto', 'documentacion_pendiente', 'en_evaluacion', 'reabierto'];

    /** Con el siniestro en estos estatus ya no se puede reasignar. */
    private const NO_REASIGNABLES = ['pagado', 'cerrado', 'cancelado'];

    // ─────────────────────────── Listado y detalle ───────────────────────────

    public function index(Request $request)
    {
        $user    = $request->user();
        $perPage = min(max((int) $request->get('per_page', 5), 1), 100);
        $search  = $request->get('search', '');
        $estatus = $request->get('estatus', 'Todos');

        // "Mis casos": sin siniestros.ver_todos solo aparecen los del empleado que inició sesión
        $query = Siniestro::visiblePara($user)
            ->select(
                'siniestros.*',
                'reportes.id_poliza',
                'clientes.nombre',
                'clientes.apellidos',
                'clientes.id as cliente_id',
                'aj.nombre as ajustador_nombre',
                'aj.apellidos as ajustador_apellidos'
            )
            ->leftJoin('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->leftJoin('polizas', 'reportes.id_poliza', '=', 'polizas.id')
            ->leftJoin('clientes', 'polizas.id_cliente', '=', 'clientes.id')
            ->leftJoin('empleados as aj', 'siniestros.id_empleado', '=', 'aj.id');

        if (!empty($search)) {
            $query->where(function ($q) use ($search) {
                $q->where('siniestros.numero_siniestro', 'ILIKE', "%{$search}%")
                  ->orWhere('clientes.nombre', 'ILIKE', "%{$search}%")
                  ->orWhere('clientes.apellidos', 'ILIKE', "%{$search}%")
                  ->orWhere('siniestros.tipo', 'ILIKE', "%{$search}%");
            });
        }

        if ($estatus !== 'Todos') {
            $query->where('siniestros.estatus', $estatus);
        }

        $siniestros = $query->orderByDesc('siniestros.id')->paginate($perPage);

        $data = $siniestros->getCollection()->map(fn ($s) => [
            'id'              => $s->id,
            'nSiniestro'      => $s->numero_siniestro,
            'tipo'            => $this->traducirTipo($s->tipo),
            'cliente'         => $s->nombre ? $s->nombre . ' ' . $s->apellidos : 'Sin cliente',
            'estatus'         => SiniestroEstadoService::etiqueta($s->estatus),
            'estatus_valor'   => $s->estatus,
            'prioridad'       => $this->traducirPrioridad($s->prioridad),
            'monto_reclamado' => $s->monto_reclamado,
            'fecha_creacion'  => $s->fecha_creacion ? $s->fecha_creacion->format('d-m-Y') : '',
            'id_cliente'      => $s->cliente_id,
            'id_poliza'       => $s->id_poliza,
            'id_reporte'      => $s->id_reporte,
            'id_empleado'     => $s->id_empleado,
            'ajustador'       => $s->ajustador_nombre ? trim($s->ajustador_nombre . ' ' . $s->ajustador_apellidos) : 'Sin asignar',
        ])->values();

        return response()->json([
            'data'         => $data,
            'current_page' => $siniestros->currentPage(),
            'last_page'    => $siniestros->lastPage(),
            'per_page'     => $siniestros->perPage(),
            'total'        => $siniestros->total(),
        ]);
    }

    public function getDetalleCompleto(Request $request, $id)
    {
        $user = $request->user();

        $siniestro = Siniestro::visiblePara($user)->where('siniestros.id', $id)->firstOrFail();

        $reporte = $cliente = $poliza = null;
        $ajustador = $siniestro->empleado;

        if ($siniestro->id_reporte) {
            $reporte = Reportes::find($siniestro->id_reporte);
            if ($reporte && $reporte->id_poliza) {
                $poliza = Poliza::where('id', $reporte->id_poliza)
                    ->where('id_aseguradora', $user->id_aseguradora)->first();
                if ($poliza && $poliza->id_cliente) {
                    $cliente = Cliente::where('id', $poliza->id_cliente)
                        ->where('id_aseguradora', $user->id_aseguradora)->first();
                }
            }
        }

        $tiempoRespuesta = ($siniestro->fecha_arribo && $siniestro->fecha_creacion)
            ? intdiv($siniestro->fecha_arribo->timestamp - $siniestro->fecha_creacion->timestamp, 60)
            : null;

        return response()->json([
            'id'                   => $siniestro->id,
            'numero_siniestro'     => $siniestro->numero_siniestro,
            'tipo'                 => $this->traducirTipo($siniestro->tipo),
            'tipo_raw'             => $siniestro->tipo,
            'estatus'              => SiniestroEstadoService::etiqueta($siniestro->estatus),
            'estatus_valor'        => $siniestro->estatus,
            'transiciones'         => $this->transicionesManuales($siniestro->estatus),
            'prioridad'            => $this->traducirPrioridad($siniestro->prioridad),
            'prioridad_raw'        => $siniestro->prioridad,
            'monto_reclamado'      => $siniestro->monto_reclamado,
            'deducible_aplicado'   => $siniestro->deducible_aplicado,
            'ubicacion_siniestro'  => $siniestro->ubicacion_siniestro,
            'coordenadas'          => $siniestro->coordenadas,
            'fecha_arribo'         => $siniestro->fecha_arribo ? $siniestro->fecha_arribo->format('d-m-Y H:i') : null,
            'tiempo_respuesta_min' => $tiempoRespuesta,
            'puede_registrar_arribo' => $siniestro->fecha_arribo === null && in_array($siniestro->estatus, self::ESTATUS_ARRIBO, true),
            'fecha_creacion'       => $siniestro->fecha_creacion ? $siniestro->fecha_creacion->format('d-m-Y H:i') : '',
            'fecha_cierre'         => $siniestro->fecha_cierre ? $siniestro->fecha_cierre->format('d-m-Y H:i') : '',
            'motivo_cierre'        => $siniestro->motivo_cierre,
            'cliente'              => $cliente ? $cliente->nombre . ' ' . $cliente->apellidos : 'Sin cliente',
            'cliente_id'           => $cliente?->id,
            'poliza'               => $poliza ? $poliza->numero_poliza : 'Sin póliza',
            'poliza_id'            => $poliza?->id,
            'poliza_tipo'          => $poliza?->tipo,
            'id_reporte'           => $siniestro->id_reporte,
            'id_empleado'          => $siniestro->id_empleado,
            'ajustador'            => $ajustador ? $ajustador->nombre_completo : 'Sin asignar',
            'historial'            => $this->historialDe($siniestro->id),
            'documentos'           => app(DocumentosSiniestroService::class)->resumen($siniestro),
            'evaluacion'           => $this->resumenEvaluacion($siniestro->id),
            'indemnizacion'        => $this->resumenIndemnizacion($siniestro->id),
        ]);
    }

    /** Pasos que una persona puede dar desde el estatus actual. */
    private function transicionesManuales(string $estatus): array
    {
        return array_values(array_filter(
            array_intersect(SiniestroEstadoService::permitidas($estatus), SiniestroEstadoService::MANUALES),
            fn ($destino) => $destino !== 'en_evaluacion' || $estatus === 'rechazado'
        ));
    }

    private function resumenIndemnizacion(int $idSiniestro): ?array
    {
        $i = Indemnizaciones::where('id_siniestro', $idSiniestro)->where('estatus', '!=', 'rechazada')->orderByDesc('id')->first();

        return $i ? [
            'id'              => $i->id,
            'estatus'         => $i->estatus,
            'monto_aprobado'  => $i->monto_aprobado,
            'forma_pago'      => $i->forma_pago,
            'fecha_pago'      => $i->fecha_pago?->format('d-m-Y'),
            'referencia_pago' => $i->referencia_pago,
        ] : null;
    }

    private function resumenEvaluacion(int $idSiniestro): ?array
    {
        $e = Evaluacion::where('id_siniestro', $idSiniestro)->orderByDesc('id')->first();

        return $e ? [
            'id'                 => $e->id,
            'estatus'            => $e->estatus,
            'recomendacion'      => $e->recomendacion,
            'monto_recomendado'  => $e->monto_recomendado,
            'nivel_autorizacion' => $e->nivel_autorizacion,
        ] : null;
    }

    private function historialDe(int $idSiniestro): array
    {
        return SiniestroHistorial::where('siniestro_historial.id_siniestro', $idSiniestro)
            ->leftJoin('usuarios as u', 'siniestro_historial.id_usuario', '=', 'u.id')
            ->leftJoin('empleados as e', 'e.id_usuario', '=', 'u.id')
            ->select('siniestro_historial.*', 'u.correo', 'e.nombre as emp_nombre', 'e.apellidos as emp_apellidos')
            ->orderBy('siniestro_historial.id')
            ->get()
            ->map(fn ($h) => [
                'de'       => $h->estado_anterior ? SiniestroEstadoService::etiqueta($h->estado_anterior) : null,
                'a'        => SiniestroEstadoService::etiqueta($h->estado_nuevo),
                'motivo'   => $h->motivo,
                'por'      => $h->id_usuario
                    ? ($h->emp_nombre ? trim($h->emp_nombre . ' ' . $h->emp_apellidos) : $h->correo)
                    : 'Sistema',
                'fecha'    => $h->created_at?->format('d-m-Y H:i'),
                'evento'   => $h->metadata['evento'] ?? 'cambio_estatus',
                'metadata' => $h->metadata,
            ])->all();
    }

    // ─────────────────────────── Búsquedas para el formulario ───────────────────────────

    public function getPolizasPorRfc(Request $request)
    {
        $user = $request->user();
        $request->validate(['rfc' => 'required|string|max:13']);

        $cliente = Cliente::where('rfc', $request->rfc)
            ->where('id_aseguradora', $user->id_aseguradora)->first();

        if (!$cliente) {
            return response()->json(['message' => 'Cliente no encontrado'], 404);
        }

        $polizas = Poliza::where('id_cliente', $cliente->id)
            ->where('id_aseguradora', $user->id_aseguradora)
            ->select('id', 'numero_poliza', 'tipo')->get();

        return response()->json([
            'cliente' => ['id' => $cliente->id, 'nombre' => $cliente->nombre, 'apellido' => $cliente->apellidos],
            'polizas' => $polizas,
        ]);
    }

    /** Todos los reportes de la póliza, con su estado y si ya tienen siniestro. */
    public function getReportesPorPoliza(Request $request, $polizaId)
    {
        $user = $request->user();

        $poliza = Poliza::where('id', $polizaId)
            ->where('id_aseguradora', $user->id_aseguradora)->firstOrFail();

        $reportes = Reportes::where('id_poliza', $poliza->id)
            ->where('id_aseguradora', $user->id_aseguradora)
            ->withExists('siniestro as tiene_siniestro')
            ->orderByDesc('id')
            ->get();

        return response()->json($reportes->map(fn ($r) => [
            'id'              => $r->id,
            'numero_reporte'  => 'REP-' . str_pad($r->id, 6, '0', STR_PAD_LEFT),
            'fecha_reporte'   => $r->fecha_reporte?->format('d-m-Y'),
            'descripcion'     => $r->descripcion,
            'estado'          => $r->estado,
            'id_poliza'       => $r->id_poliza,
            'tiene_siniestro' => (bool) $r->tiene_siniestro,
            'elegible'        => $r->estado === 'validado' && !$r->tiene_siniestro,
        ]));
    }

    /** Reportes del cliente que ya se pueden convertir en siniestro (validados y sin siniestro). */
    public function getReportesPorRfc(Request $request)
    {
        $user = $request->user();
        $request->validate(['rfc' => 'required|string|max:13']);

        $cliente = Cliente::where('rfc', $request->rfc)
            ->where('id_aseguradora', $user->id_aseguradora)->first();

        if (!$cliente) {
            return response()->json(['message' => 'Cliente no encontrado'], 404);
        }

        $datosCliente = ['id' => $cliente->id, 'nombre' => $cliente->nombre, 'apellido' => $cliente->apellidos];

        $polizas = Poliza::where('id_cliente', $cliente->id)
            ->where('id_aseguradora', $user->id_aseguradora)->get();

        if ($polizas->isEmpty()) {
            return response()->json(['cliente' => $datosCliente, 'polizas' => [], 'reportes' => []]);
        }

        $reportes = Reportes::whereIn('id_poliza', $polizas->pluck('id'))
            ->where('id_aseguradora', $user->id_aseguradora)
            ->where('estado', 'validado')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($r) => [
                'id'             => $r->id,
                'numero_reporte' => 'REP-' . str_pad($r->id, 6, '0', STR_PAD_LEFT),
                'fecha_reporte'  => $r->fecha_reporte?->format('d-m-Y'),
                'descripcion'    => $r->descripcion,
                'estado'         => $r->estado,
                'id_poliza'      => $r->id_poliza,
                'numero_poliza'  => $polizas->firstWhere('id', $r->id_poliza)?->numero_poliza ?? 'N/A',
            ]);

        return response()->json(['cliente' => $datosCliente, 'polizas' => $polizas, 'reportes' => $reportes]);
    }

    /** Datos para abrir el formulario de siniestro desde un reporte (botón "Agregar siniestro"). */
    public function getReportePorId(Request $request, $reporteId)
    {
        $user = $request->user();

        $reporte = Reportes::where('id', $reporteId)
            ->where('id_aseguradora', $user->id_aseguradora)->firstOrFail();

        if ($reporte->estado === 'convertido') {
            return response()->json(['message' => 'Este reporte ya tiene un siniestro.'], 422);
        }
        if ($reporte->estado !== 'validado') {
            return response()->json([
                'message' => 'Primero valida el reporte (estado actual: ' . $reporte->estado . ').',
            ], 422);
        }

        $poliza = Poliza::where('id', $reporte->id_poliza)
            ->where('id_aseguradora', $user->id_aseguradora)->firstOrFail();

        $cliente = Cliente::where('id', $poliza->id_cliente)
            ->where('id_aseguradora', $user->id_aseguradora)->firstOrFail();

        return response()->json([
            'cliente' => [
                'id' => $cliente->id, 'rfc' => $cliente->rfc,
                'nombre' => $cliente->nombre, 'apellido' => $cliente->apellidos,
            ],
            'polizas' => [[
                'id' => $poliza->id, 'numero_poliza' => $poliza->numero_poliza, 'tipo' => $poliza->tipo,
            ]],
            'reportes' => [[
                'id'             => $reporte->id,
                'numero_reporte' => 'REP-' . str_pad($reporte->id, 6, '0', STR_PAD_LEFT),
                'id_poliza'      => $poliza->id,
                'numero_poliza'  => $poliza->numero_poliza,
                'fecha_reporte'  => $reporte->fecha_reporte,
                'descripcion'    => $reporte->descripcion,
            ]],
            'reporte_actual' => [
                'id' => $reporte->id, 'id_poliza' => $poliza->id, 'numero_poliza' => $poliza->numero_poliza,
            ],
        ]);
    }

    // ─────────────────────────── Alta, edición y baja ───────────────────────────

    public function store(
        Request $request,
        SiniestroEstadoService $estados,
        SiniestroNumeroService $numeros,
        ValidacionPolizaService $validacion,
        DocumentosSiniestroService $docs,
        NotificacionSiniestroService $notificaciones
    ) {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'id_reporte'          => ['required', 'integer'],
            'id_empleado'         => ['nullable', 'integer'],
            'tipo'                => ['required', Rule::in(self::TIPOS)],
            'prioridad'           => ['required', Rule::in(self::PRIORIDADES)],
            'monto_reclamado'     => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'deducible_aplicado'  => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'ubicacion_siniestro' => ['nullable', 'string', 'max:255'],
            'coordenadas'         => ['nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        [$siniestro, $analisis] = DB::transaction(function () use ($request, $user, $estados, $numeros, $validacion, $docs) {
            // Bloquea el reporte: dos personas no pueden convertirlo a la vez
            $reporte = Reportes::where('id', $request->id_reporte)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->lockForUpdate()
                ->first();

            abort_if(!$reporte, 404, 'Reporte no encontrado.');
            abort_if($reporte->estado === 'convertido' || Siniestro::where('id_reporte', $reporte->id)->exists(),
                422, 'Este reporte ya tiene un siniestro asociado.');
            abort_if($reporte->estado !== 'validado',
                422, 'El reporte debe estar validado antes de abrir el siniestro (estado actual: ' . $reporte->estado . ').');

            abort_if(
                !Poliza::where('id', $reporte->id_poliza)->where('id_aseguradora', $user->id_aseguradora)->exists(),
                422, 'La póliza del reporte no pertenece a tu aseguradora.'
            );

            // Vigencia, cobertura para este tipo de siniestro, plazo de aviso y señales de riesgo
            $analisis = $validacion->evaluar($reporte, $request->tipo, (int) $user->id_aseguradora);
            abort_if(!empty($analisis['bloqueos']), 422, implode(' ', $analisis['bloqueos']));

            // El número se pide antes de elegir ajustador: su bloqueo también serializa la
            // asignación y evita que dos siniestros simultáneos caigan en el mismo ajustador.
            $numero = $numeros->siguiente((int) $user->id_aseguradora);

            // Con id_empleado se usa ese ajustador; vacío o nulo = auto-asignación (menos cargado)
            $manual = $request->filled('id_empleado');

            if ($manual) {
                $ajustador = $this->ajustadorValido((int) $request->id_empleado, (int) $user->id_aseguradora);
                abort_if(!$ajustador, 422, 'El ajustador seleccionado no está activo o no pertenece a tu aseguradora.');
            } else {
                $ajustador = Empleado::ajustadorMenosCargado((int) $user->id_aseguradora)
                    ?? $user->empleado;
                abort_if(!$ajustador, 422, 'No hay ajustadores activos. Registra o reactiva a un ajustador para poder abrir siniestros.');
            }

            $siniestro = Siniestro::create([
                'id_aseguradora'      => $user->id_aseguradora,
                'id_reporte'          => $reporte->id,
                'id_empleado'         => $ajustador->id,
                'numero_siniestro'    => $numero,
                'tipo'                => $request->tipo,
                'estatus'             => 'abierto',
                'prioridad'           => $request->prioridad,
                'monto_reclamado'     => $request->monto_reclamado,
                'deducible_aplicado'  => $request->deducible_aplicado,
                'ubicacion_siniestro' => $request->ubicacion_siniestro,
                'coordenadas'         => $request->coordenadas,
                'fecha_creacion'      => now(),
                'fecha_actualizacion' => now(),
            ]);

            $reporte->update(['estado' => 'convertido', 'fecha_actualizacion' => now()]);

            $estados->registrarCreacion($siniestro, $user->id, 'Siniestro abierto', [
                'advertencias' => $analisis['advertencias'],
                'ajustador'    => [
                    'id'         => $ajustador->id,
                    'nombre'     => $ajustador->nombre_completo,
                    'asignacion' => $manual ? 'manual' : 'automatica',
                ],
            ]);

            // Checklist de documentos; si hay obligatorios pasa a "documentación pendiente",
            // si no, directo a "en evaluación". El correo es uno solo (apertura).
            $docs->generarChecklist($siniestro);
            $docs->sincronizar($siniestro->id, $user->id, false);

            return [$siniestro->fresh(), $analisis];
        });

        // Fuera de la transacción: si algo falló arriba, no sale ningún correo
        $notificaciones->programar($siniestro->id, 'apertura');

        return response()->json([
            'message'      => 'Siniestro creado exitosamente',
            'siniestro'    => $siniestro,
            'advertencias' => $analisis['advertencias'],
        ], 201);
    }

    /** Edita datos del siniestro. El estatus NO se cambia aquí (ver cambiarEstatus) ni el ajustador (ver asignarAjustador). */
    public function update(Request $request, $id)
    {
        $siniestro = Siniestro::visiblePara($request->user())->where('siniestros.id', $id)->firstOrFail();

        if (in_array($siniestro->estatus, self::NO_EDITABLES, true)) {
            return response()->json([
                'message' => 'Con el siniestro en estatus "' . SiniestroEstadoService::etiqueta($siniestro->estatus) . '" ya no se pueden editar sus datos.',
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'tipo'                => ['sometimes', Rule::in(self::TIPOS)],
            'prioridad'           => ['sometimes', Rule::in(self::PRIORIDADES)],
            'monto_reclamado'     => ['sometimes', 'numeric', 'min:0', 'max:9999999999.99'],
            'deducible_aplicado'  => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'ubicacion_siniestro' => ['sometimes', 'nullable', 'string', 'max:255'],
            'coordenadas'         => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $siniestro->update($validator->validated() + ['fecha_actualizacion' => now()]);

        return response()->json(['message' => 'Siniestro actualizado exitosamente', 'siniestro' => $siniestro->fresh()]);
    }

    /** POST /siniestros/{id}/estatus  { estatus, motivo } — solo transiciones manuales. */
    public function cambiarEstatus(
        Request $request,
        $id,
        SiniestroEstadoService $estados,
        DocumentosSiniestroService $docs,
        NotificacionSiniestroService $notificaciones
    ) {
        $user = $request->user();

        $data = $request->validate([
            'estatus' => ['required', Rule::in(SiniestroEstadoService::MANUALES)],
            'motivo'  => ['nullable', 'string', 'max:255'],
        ]);

        $siniestro = Siniestro::visiblePara($user)->where('siniestros.id', $id)->firstOrFail();
        $desde     = $siniestro->estatus;

        if ($data['estatus'] === 'en_evaluacion' && $desde !== 'rechazado') {
            return response()->json([
                'message' => 'Solo se puede volver a evaluación desde "Rechazado" (reconsideración). En los demás casos el paso es automático cuando los documentos obligatorios están aprobados.',
            ], 422);
        }

        $actualizado = $estados->transicionar($siniestro->id, $data['estatus'], $user->id, $data['motivo'] ?? null);

        // Si se cancela el siniestro, la indemnización pendiente o autorizada se anula con él
        if ($actualizado->estatus === 'cancelado') {
            Indemnizaciones::where('id_siniestro', $actualizado->id)->whereIn('estatus', ['pendiente', 'autorizada'])->update([
                'estatus'            => 'rechazada',
                'motivo_cancelacion' => 'Siniestro cancelado: ' . ($data['motivo'] ?? ''),
            ]);
        }

        // Reconsideración: el dictamen anterior queda sustituido para poder hacer uno nuevo
        if ($desde === 'rechazado' && $actualizado->estatus === 'en_evaluacion') {
            Evaluacion::where('id_siniestro', $actualizado->id)->where('estatus', 'aprobada')->update([
                'estatus'           => 'rechazada',
                'motivo_resolucion' => 'Reconsiderado: ' . ($data['motivo'] ?? ''),
            ]);
        }

        // Al reconsiderar o reabrir, se revisan de nuevo los documentos
        if (in_array($actualizado->estatus, ['en_evaluacion', 'reabierto'], true)) {
            $docs->sincronizar($actualizado->id, $user->id, false);
            $actualizado = $actualizado->fresh();
        }

        if ($actualizado->estatus === 'rechazado') {
            $notificaciones->programar($actualizado->id, 'siniestro_rechazado', ['motivo' => $data['motivo'] ?? null]);
        }

        return response()->json([
            'message'       => 'Estatus actualizado',
            'estatus'       => SiniestroEstadoService::etiqueta($actualizado->estatus),
            'estatus_valor' => $actualizado->estatus,
        ]);
    }

    /** Solo se elimina un siniestro recién abierto y sin nada colgando; si no, se cancela. */
    public function destroy(Request $request, $id)
    {
        $siniestro = Siniestro::visiblePara($request->user())->where('siniestros.id', $id)->firstOrFail();

        $tieneHistoria = $siniestro->evaluaciones()->exists()
            || $siniestro->indemnizaciones()->exists()
            || $siniestro->evidencias()->exists();

        if ($siniestro->estatus !== 'abierto' || $tieneHistoria) {
            return response()->json([
                'message' => 'Este siniestro ya tiene trámite y no se puede eliminar. Cancélalo indicando el motivo.',
            ], 422);
        }

        DB::transaction(function () use ($siniestro) {
            Reportes::where('id', $siniestro->id_reporte)->update(['estado' => 'validado', 'fecha_actualizacion' => now()]);
            $siniestro->delete(); // la bitácora se borra en cascada
        });

        return response()->json(['message' => 'Siniestro eliminado exitosamente']);
    }

    // ─────────────────────────── Asignación y SLA ───────────────────────────

    /** Ajustador activo de ESTA aseguradora (mismo criterio que la auto-asignación). */
    private function ajustadorValido(int $idEmpleado, int $idAseguradora): ?Empleado
    {
        return Empleado::ajustadoresActivos($idAseguradora)->where('id', $idEmpleado)->first();
    }

    /** GET /siniestros/ajustadores — para los selects del formulario y del modal de reasignación. */
    public function getAjustadores(Request $request)
    {
        $idAseg = (int) $request->user()->id_aseguradora;

        $cargas = Siniestro::where('id_aseguradora', $idAseg)
            ->whereNotIn('estatus', Siniestro::ESTATUS_SIN_CARGA)
            ->selectRaw('id_empleado, COUNT(*) AS total')
            ->groupBy('id_empleado')
            ->pluck('total', 'id_empleado');

        return response()->json(
            Empleado::ajustadoresActivos($idAseg)
                ->orderBy('nombre')->get()
                ->map(fn ($e) => [
                    'id'     => $e->id,
                    'nombre' => $e->nombre_completo,
                    'carga'  => (int) ($cargas[$e->id] ?? 0),
                ])->values()
        );
    }

    /** POST /siniestros/{id}/ajustador  { id_empleado, motivo } */
    public function asignarAjustador(Request $request, $id)
    {
        $user = $request->user();

        abort_if(!$user->tienePermiso('siniestros.reasignar'), 403, 'No tienes permiso para asignar ajustadores.');

        $data = $request->validate([
            'id_empleado' => ['required', 'integer'],
            'motivo'      => ['required', 'string', 'min:5', 'max:255'],
        ]);

        $visible = Siniestro::visiblePara($user)->where('siniestros.id', $id)->firstOrFail();

        $nuevo = $this->ajustadorValido((int) $data['id_empleado'], (int) $user->id_aseguradora);
        abort_if(!$nuevo, 422, 'El ajustador seleccionado no está activo o no pertenece a tu aseguradora.');

        $siniestro = DB::transaction(function () use ($visible, $nuevo, $data, $user) {
            // Bloqueo: dos reasignaciones simultáneas no pueden pisarse ni falsear "ajustador_anterior"
            $s = Siniestro::whereKey($visible->id)->lockForUpdate()->firstOrFail();

            abort_if(in_array($s->estatus, self::NO_REASIGNABLES, true), 422,
                'Con el siniestro en estatus "' . SiniestroEstadoService::etiqueta($s->estatus) . '" ya no se puede reasignar.');
            abort_if((int) $s->id_empleado === (int) $nuevo->id, 422, 'Ese ajustador ya tiene asignado el siniestro.');

            $anterior = $s->id_empleado ? Empleado::find($s->id_empleado) : null;

            $s->update(['id_empleado' => $nuevo->id, 'fecha_actualizacion' => now()]);

            SiniestroHistorial::create([
                'id_aseguradora'  => $s->id_aseguradora,
                'id_siniestro'    => $s->id,
                'estado_anterior' => $s->estatus, // el estatus no cambia; la bitácora exige ambos
                'estado_nuevo'    => $s->estatus,
                'id_usuario'      => $user->id,
                'motivo'          => $data['motivo'],
                'metadata'        => [
                    'evento'             => 'reasignacion',
                    'ajustador_anterior' => $anterior ? ['id' => $anterior->id, 'nombre' => $anterior->nombre_completo] : null,
                    'ajustador_nuevo'    => ['id' => $nuevo->id, 'nombre' => $nuevo->nombre_completo],
                    'motivo'             => $data['motivo'],
                ],
            ]);

            return $s->fresh();
        });

        return response()->json([
            'message'     => 'Ajustador asignado correctamente.',
            'id_empleado' => $siniestro->id_empleado,
            'ajustador'   => $nuevo->nombre_completo,
        ]);
    }

    /** POST /siniestros/{id}/arribo  { ubicacion_siniestro?, coordenadas? } */
    public function registrarArribo(Request $request, $id, SiniestroEstadoService $estados)
    {
        $user = $request->user();

        $data = $request->validate([
            'ubicacion_siniestro' => ['nullable', 'string', 'max:255'],
            'coordenadas'         => ['nullable', 'string', 'max:100'],
        ]);

        $visible = Siniestro::visiblePara($user)->where('siniestros.id', $id)->firstOrFail();

        $resultado = DB::transaction(function () use ($visible, $estados, $user, $data) {
            $s = Siniestro::whereKey($visible->id)->lockForUpdate()->firstOrFail();

            abort_if($s->fecha_arribo !== null, 422,
                'El arribo ya fue registrado el ' . $s->fecha_arribo->format('d-m-Y H:i') . '.');
            abort_if(!in_array($s->estatus, self::ESTATUS_ARRIBO, true), 422,
                'No se puede registrar el arribo con el siniestro en estatus "' . SiniestroEstadoService::etiqueta($s->estatus) . '".');

            $ahora   = now();
            $minutos = $s->fecha_creacion ? intdiv($ahora->timestamp - $s->fecha_creacion->timestamp, 60) : null;

            // Valida la transición, bloquea y escribe la bitácora (con metadata del evento)
            $estados->transicionar($s->id, 'en_proceso', $user->id, 'Arribo del ajustador al sitio', [
                'evento'               => 'arribo',
                'fecha_arribo'         => $ahora->toIso8601String(),
                'tiempo_respuesta_min' => $minutos,
            ]);

            $s->refresh();
            $s->update(array_filter($data, fn ($v) => $v !== null) + [
                'fecha_arribo'        => $ahora,
                'fecha_actualizacion' => $ahora,
            ]);

            return [$s->fresh(), $minutos];
        });

        return response()->json([
            'message'              => 'Arribo registrado.',
            'estatus_valor'        => $resultado[0]->estatus,
            'fecha_arribo'         => $resultado[0]->fecha_arribo->format('d-m-Y H:i'),
            'tiempo_respuesta_min' => $resultado[1],
        ]);
    }

    // ─────────────────────────── Etiquetas ───────────────────────────

    private function traducirTipo($tipo)
    {
        $map = [
            'robo' => 'Robo', 'accidente' => 'Accidente', 'incendio' => 'Incendio',
            'inundacion' => 'Inundación', 'responsabilidad_civil' => 'Responsabilidad Civil',
            'fallecimiento' => 'Fallecimiento', 'invalidez' => 'Invalidez', 'otro' => 'Otro',
        ];
        return $map[$tipo] ?? $tipo;
    }

    private function traducirPrioridad($prioridad)
    {
        $map = ['baja' => 'Baja', 'media' => 'Media', 'alta' => 'Alta', 'urgente' => 'Urgente'];
        return $map[$prioridad] ?? $prioridad;
    }
}