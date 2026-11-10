<?php
// app/Http/Controllers/PolizaController.php

namespace App\Http\Controllers;

use App\Models\Bienes;
use App\Models\Cliente;
use App\Models\Cobertura;
use App\Models\Empleado;
use App\Models\Poliza;
use App\Models\PolizaEndoso;
use App\Models\Reportes;
use App\Services\PolizaBitacoraService;
use App\Services\PolizaCoberturasService;
use App\Services\PolizaFinanzasService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\HttpException;

class PolizaController extends Controller
{
    /**
     * Datos que modifican el CONTRATO: una póliza emitida solo se cambia con un endoso.
     * (IVA y prima total no se capturan: se recalculan.)  El ramo (tipo) nunca cambia.
     */
    private const CAMPOS_CONTRACTUALES = [
        'suma_asegurada', 'fecha_inicio', 'fecha_fin', 'prima_neta', 'derecho_poliza', 'forma_pago',
    ];

    private const FORMAS_PAGO = ['contado', 'mensual', 'trimestral', 'semestral', 'anual'];

    /** Límite de los PDF en KB (2 MB, igual que en el resto del sistema). */
    private const MAX_PDF_KB = 2048;

    /** Un endoso puede tener efecto hacia atrás, pero no indefinidamente (ajústalo a la política de tu aseguradora). */
    private const MAX_RETROACTIVIDAD_DIAS = 30;

    /** Estatus en los que un bien se considera "ya asegurado" por esa póliza (si su vigencia no ha terminado). */
    private const ESTATUS_QUE_OCUPAN_BIEN = ['activa', 'suspendida', 'en_renovacion'];

    /** Ramo: letra con la que inicia el número de póliza (A-2026-001-000051). */
    private const LETRAS_TIPO = [
        'vida' => 'V',
        'gastos_medicos' => 'G',
        'autos' => 'A',
        'hogar' => 'H',
        'empresarial' => 'E',
        'responsabilidad_civil' => 'R',
        'otro' => 'O',
    ];

    /** Nombres legibles de los campos para los mensajes de validación (en vez de "prima neta field"). */
    private const ATRIBUTOS = [
        'id_cliente'         => 'cliente',
        'tipo'               => 'ramo',
        'fecha_inicio'       => 'fecha de inicio',
        'fecha_fin'          => 'fecha de fin',
        'fecha_efecto'       => 'fecha de efecto',
        'suma_asegurada'     => 'suma asegurada',
        'prima_neta'         => 'prima neta',
        'derecho_poliza'     => 'derecho de póliza',
        'forma_pago'         => 'forma de pago',
        'observaciones'      => 'observaciones',
        'coberturas_ids'     => 'coberturas',
        'descripcion_endoso' => 'descripción del endoso',
        'endoso_documento'   => 'documento del endoso',
        'documento'          => 'documento de la póliza',
    ];

    public function __construct(
        private PolizaFinanzasService $finanzas,
        private PolizaCoberturasService $coberturas,
        private PolizaBitacoraService $bitacora
    ) {
    }

    /**
     * Mensajes de validación en español. Los específicos (ej. 'documento.mimes') ganan sobre los genéricos.
     */
    private function mensajesValidacion(array $especificos): array
    {
        return $especificos + [
            'required' => 'Falta capturar la :attribute.',
            'numeric'  => 'La :attribute debe ser un número.',
            'integer'  => 'La :attribute no es válida.',
            'date'     => 'La :attribute no es una fecha válida.',
            'boolean'  => 'La :attribute no es válida.',
            'array'    => 'La :attribute no es válida.',
            'string'   => 'La :attribute no es válida.',
            'min'      => [
                'numeric' => 'La :attribute no puede ser negativa.',
            ],
            'max'      => [
                'numeric' => 'La :attribute es demasiado grande.',
                'string'  => 'La :attribute no debe pasar de :max caracteres.',
            ],
            'gt'       => [
                'numeric' => 'La :attribute debe ser mayor a 0.',
            ],
            'in'       => 'La :attribute seleccionada no es válida.',
            'exists'   => 'La :attribute no existe o no pertenece a tu aseguradora.',
            'after'    => 'La :attribute debe ser posterior a la fecha de inicio.',
        ];
    }

    /** Manejo común de errores: respeta los abort() (422, 403…) y los "no encontrado". */
    private function falla(\Throwable $e, string $contexto)
    {
        if ($e instanceof HttpException) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
        if ($e instanceof ModelNotFoundException) {
            return response()->json(['message' => 'Póliza no encontrada'], 404);
        }

        Log::error("Error en {$contexto}: " . $e->getMessage());
        return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
    }

    /** Una póliza "activa" cuya vigencia ya terminó está vencida aunque el proceso nocturno aún no la haya marcado. */
    private function estaVencida(Poliza $poliza): bool
    {
        return $poliza->estatus === 'vencida'
            || ($poliza->estatus === 'activa' && Carbon::parse($poliza->fecha_fin)->endOfDay()->isPast());
    }

    // ───────────────────────── Consulta ─────────────────────────

    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $perPage = $request->get('per_page', 5);
            $search = $request->get('search', '');
            $estatus = $request->get('estatus', 'Todos');

            $query = Poliza::select(
                    'polizas.*',
                    'clientes.nombre',
                    'clientes.apellidos',
                    'clientes.id as cliente_id',
                    'clientes.rfc as cliente_rfc',
                    'clientes.correo as cliente_correo',
                    'clientes.numero_de_telefono as cliente_telefono',
                    'clientes.domicilio as cliente_domicilio',
                    'clientes.curp as cliente_curp'
                )
                ->addSelect(DB::raw("(SELECT COUNT(*) FROM polizas_endosos e WHERE e.id_poliza = polizas.id) AS total_endosos"))
                ->addSelect(DB::raw("(SELECT COUNT(*) FROM polizas_x_cobertura pc WHERE pc.id_poliza = polizas.id) AS total_coberturas"))
                ->leftJoin('clientes', 'polizas.id_cliente', '=', 'clientes.id')
                ->where('polizas.id_aseguradora', $user->id_aseguradora);

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('polizas.numero_poliza', 'ILIKE', "%{$search}%")
                      ->orWhere('clientes.nombre', 'ILIKE', "%{$search}%")
                      ->orWhere('clientes.apellidos', 'ILIKE', "%{$search}%");
                });
            }

            if ($estatus !== 'Todos') {
                $query->where('polizas.estatus', $estatus);
            }

            $polizas = $query->orderBy('polizas.id', 'desc')->paginate($perPage);

            $data = [];
            foreach ($polizas as $poliza) {
                $tieneEndoso = (int) $poliza->total_endosos > 0;

                $data[] = [
                    'id' => $poliza->id,
                    'nPoliza' => $poliza->numero_poliza,
                    'cliente' => ($poliza->nombre ? $poliza->nombre . ' ' . $poliza->apellidos : 'Sin cliente'),
                    'id_cliente' => $poliza->cliente_id,
                    'fechaInicio' => $poliza->fecha_inicio,
                    'fechaFinal' => $poliza->fecha_fin,
                    'estatus' => $this->traducirEstatus($poliza->estatus),
                    'endoso' => $tieneEndoso ? 'Sí' : 'No',
                    'tiene_endoso' => $tieneEndoso,
                    'total_endosos' => (int) $poliza->total_endosos,
                    'total_coberturas' => (int) $poliza->total_coberturas,
                    'sumaAsegurada' => $poliza->suma_asegurada,
                    'formaPago' => $poliza->forma_pago,
                    'montoPrima' => $poliza->prima_total,
                    'observaciones' => $poliza->observaciones,
                    'tipo' => $poliza->tipo,
                    'prima_neta' => $poliza->prima_neta,
                    'derecho_poliza' => $poliza->derecho_poliza,
                    'iva' => $poliza->iva,
                    'prima_total' => $poliza->prima_total,
                    'cliente_rfc' => $poliza->cliente_rfc,
                    'cliente_correo' => $poliza->cliente_correo,
                    'cliente_telefono' => $poliza->cliente_telefono,
                    'cliente_domicilio' => $poliza->cliente_domicilio,
                    'cliente_curp' => $poliza->cliente_curp,
                ];
            }

            return response()->json([
                'data' => $data,
                'current_page' => $polizas->currentPage(),
                'last_page' => $polizas->lastPage(),
                'per_page' => $polizas->perPage(),
                'total' => $polizas->total(),
            ]);

        } catch (\Exception $e) {
            return $this->falla($e, 'index');
        }
    }

    /** GET /polizas/parametros — IVA y ramos exentos de MI aseguradora (el formulario los usa para mostrar el resumen). */
    public function parametros()
    {
        return response()->json($this->finanzas->parametros((int) auth()->user()->id_aseguradora));
    }

    public function buscarClientePorRfc(Request $request)
    {
        try {
            $request->validate(['rfc' => 'required|string|max:13']);
            $user = auth()->user();

            $cliente = Cliente::where('rfc', $request->rfc)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->first();

            if (!$cliente) {
                return response()->json(['message' => 'Cliente no encontrado'], 404);
            }

            return response()->json($this->datosCliente($cliente));
        } catch (\Exception $e) {
            Log::error('Error al buscar cliente: ' . $e->getMessage());
            return response()->json(['message' => 'Error al buscar cliente'], 500);
        }
    }

    /**
     * Busca al cliente por RFC. Solo devuelve los datos del cliente:
     * los datos de la póliza no se copian de la póliza anterior.
     */
    public function getUltimaPolizaPorRfc(Request $request)
    {
        try {
            $request->validate(['rfc' => 'required|string|max:13']);
            $user = auth()->user();

            $cliente = Cliente::where('rfc', $request->rfc)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->first();

            if (!$cliente) {
                return response()->json(['message' => 'Cliente no encontrado'], 404);
            }

            $tienePoliza = Poliza::where('id_cliente', $cliente->id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->exists();

            return response()->json([
                'cliente' => $this->datosCliente($cliente),
                'tienePoliza' => $tienePoliza,
            ]);

        } catch (\Exception $e) {
            Log::error('Error en getUltimaPolizaPorRfc: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    // ───────────────────────── Alta ─────────────────────────

    public function store(Request $request)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json(['message' => 'Usuario no autenticado'], 401);
            }

            [$reglasDocs, $mensajes] = $this->reglasDocumentos();

            // El N° de póliza, el IVA y la prima total NO se capturan: los calcula el sistema.
            $validator = Validator::make(
                $request->all(),
                array_merge([
                    'id_cliente' => ['required', 'integer', Rule::exists('clientes', 'id')->where('id_aseguradora', $user->id_aseguradora)],
                    'tipo' => ['required', 'string', Rule::in(array_keys(self::LETRAS_TIPO))],
                    'fecha_inicio' => 'required|date',
                    'fecha_fin' => 'required|date|after:fecha_inicio',
                    'suma_asegurada' => 'required|numeric|gt:0|max:9999999999.99',
                    'prima_neta' => 'required|numeric|min:0|max:9999999999.99',
                    'derecho_poliza' => 'required|numeric|min:0|max:9999999999.99',
                    'forma_pago' => ['required', Rule::in(self::FORMAS_PAGO)],
                    'observaciones' => 'nullable|string|max:255',
                    'coberturas_ids' => 'nullable|array',
                    'coberturas_ids.*' => 'integer',
                ], $reglasDocs),
                $this->mensajesValidacion($mensajes),
                self::ATRIBUTOS
            );

            if ($validator->fails()) {
                Log::error('Validación fallida:', $validator->errors()->toArray());
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $idEmpleado = $user->empleado?->id
                ?? Empleado::where('id_aseguradora', $user->id_aseguradora)->orderBy('id')->value('id');

            if (!$idEmpleado) {
                return response()->json(['message' => 'No hay empleados registrados. Por favor, registra un empleado primero.'], 422);
            }

            // Bienes y coberturas elegidos: se revisan aquí, no solo en la pantalla
            $bienesIds = (array) $request->get('bienes_ids', []);
            $precios = (array) $request->get('precios_bienes', []);

            // Un bien no se asegura dos veces al mismo tiempo (doble aseguramiento)
            $ocupados = $this->erroresBienesOcupados($bienesIds, null);
            if ($ocupados) {
                return response()->json(['message' => implode(' ', $ocupados), 'errors' => ['bienes' => $ocupados]], 422);
            }

            $bienesDeLaPoliza = $this->bienesValidos((int) $request->id_cliente, (int) $user->id_aseguradora, $bienesIds, $precios);
            $coberturasIds = array_values(array_unique(array_map('intval', (array) $request->get('coberturas_ids', []))));

            $errores = $this->erroresDeCoberturas(
                (int) $user->id_aseguradora, $request->tipo, $coberturasIds, $bienesDeLaPoliza, (float) $request->suma_asegurada
            );

            if ($errores) {
                return response()->json(['message' => implode(' ', $errores), 'errors' => ['coberturas' => $errores]], 422);
            }

            $fin = $this->finanzas->calcular($request->tipo, $request->prima_neta, $request->derecho_poliza, (int) $user->id_aseguradora);

            $poliza = DB::transaction(function () use ($request, $user, $idEmpleado, $fin, $bienesIds, $precios, $coberturasIds) {
                // Se asigna dentro de la transacción: si algo falla, el consecutivo no se gasta
                $numero = $this->generarNumeroPoliza($request->tipo, (int) $user->id_aseguradora);

                $poliza = Poliza::create(array_merge([
                    'id_aseguradora' => $user->id_aseguradora,
                    'id_cliente' => $request->id_cliente,
                    'id_empleado' => $idEmpleado,
                    'numero_poliza' => $numero,
                    'tipo' => $request->tipo,
                    'estatus' => 'activa',
                    'fecha_emision' => now()->format('Y-m-d'),
                    'fecha_inicio' => $request->fecha_inicio,
                    'fecha_fin' => $request->fecha_fin,
                    'suma_asegurada' => $request->suma_asegurada,
                    'prima_neta' => $request->prima_neta,
                    'derecho_poliza' => $request->derecho_poliza,
                    'iva' => $fin['iva'],
                    'prima_total' => $fin['prima_total'],
                    'forma_pago' => $request->forma_pago,
                    'observaciones' => $request->observaciones,
                ], $this->calendarioDePagos($request->forma_pago, $request->fecha_inicio)));

                $this->sincronizarBienes($poliza, $bienesIds, $precios);
                $this->coberturas->sincronizar($poliza, $coberturasIds);

                $this->bitacora->registrar(
                    $poliza->id,
                    (int) $poliza->id_aseguradora,
                    'creada',
                    "Póliza {$poliza->numero_poliza} emitida (ramo {$poliza->tipo}, prima total \${$fin['prima_total']})"
                );

                if ($request->hasFile('documento')) {
                    $this->adjuntarDocumento($poliza, $request->file('documento'));
                    $this->bitacora->registrar($poliza->id, (int) $poliza->id_aseguradora, 'documento', 'Se cargó el documento de la póliza');
                }

                if ($request->boolean('tiene_endoso')) {
                    $this->crearEndoso($poliza, $request);
                }

                return $poliza;
            });

            Log::info('Póliza creada ID: ' . $poliza->id . ' N°: ' . $poliza->numero_poliza);

            return response()->json(['message' => 'Póliza creada exitosamente', 'poliza' => $poliza], 201);

        } catch (\Exception $e) {
            return $this->falla($e, 'store');
        }
    }

    // ───────────────────────── Detalle ─────────────────────────

    public function show($id)
    {
        try {
            $user = auth()->user();

            $poliza = Poliza::with(['cliente', 'endosos'])
                ->where('id', $id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();

            $tieneEndoso = $poliza->endosos->count() > 0;
            $ultimoConsecutivo = (int) $poliza->endosos->max('consecutivo');

            $idsCob = $this->coberturas->idsAsignados($poliza->id);
            $coberturas = Cobertura::whereIn('id', $idsCob)->orderBy('nombre')->get()
                ->map(fn ($c) => $this->coberturas->ficha($c))->values();

            $fin = $this->finanzas->calcular($poliza->tipo, $poliza->prima_neta, $poliza->derecho_poliza, (int) $poliza->id_aseguradora);
            $plan = $this->finanzas->plan($poliza->forma_pago, (float) $poliza->prima_total, $poliza->fecha_inicio, $poliza->fecha_fin);

            // La tasa que se muestra es la que realmente se aplicó al emitir (si la aseguradora cambió su IVA después, no se reescribe el pasado)
            $subtotal = (float) $poliza->prima_neta + (float) $poliza->derecho_poliza;
            $tasaAplicada = ($subtotal > 0 && (float) $poliza->iva > 0)
                ? round((float) $poliza->iva / $subtotal * 100, 2)
                : $fin['tasa_iva'];

            $nombresEndosos = $this->bitacora->nombresPorUsuario(
                $poliza->endosos->pluck('id_usuario')->all(),
                (int) $poliza->id_aseguradora
            );

            return response()->json([
                'id' => $poliza->id,
                'nPoliza' => $poliza->numero_poliza,
                'cliente' => $poliza->cliente ? $poliza->cliente->nombre . ' ' . $poliza->cliente->apellidos : '',
                'id_cliente' => $poliza->id_cliente,
                'fechaInicio' => $poliza->fecha_inicio,
                'fechaFinal' => $poliza->fecha_fin,
                'estatus' => $this->traducirEstatus($this->estaVencida($poliza) ? 'vencida' : $poliza->estatus),
                'sumaAsegurada' => $poliza->suma_asegurada,
                'formaPago' => $poliza->forma_pago,
                'montoPrima' => $poliza->prima_total,
                'observaciones' => $poliza->observaciones,
                'tipo' => $poliza->tipo,
                'prima_neta' => $poliza->prima_neta,
                'derecho_poliza' => $poliza->derecho_poliza,
                'iva' => $poliza->iva,
                'prima_total' => $poliza->prima_total,
                'iva_exento' => $fin['iva_exento'],
                'tasa_iva' => $tasaAplicada,
                'numero_pagos' => $plan['numero_pagos'],
                'pago_periodico' => $plan['pago_periodico'],
                'coberturas' => $coberturas,
                'coberturas_ids' => $idsCob,
                'cliente_nombre' => $poliza->cliente ? $poliza->cliente->nombre : '',
                'cliente_apellido' => $poliza->cliente ? $poliza->cliente->apellidos : '',
                'cliente_rfc' => $poliza->cliente ? $poliza->cliente->rfc : '',
                'cliente_correo' => $poliza->cliente ? $poliza->cliente->correo : '',
                'cliente_telefono' => $poliza->cliente ? $poliza->cliente->numero_de_telefono : '',
                'cliente_domicilio' => $poliza->cliente ? $poliza->cliente->domicilio : '',
                'cliente_curp' => $poliza->cliente ? $poliza->cliente->curp : '',
                // Documento de la póliza
                'tiene_documento' => !empty($poliza->documento_ruta),
                'documento_nombre' => $poliza->documento_nombre,
                // Endosos
                'tiene_endoso' => $tieneEndoso,
                'endoso' => $tieneEndoso ? 'Sí' : 'No',
                'total_endosos' => $poliza->endosos->count(),
                // N° que recibirá el próximo endoso (solo informativo para el formulario)
                'siguiente_endoso' => $this->formarNumeroEndoso($poliza->numero_poliza, $ultimoConsecutivo + 1),
                'endosos' => $poliza->endosos->map(function ($e) use ($nombresEndosos) {
                    $cambios = $e->cambios;

                    return [
                        'id' => $e->id,
                        'numero_endoso' => $e->numero_endoso,
                        'fecha' => optional($e->created_at)->format('Y-m-d H:i'),
                        'fecha_efecto' => $e->fecha_efecto ? Carbon::parse($e->fecha_efecto)->format('Y-m-d') : null,
                        'descripcion' => $e->descripcion,
                        'usuario' => $nombresEndosos[$e->id_usuario] ?? null,
                        'tipo_endoso' => $e->tipo_endoso,
                        'ajuste_prima' => $e->ajuste_prima,
                        'cambios' => is_string($cambios) ? json_decode($cambios, true) : $cambios,
                        'tiene_documento' => !empty($e->documento_ruta),
                        'documento_nombre' => $e->documento_nombre,
                    ];
                })->values(),
                // Bitácora: quién hizo qué y cuándo
                'historial' => $this->bitacora->historial($poliza->id, (int) $poliza->id_aseguradora),
            ]);
        } catch (\Exception $e) {
            return $this->falla($e, 'show');
        }
    }

    // ───────────────────────── Edición (solo con endoso) ─────────────────────────

    public function update(Request $request, $id)
    {
        try {
            $user = auth()->user();

            [$reglasDocs, $mensajes] = $this->reglasDocumentos();
            $validator = Validator::make(
                $request->all(),
                $reglasDocs + [
                    'fecha_inicio' => 'sometimes|date',
                    'fecha_fin' => 'sometimes|date',
                    'suma_asegurada' => 'sometimes|numeric|gt:0|max:9999999999.99',
                    'prima_neta' => 'sometimes|numeric|min:0|max:9999999999.99',
                    'derecho_poliza' => 'sometimes|numeric|min:0|max:9999999999.99',
                    'forma_pago' => ['sometimes', Rule::in(self::FORMAS_PAGO)],
                    'observaciones' => 'nullable|string|max:255',
                    'coberturas_ids' => 'nullable|array',
                    'coberturas_ids.*' => 'integer',
                ],
                $this->mensajesValidacion($mensajes),
                self::ATRIBUTOS
            );

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $infoEndoso = DB::transaction(function () use ($request, $id, $user) {
                // Bloquea la fila para que dos ediciones simultáneas no repitan consecutivo de endoso
                $poliza = Poliza::where('id', $id)
                    ->where('id_aseguradora', $user->id_aseguradora)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_if($poliza->estatus === 'cancelada', 422, 'Una póliza cancelada no se puede modificar.');
                abort_if(
                    $request->filled('tipo') && $request->tipo !== $poliza->tipo,
                    422,
                    'El tipo (ramo) de la póliza no se puede cambiar: cancela esta póliza y emite una nueva.'
                );

                $tieneEndoso = $request->boolean('tiene_endoso');

                // ── Valores nuevos del contrato
                $nuevos = [];
                foreach (self::CAMPOS_CONTRACTUALES as $campo) {
                    $nuevos[$campo] = $request->input($campo) ?? $poliza->{$campo};
                }

                abort_if(
                    Carbon::parse($nuevos['fecha_fin'])->lte(Carbon::parse($nuevos['fecha_inicio'])),
                    422,
                    'La fecha de fin debe ser posterior a la de inicio.'
                );

                // IVA y prima total solo se recalculan si cambió la prima o el derecho.
                // Si no, se conservan los valores con los que se emitió (un cambio de tasa de IVA no reprecia pólizas ya emitidas).
                $totalAnterior = (float) $poliza->prima_total;
                $primaCambia = abs((float) $nuevos['prima_neta'] - (float) $poliza->prima_neta) >= 0.005
                    || abs((float) $nuevos['derecho_poliza'] - (float) $poliza->derecho_poliza) >= 0.005;

                if ($primaCambia) {
                    $fin = $this->finanzas->calcular($poliza->tipo, $nuevos['prima_neta'], $nuevos['derecho_poliza'], (int) $user->id_aseguradora);
                    $nuevos['iva'] = $fin['iva'];
                    $nuevos['prima_total'] = $fin['prima_total'];
                } else {
                    $nuevos['iva'] = $poliza->iva;
                    $nuevos['prima_total'] = $poliza->prima_total;
                }

                $claves = array_merge(self::CAMPOS_CONTRACTUALES, ['iva', 'prima_total']);
                $antes = $poliza->only($claves);
                $cambios = $this->diferencias($antes, array_intersect_key($nuevos, array_flip($claves)));

                // ── Bienes y coberturas
                $sincBienes = $request->boolean('bienes_sync');
                $sincCob = $request->boolean('coberturas_sync');

                $bienesActuales = Bienes::where('id_poliza', $poliza->id)->get(['id', 'valor'])
                    ->mapWithKeys(fn ($b) => [(int) $b->id => round((float) $b->valor, 2)])->all();
                $bienesIds = (array) $request->get('bienes_ids', []);
                $precios = (array) $request->get('precios_bienes', []);

                if ($sincBienes) {
                    $ocupados = $this->erroresBienesOcupados($bienesIds, (int) $poliza->id);
                    abort_if(!empty($ocupados), 422, implode(' ', $ocupados));
                }

                $bienesDeLaPoliza = $sincBienes
                    ? $this->bienesValidos((int) $poliza->id_cliente, (int) $poliza->id_aseguradora, $bienesIds, $precios)
                    : [];
                $bienesNuevos = [];
                foreach ($bienesDeLaPoliza as $b) {
                    $bienesNuevos[(int) $b['id']] = round((float) $b['valor'], 2);
                }

                $cobActuales = $this->coberturas->idsAsignados($poliza->id);
                $cobNuevas = $sincCob
                    ? array_values(array_unique(array_map('intval', (array) $request->get('coberturas_ids', []))))
                    : $cobActuales;

                if ($sincCob) {
                    $paraValidar = $sincBienes ? $bienesDeLaPoliza : $this->bienesActualesParaValidar($poliza);
                    $errores = $this->erroresDeCoberturas(
                        (int) $poliza->id_aseguradora, $poliza->tipo, $cobNuevas, $paraValidar, (float) $nuevos['suma_asegurada']
                    );
                    abort_if(!empty($errores), 422, implode(' ', $errores));
                }

                ksort($bienesActuales);
                ksort($bienesNuevos);
                sort($cobActuales);
                $cobOrdenadas = $cobNuevas;
                sort($cobOrdenadas);

                $cambiaBienes = $sincBienes && $bienesActuales != $bienesNuevos;
                // Asignar coberturas por primera vez a una póliza que no tenía es completar datos; cambiarlas es modificar el contrato
                $cambiaCob = $sincCob && !empty($cobActuales) && $cobActuales != $cobOrdenadas;
                $cobPrimeraVez = $sincCob && empty($cobActuales) && !empty($cobOrdenadas);

                if ($cambiaBienes) {
                    $cambios['bienes'] = ['antes' => $bienesActuales, 'despues' => $bienesNuevos];
                }
                if ($cambiaCob) {
                    $cambios['coberturas'] = ['antes' => $cobActuales, 'despues' => $cobOrdenadas];
                }

                // ── Reglas de ciclo de vida
                abort_if(
                    $this->estaVencida($poliza) && (!empty($cambios) || $tieneEndoso),
                    422,
                    'La póliza ya venció: no admite endosos ni cambios al contrato. Renuévala para continuar.'
                );

                abort_if(
                    !empty($cambios) && !$tieneEndoso,
                    422,
                    'Estos cambios modifican el contrato (' . implode(', ', array_keys($cambios))
                        . '). Agrega un endoso con su descripción y documento para guardarlos.'
                );

                // ── Fecha de efecto del endoso: dentro de la vigencia original y sin retroactividad excesiva
                $fechaEfecto = null;
                if ($tieneEndoso) {
                    $fechaEfecto = Carbon::parse($request->input('fecha_efecto') ?: now()->toDateString())->startOfDay();
                    $inicioOriginal = Carbon::parse($poliza->fecha_inicio)->startOfDay();
                    $finOriginal = Carbon::parse($poliza->fecha_fin)->startOfDay();

                    abort_if(
                        $fechaEfecto->lt($inicioOriginal) || $fechaEfecto->gt($finOriginal),
                        422,
                        'La fecha de efecto del endoso debe estar dentro de la vigencia de la póliza ('
                            . $inicioOriginal->toDateString() . ' al ' . $finOriginal->toDateString() . ').'
                    );
                    abort_if(
                        $fechaEfecto->lt(now()->startOfDay()->subDays(self::MAX_RETROACTIVIDAD_DIAS)),
                        422,
                        'El endoso no puede tener una retroactividad mayor a ' . self::MAX_RETROACTIVIDAD_DIAS . ' días.'
                    );
                }

                $obsAntes = (string) $poliza->observaciones;
                $obsNuevas = $request->has('observaciones') ? (string) $request->input('observaciones') : $obsAntes;
                $teniaDocumento = !empty($poliza->documento_ruta);

                // ── Guardar
                $poliza->update($nuevos + [
                    'observaciones' => $request->has('observaciones') ? $request->input('observaciones') : $poliza->observaciones,
                ]);

                if ($sincBienes) {
                    $this->sincronizarBienes($poliza, $bienesIds, $precios);
                }
                if ($sincCob) {
                    $this->coberturas->sincronizar($poliza, $cobNuevas);
                }

                // ── Bitácora de lo que NO es endoso (el endoso se registra solo en crearEndoso)
                $idAseg = (int) $poliza->id_aseguradora;

                if ($obsAntes !== $obsNuevas) {
                    $this->bitacora->registrar($poliza->id, $idAseg, 'modificada', 'Observaciones modificadas', [
                        'observaciones' => ['antes' => $obsAntes, 'despues' => $obsNuevas],
                    ]);
                }
                if ($cobPrimeraVez) {
                    $this->bitacora->registrar($poliza->id, $idAseg, 'coberturas', 'Se asignaron las coberturas de la póliza', [
                        'coberturas' => ['antes' => [], 'despues' => $cobOrdenadas],
                    ]);
                }

                if ($request->hasFile('documento')) {
                    $this->adjuntarDocumento($poliza, $request->file('documento'));
                    $this->bitacora->registrar(
                        $poliza->id, $idAseg, 'documento',
                        $teniaDocumento ? 'Se reemplazó el documento de la póliza' : 'Se cargó el documento de la póliza'
                    );
                }

                if ($tieneEndoso) {
                    $ajuste = $this->finanzas->ajusteEndoso(
                        $totalAnterior,
                        (float) $nuevos['prima_total'],
                        $fechaEfecto,
                        $nuevos['fecha_inicio'],
                        $nuevos['fecha_fin']
                    );

                    return $this->crearEndoso($poliza, $request, $cambios, $ajuste, $fechaEfecto->toDateString());
                }

                return null;
            });

            return response()->json([
                'message' => 'Póliza actualizada exitosamente',
                'endoso' => $infoEndoso,
            ]);

        } catch (\Exception $e) {
            return $this->falla($e, 'update');
        }
    }

        /** POST /polizas/{id}/cancelar  { motivo } — la póliza NO se borra: queda cancelada y de solo consulta. */
    public function cancelar(Request $request, $id)
    {
        try {
            $user = auth()->user();

            $validator = Validator::make($request->all(), [
                'motivo' => ['required', 'string', 'min:5', 'max:200'],
            ], [
                'motivo.required' => 'Escribe el motivo de la cancelación.',
                'motivo.min'      => 'El motivo debe tener al menos 5 caracteres.',
                'motivo.max'      => 'El motivo no debe pasar de 200 caracteres.',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $motivo = trim((string) $request->input('motivo'));

            DB::transaction(function () use ($id, $user, $motivo) {
                $poliza = Poliza::where('id', $id)
                    ->where('id_aseguradora', $user->id_aseguradora)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_if($poliza->estatus === 'cancelada', 422, 'La póliza ya está cancelada.');
                abort_if($this->estaVencida($poliza), 422, 'La póliza ya venció: no se puede cancelar.');

                $estatusAnterior = $poliza->estatus;
                $poliza->update(['estatus' => 'cancelada']);

                $this->bitacora->registrar(
                    $poliza->id,
                    (int) $poliza->id_aseguradora,
                    'estatus',
                    "Póliza {$poliza->numero_poliza} cancelada. Motivo: {$motivo}",
                    [
                        'estatus' => ['antes' => $estatusAnterior, 'despues' => 'cancelada'],
                        'motivo'  => ['antes' => null, 'despues' => $motivo],
                    ]
                );
            });

            return response()->json(['message' => 'Póliza cancelada correctamente.']);
        } catch (\Exception $e) {
            return $this->falla($e, 'cancelar');
        }
    }

    public function destroy($id)
    {
        try {
            $user = auth()->user();

            $poliza = Poliza::where('id', $id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();

            if (Reportes::where('id_poliza', $poliza->id)->exists()) {
                return response()->json([
                    'message' => 'La póliza tiene reportes o siniestros y no se puede eliminar. Cancélala si ya no debe operar.',
                ], 422);
            }

            $carpeta = "polizas/{$poliza->id_aseguradora}/{$poliza->id}";

            // Queda constancia en la bitácora aunque la póliza desaparezca
            $this->bitacora->registrar(
                $poliza->id,
                (int) $poliza->id_aseguradora,
                'eliminada',
                "Póliza {$poliza->numero_poliza} eliminada"
            );

            Bienes::where('id_poliza', $id)->update(['id_poliza' => null]);
            DB::table('polizas_x_cobertura')->where('id_poliza', $poliza->id)->delete();
            $poliza->delete(); // los endosos se borran en cascada

            // Borra el PDF de la póliza y los de sus endosos
            Storage::disk('local')->deleteDirectory($carpeta);

            // Nota: el número de póliza NO se reutiliza, el contador sigue avanzando.
            return response()->json(['message' => 'Póliza eliminada exitosamente']);
        } catch (\Exception $e) {
            return $this->falla($e, 'destroy');
        }
    }

    /**
     * Asigna bienes a una póliza. Una póliza emitida (no borrador) no cambia sus bienes por esta vía:
     * eso modifica el contrato y se hace con un endoso desde la edición de la póliza.
     */
    public function asignarBienes(Request $request, $id)
    {
        try {
            $user = auth()->user();

            DB::transaction(function () use ($request, $id, $user) {
                $poliza = Poliza::where('id', $id)
                    ->where('id_aseguradora', $user->id_aseguradora)
                    ->lockForUpdate()
                    ->firstOrFail();

                abort_if(
                    strtolower((string) $poliza->estatus) !== 'borrador',
                    422,
                    'Los bienes de una póliza emitida solo se cambian con un endoso: edita la póliza y agrega el endoso.'
                );

                $bienesIds = (array) $request->get('bienes_ids', []);
                $precios = (array) $request->get('precios_bienes', []);

                $ocupados = $this->erroresBienesOcupados($bienesIds, (int) $poliza->id);
                abort_if(!empty($ocupados), 422, implode(' ', $ocupados));

                $this->sincronizarBienes($poliza, $bienesIds, $precios);

                $this->bitacora->registrar(
                    $poliza->id,
                    (int) $poliza->id_aseguradora,
                    'bienes',
                    'Se actualizaron los bienes asegurados (' . count(array_unique($bienesIds)) . ')'
                );
            });

            return response()->json(['message' => 'Bienes asignados correctamente']);
        } catch (\Exception $e) {
            return $this->falla($e, 'asignarBienes');
        }
    }

    // ───────────────────────── Documentos (PDF) ─────────────────────────

    /** Devuelve el PDF de la póliza para verlo en el navegador. */
    public function documento($id)
    {
        $poliza = Poliza::where('id', $id)
            ->where('id_aseguradora', auth()->user()->id_aseguradora)
            ->firstOrFail();

        return $this->responderPdf($poliza->documento_ruta, $poliza->documento_nombre);
    }

    /** Devuelve el PDF de un endoso. */
    public function documentoEndoso($id, $endosoId)
    {
        $poliza = Poliza::where('id', $id)
            ->where('id_aseguradora', auth()->user()->id_aseguradora)
            ->firstOrFail();

        $endoso = PolizaEndoso::where('id', $endosoId)
            ->where('id_poliza', $poliza->id)
            ->firstOrFail();

        return $this->responderPdf($endoso->documento_ruta, $endoso->documento_nombre);
    }

    private function responderPdf(?string $ruta, ?string $nombre)
    {
        $disk = Storage::disk('local');

        if (!$ruta || !$disk->exists($ruta)) {
            return response()->json(['message' => 'Documento no encontrado'], 404);
        }

        return $disk->response($ruta, $nombre ?: 'documento.pdf', ['Content-Type' => 'application/pdf']);
    }

    /** Reglas de validación de los PDF y de los datos del endoso (sirven para store y update). */
    private function reglasDocumentos(): array
    {
        // El N° de endoso NO se captura: se arma con el N° de póliza.
        $reglas = [
            'documento' => ['nullable', 'file', 'mimes:pdf', 'max:' . self::MAX_PDF_KB],
            'tiene_endoso' => ['nullable', 'boolean'],
            'descripcion_endoso' => ['required_if:tiene_endoso,1', 'nullable', 'string', 'max:255'],
            'endoso_documento' => ['required_if:tiene_endoso,1', 'nullable', 'file', 'mimes:pdf', 'max:' . self::MAX_PDF_KB],
            'fecha_efecto' => ['nullable', 'date'],
        ];

        $mensajes = [
            'documento.mimes' => 'El documento de la póliza debe ser un PDF.',
            'documento.max' => 'El documento de la póliza no debe pesar más de 2 MB.',
            'documento.uploaded' => 'No se pudo subir el documento de la póliza. Revisa que sea PDF y pese menos de 2 MB.',
            'descripcion_endoso.required_if' => 'Escribe la descripción del endoso.',
            'endoso_documento.required_if' => 'Sube el documento del endoso (PDF).',
            'endoso_documento.mimes' => 'El documento del endoso debe ser un PDF.',
            'endoso_documento.max' => 'El documento del endoso no debe pesar más de 2 MB.',
            'endoso_documento.uploaded' => 'No se pudo subir el documento del endoso. Revisa que sea PDF y pese menos de 2 MB.',
        ];

        return [$reglas, $mensajes];
    }

    /** Guarda un PDF en el disco privado y devuelve sus datos. */
    private function guardarPdf(UploadedFile $file, string $carpeta): array
    {
        $ruta = $file->store($carpeta, 'local');

        return [
            'ruta' => $ruta,
            'nombre' => $file->getClientOriginalName(),
            'tamanio' => $file->getSize(),
        ];
    }

    /** Guarda (o reemplaza) el PDF de la póliza. */
    private function adjuntarDocumento(Poliza $poliza, UploadedFile $file): void
    {
        if ($poliza->documento_ruta) {
            Storage::disk('local')->delete($poliza->documento_ruta);
        }

        $doc = $this->guardarPdf($file, "polizas/{$poliza->id_aseguradora}/{$poliza->id}");

        $poliza->update([
            'documento_ruta' => $doc['ruta'],
            'documento_nombre' => $doc['nombre'],
            'documento_tamanio' => $doc['tamanio'],
        ]);
    }

    // ───────────────────────── Numeración ─────────────────────────

    /**
     * Asigna el siguiente N° de póliza: RAMO-AÑO-OFICINA-CONSECUTIVO (A-2026-001-000051).
     * El consecutivo sale de un contador con bloqueo de fila: nunca se repite ni se reutiliza.
     * Debe llamarse dentro de una transacción (el bloqueo dura hasta que termina).
     */
    private function generarNumeroPoliza(string $tipo, int $idAseguradora): string
    {
        $ramo = self::LETRAS_TIPO[$tipo];
        $anio = (int) now()->year;
        $prefijo = sprintf('%s-%d-%03d-', $ramo, $anio, $idAseguradora);

        $llave = ['id_aseguradora' => $idAseguradora, 'ramo' => $ramo, 'anio' => $anio];

        DB::table('polizas_secuencias')->insertOrIgnore($llave + [
            'ultimo' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $fila = DB::table('polizas_secuencias')->where($llave)->lockForUpdate()->first();
        $ultimo = (int) $fila->ultimo;

        // Contador nuevo: continúa después del mayor consecutivo que ya exista con ese prefijo
        if ($ultimo === 0) {
            $existente = DB::table('polizas')
                ->where('id_aseguradora', $idAseguradora)
                ->whereRaw('numero_poliza ~ ?', ['^' . $prefijo . '[0-9]{6}$'])
                ->selectRaw('MAX(CAST(RIGHT(numero_poliza, 6) AS INTEGER)) AS maximo')
                ->value('maximo');

            $ultimo = (int) $existente;
        }

        $siguiente = $ultimo + 1;

        if ($siguiente > 999999) {
            throw new \RuntimeException("Se agotó la numeración del ramo {$ramo} para {$anio}.");
        }

        DB::table('polizas_secuencias')->where($llave)->update([
            'ultimo' => $siguiente,
            'updated_at' => now(),
        ]);

        return $prefijo . str_pad((string) $siguiente, 6, '0', STR_PAD_LEFT);
    }

    /** N° de endoso = N° de póliza + "-E" + consecutivo a 3 dígitos (A-2026-001-000051-E001). */
    private function formarNumeroEndoso(string $numeroPoliza, int $consecutivo): string
    {
        return $numeroPoliza . '-E' . str_pad((string) $consecutivo, 3, '0', STR_PAD_LEFT);
    }

    // ───────────────────────── Datos que se llenan solos ─────────────────────────

    /**
     * Calendario de pagos al crear la póliza:
     *  - primer pago: el día de inicio de vigencia
     *  - día de pago: solo en mensual, trimestral y semestral (máximo 28 para que exista en todos los meses)
     *  - siguiente pago: inicio + 1, 3, 6 o 12 meses; en contado no hay
     */
    private function calendarioDePagos(string $formaPago, string $fechaInicio): array
    {
        $inicio = Carbon::parse($fechaInicio);
        $meses = PolizaFinanzasService::MESES_POR_FORMA_PAGO[$formaPago] ?? null;

        return [
            'fecha_primer_pago' => $inicio->toDateString(),
            'dia_pago' => in_array($formaPago, ['mensual', 'trimestral', 'semestral'], true)
                ? min($inicio->day, 28)
                : null,
            'siguiente_pago' => $meses ? $inicio->copy()->addMonthsNoOverflow($meses)->toDateString() : null,
        ];
    }

    // ───────────────────────── Endosos, bienes y coberturas ─────────────────────────

    /**
     * Registra el endoso. El N° lo asigna el sistema a partir de la póliza:
     * la póliza ya viene bloqueada (lockForUpdate / transacción), así que el consecutivo no se repite.
     *
     * @param array|null $ajuste resultado de PolizaFinanzasService::ajusteEndoso (null = sin movimiento de prima)
     * @return array{id:int, numero_endoso:string, tipo_endoso:string, ajuste_prima:float}
     */
    private function crearEndoso(Poliza $poliza, Request $request, array $cambios = [], ?array $ajuste = null, ?string $fechaEfecto = null): array
    {
        $consecutivo = ((int) PolizaEndoso::where('id_poliza', $poliza->id)->max('consecutivo')) + 1;
        $numero = $this->formarNumeroEndoso($poliza->numero_poliza, $consecutivo);

        $doc = $this->guardarPdf(
            $request->file('endoso_documento'),
            "polizas/{$poliza->id_aseguradora}/{$poliza->id}/endosos"
        );

        $endoso = PolizaEndoso::create([
            'id_poliza' => $poliza->id,
            'id_aseguradora' => $poliza->id_aseguradora,
            'id_usuario' => auth()->id(),
            'consecutivo' => $consecutivo,
            'numero_endoso' => $numero,
            'descripcion' => $request->descripcion_endoso,
            'documento_ruta' => $doc['ruta'],
            'documento_nombre' => $doc['nombre'],
            'documento_tamanio' => $doc['tamanio'],
            'cambios' => $cambios ?: null,
            'fecha_efecto' => $fechaEfecto ?: $request->input('fecha_efecto', now()->toDateString()),
        ]);

        $tipo = $ajuste['tipo'] ?? 'D';
        $monto = (float) ($ajuste['ajuste_prima'] ?? 0);

        // Se guarda con DB::table para no depender del $fillable del modelo
        DB::table('polizas_endosos')->where('id', $endoso->id)->update([
            'tipo_endoso' => $tipo,
            'ajuste_prima' => $monto,
        ]);

        $detalleAjuste = $monto != 0.0
            ? sprintf(' · ajuste de prima a prorrata: %s$%s', $monto > 0 ? '+' : '-', number_format(abs($monto), 2))
            : '';

        $this->bitacora->registrar(
            $poliza->id,
            (int) $poliza->id_aseguradora,
            'endoso',
            "Endoso {$numero} (tipo {$tipo}): " . $request->descripcion_endoso . $detalleAjuste,
            $cambios ?: null
        );

        return [
            'id' => $endoso->id,
            'numero_endoso' => $numero,
            'tipo_endoso' => $tipo,
            'ajuste_prima' => $monto,
        ];
    }

    /** ['campo' => ['antes' => x, 'despues' => y]] solo de lo que cambió. */
    private function diferencias(array $antes, array $despues): array
    {
        $out = [];
        foreach ($despues as $campo => $nuevo) {
            $viejo = $antes[$campo] ?? null;
            $iguales = (is_numeric($viejo) && is_numeric($nuevo))
                ? abs((float) $viejo - (float) $nuevo) < 0.005
                : (string) $viejo === (string) $nuevo;

            if (!$iguales) {
                $out[$campo] = ['antes' => $viejo, 'despues' => $nuevo];
            }
        }
        return $out;
    }

    /**
     * Doble aseguramiento: un bien que ya está en OTRA póliza vigente no puede asegurarse de nuevo.
     * (Las pólizas vencidas o canceladas liberan el bien, por ejemplo para una renovación.)
     *
     * @return string[] mensajes de error (vacío = todo bien)
     */
    private function erroresBienesOcupados(array $bienesIds, ?int $idPoliza): array
    {
        $ids = array_values(array_unique(array_map('intval', $bienesIds)));
        if (!$ids) {
            return [];
        }

        $tabla = (new Bienes)->getTable();

        return Bienes::whereIn("{$tabla}.id", $ids)
            ->join('polizas as p', 'p.id', '=', "{$tabla}.id_poliza")
            ->when($idPoliza, fn ($q) => $q->where("{$tabla}.id_poliza", '!=', $idPoliza))
            ->whereIn('p.estatus', self::ESTATUS_QUE_OCUPAN_BIEN)
            ->whereDate('p.fecha_fin', '>=', now()->toDateString())
            ->get(["{$tabla}.descripcion", "{$tabla}.tipo", 'p.numero_poliza'])
            ->map(fn ($b) => "El bien «" . ($b->descripcion ?: $b->tipo) . "» ya está asegurado en la póliza {$b->numero_poliza}. Quítalo de esa póliza o cancélala antes de asegurarlo aquí.")
            ->values()
            ->all();
    }

    /**
     * Bienes del cliente (de esta aseguradora) con el valor que llevarán en la póliza.
     * @return array<int, array{id:int, tipo:string, valor:float, descripcion:?string}>
     */
    private function bienesValidos(int $idCliente, int $idAseguradora, array $bienesIds, array $precios): array
    {
        $ids = array_values(array_unique(array_map('intval', $bienesIds)));
        if (!$ids) {
            return [];
        }

        return Bienes::whereIn('id', $ids)
            ->where('id_cliente', $idCliente)
            ->where('id_aseguradora', $idAseguradora)
            ->get()
            ->map(fn ($b) => [
                'id' => (int) $b->id,
                'tipo' => (string) $b->tipo,
                'valor' => (float) ($precios[$b->id] ?? $b->valor),
                'descripcion' => $b->descripcion,
            ])->all();
    }

    private function bienesActualesParaValidar(Poliza $poliza): array
    {
        return Bienes::where('id_poliza', $poliza->id)->get()
            ->map(fn ($b) => ['id' => (int) $b->id, 'tipo' => (string) $b->tipo, 'valor' => (float) $b->valor, 'descripcion' => $b->descripcion])
            ->all();
    }

    /** Errores de la selección de coberturas (vacío = bien). Con coberturas disponibles para el ramo, se exige al menos una. */
    private function erroresDeCoberturas(int $idAseguradora, string $ramo, array $ids, array $bienes, float $suma): array
    {
        $errores = $this->coberturas->validar($idAseguradora, $ramo, $ids, $bienes, $suma);

        if (empty($ids) && $this->coberturas->disponibles($idAseguradora, $ramo)->isNotEmpty()) {
            $errores[] = 'Selecciona al menos una cobertura para la póliza.';
        }

        return $errores;
    }

    /**
     * Deja asignados a la póliza exactamente los bienes indicados (solo del mismo cliente y
     * aseguradora) y aplica los precios.
     */
    private function sincronizarBienes(Poliza $poliza, array $bienesIds, array $precios): void
    {
        $bienesIds = array_values(array_unique(array_map('intval', $bienesIds)));

        $validos = Bienes::whereIn('id', $bienesIds)
            ->where('id_cliente', $poliza->id_cliente)
            ->where('id_aseguradora', $poliza->id_aseguradora)
            ->get()
            ->keyBy('id');

        $actualesIds = Bienes::where('id_poliza', $poliza->id)->pluck('id')->all();
        $quitados = array_values(array_diff($actualesIds, $validos->keys()->all()));

        if (!empty($quitados)) {
            Bienes::whereIn('id', $quitados)->update(['id_poliza' => null]);
        }

        foreach ($validos as $bienId => $bien) {
            $update = ['id_poliza' => $poliza->id];

            if (isset($precios[$bienId])) {
                $update['valor'] = round((float) $precios[$bienId], 2);
            }

            Bienes::where('id', $bienId)->update($update);
        }
    }

    private function datosCliente(Cliente $cliente): array
    {
        return [
            'id' => $cliente->id,
            'nombre' => $cliente->nombre,
            'apellido' => $cliente->apellidos,
            'rfc' => $cliente->rfc,
            'curp' => $cliente->curp,
            'domicilio' => $cliente->domicilio,
            'correo' => $cliente->correo,
            'telefono' => $cliente->numero_de_telefono,
        ];
    }

    private function traducirEstatus($estatus)
    {
        $map = [
            'activa' => 'Activa',
            'vencida' => 'Vencida',
            'cancelada' => 'Cancelada',
            'suspendida' => 'Suspendida',
            'en_renovacion' => 'En renovación'
        ];
        return $map[$estatus] ?? $estatus;
    }
}
