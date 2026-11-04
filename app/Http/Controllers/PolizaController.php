<?php
// app/Http/Controllers/PolizaController.php

namespace App\Http\Controllers;

use App\Models\Poliza;
use App\Models\PolizaEndoso;
use App\Models\Cliente;
use App\Models\Bienes;
use App\Models\Empleado;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class PolizaController extends Controller
{
    /** Campos que se pueden modificar al editar (numero_poliza NO se modifica nunca). */
    private const CAMPOS_EDITABLES = [
        'tipo', 'estatus', 'fecha_inicio', 'fecha_fin', 'suma_asegurada',
        'prima_neta', 'derecho_poliza', 'iva', 'prima_total', 'forma_pago', 'observaciones',
    ];

    /** Límite de los PDF en KB (2 MB, igual que en el resto del sistema). */
    private const MAX_PDF_KB = 2048;

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

    /** Cada cuántos meses se paga según la forma de pago (contado no tiene siguiente pago). */
    private const MESES_POR_FORMA_PAGO = [
        'mensual' => 1,
        'trimestral' => 3,
        'semestral' => 6,
        'anual' => 12,
    ];

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
                ->leftJoin('clientes', 'polizas.id_cliente', '=', 'clientes.id')
                ->where('polizas.id_aseguradora', $user->id_aseguradora);

            if (!empty($search)) {
                $query->where(function ($q) use ($search) {
                    $q->where('polizas.numero_poliza', 'LIKE', "%{$search}%")
                      ->orWhere('clientes.nombre', 'LIKE', "%{$search}%")
                      ->orWhere('clientes.apellidos', 'LIKE', "%{$search}%");
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
            Log::error('Error en index: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
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

    public function store(Request $request)
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json(['message' => 'Usuario no autenticado'], 401);
            }

            [$reglasDocs, $mensajes] = $this->reglasDocumentos();

            // El número de póliza NO se captura: lo asigna el sistema al guardar.
            $validator = Validator::make($request->all(), array_merge([
                'id_cliente' => 'required|exists:clientes,id',
                'tipo' => 'required|string|in:' . implode(',', array_keys(self::LETRAS_TIPO)),
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after:fecha_inicio',
                'suma_asegurada' => 'required|numeric|min:0',
                'prima_neta' => 'required|numeric|min:0',
                'derecho_poliza' => 'required|numeric|min:0',
                'iva' => 'required|numeric|min:0',
                'prima_total' => 'required|numeric|min:0',
                'forma_pago' => 'required|string',
            ], $reglasDocs), $mensajes);

            if ($validator->fails()) {
                Log::error('Validación fallida:', $validator->errors()->toArray());
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $empleado = Empleado::where('id_aseguradora', $user->id_aseguradora)->first();

            if (!$empleado) {
                Log::error('No hay empleados registrados para la aseguradora ID: ' . $user->id_aseguradora);
                return response()->json(['message' => 'No hay empleados registrados. Por favor, registra un empleado primero.'], 422);
            }

            $poliza = DB::transaction(function () use ($request, $user, $empleado) {
                // Se asigna dentro de la transacción: si algo falla, el consecutivo no se gasta
                $numero = $this->generarNumeroPoliza($request->tipo, (int) $user->id_aseguradora);

                $poliza = Poliza::create(array_merge([
                    'id_aseguradora' => $user->id_aseguradora,
                    'id_cliente' => $request->id_cliente,
                    'id_empleado' => $empleado->id,
                    'numero_poliza' => $numero,
                    'tipo' => $request->tipo,
                    'estatus' => 'activa',
                    'fecha_emision' => now()->format('Y-m-d'),
                    'fecha_inicio' => $request->fecha_inicio,
                    'fecha_fin' => $request->fecha_fin,
                    'suma_asegurada' => $request->suma_asegurada,
                    'prima_neta' => $request->prima_neta,
                    'derecho_poliza' => $request->derecho_poliza,
                    'iva' => $request->iva,
                    // La prima total siempre se calcula aquí: prima neta + derecho + IVA
                    'prima_total' => $this->calcularPrimaTotal($request->prima_neta, $request->derecho_poliza, $request->iva),
                    'forma_pago' => $request->forma_pago,
                    'observaciones' => $request->observaciones,
                ], $this->calendarioDePagos($request->forma_pago, $request->fecha_inicio)));

                $this->sincronizarBienes(
                    $poliza,
                    (array) $request->get('bienes_ids', []),
                    (array) $request->get('precios_bienes', [])
                );

                if ($request->hasFile('documento')) {
                    $this->adjuntarDocumento($poliza, $request->file('documento'));
                }

                if ($request->boolean('tiene_endoso')) {
                    $this->crearEndoso($poliza, $request);
                }

                return $poliza;
            });

            Log::info('Póliza creada ID: ' . $poliza->id . ' N°: ' . $poliza->numero_poliza);

            return response()->json(['message' => 'Póliza creada exitosamente', 'poliza' => $poliza], 201);

        } catch (\Exception $e) {
            Log::error('Error en store: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

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

            return response()->json([
                'id' => $poliza->id,
                'nPoliza' => $poliza->numero_poliza,
                'cliente' => $poliza->cliente ? $poliza->cliente->nombre . ' ' . $poliza->cliente->apellidos : '',
                'id_cliente' => $poliza->id_cliente,
                'fechaInicio' => $poliza->fecha_inicio,
                'fechaFinal' => $poliza->fecha_fin,
                'estatus' => $this->traducirEstatus($poliza->estatus),
                'sumaAsegurada' => $poliza->suma_asegurada,
                'formaPago' => $poliza->forma_pago,
                'montoPrima' => $poliza->prima_total,
                'observaciones' => $poliza->observaciones,
                'tipo' => $poliza->tipo,
                'prima_neta' => $poliza->prima_neta,
                'derecho_poliza' => $poliza->derecho_poliza,
                'iva' => $poliza->iva,
                'prima_total' => $poliza->prima_total,
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
                'endosos' => $poliza->endosos->map(fn ($e) => [
                    'id' => $e->id,
                    'numero_endoso' => $e->numero_endoso,
                    'fecha' => optional($e->created_at)->format('Y-m-d H:i'),
                    'descripcion' => $e->descripcion,
                    'tiene_documento' => !empty($e->documento_ruta),
                    'documento_nombre' => $e->documento_nombre,
                ])->values(),
            ]);
        } catch (\Exception $e) {
            Log::error('Error en show: ' . $e->getMessage());
            return response()->json(['message' => 'Póliza no encontrada'], 404);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = auth()->user();

            [$reglasDocs, $mensajes] = $this->reglasDocumentos();
            $validator = Validator::make($request->all(), $reglasDocs, $mensajes);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            DB::transaction(function () use ($request, $id, $user) {
                // Bloquea la fila para que dos ediciones simultáneas no repitan consecutivo de endoso
                $poliza = Poliza::where('id', $id)
                    ->where('id_aseguradora', $user->id_aseguradora)
                    ->lockForUpdate()
                    ->firstOrFail();

                // numero_poliza NO se modifica nunca.
                $nuevos = [];
                foreach (self::CAMPOS_EDITABLES as $campo) {
                    $nuevos[$campo] = $request->input($campo) ?? $poliza->{$campo};
                }

                // La prima total siempre se calcula aquí: prima neta + derecho + IVA
                $nuevos['prima_total'] = $this->calcularPrimaTotal(
                    $nuevos['prima_neta'], $nuevos['derecho_poliza'], $nuevos['iva']
                );

                $poliza->update($nuevos);

                // El front manda bienes_sync=1 siempre, así también se pueden quitar todos los bienes.
                if ($request->boolean('bienes_sync')) {
                    $this->sincronizarBienes(
                        $poliza,
                        (array) $request->get('bienes_ids', []),
                        (array) $request->get('precios_bienes', [])
                    );
                }

                if ($request->hasFile('documento')) {
                    $this->adjuntarDocumento($poliza, $request->file('documento'));
                }

                if ($request->boolean('tiene_endoso')) {
                    $this->crearEndoso($poliza, $request);
                }
            });

            return response()->json(['message' => 'Póliza actualizada exitosamente']);

        } catch (\Exception $e) {
            Log::error('Error al actualizar póliza: ' . $e->getMessage());
            return response()->json(['message' => 'Error al actualizar póliza: ' . $e->getMessage()], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = auth()->user();

            $poliza = Poliza::where('id', $id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();

            $carpeta = "polizas/{$poliza->id_aseguradora}/{$poliza->id}";

            Bienes::where('id_poliza', $id)->update(['id_poliza' => null]);
            $poliza->delete(); // los endosos se borran en cascada

            // Borra el PDF de la póliza y los de sus endosos
            Storage::disk('local')->deleteDirectory($carpeta);

            // Nota: el número de póliza NO se reutiliza, el contador sigue avanzando.
            return response()->json(['message' => 'Póliza eliminada exitosamente']);
        } catch (\Exception $e) {
            Log::error('Error al eliminar póliza: ' . $e->getMessage());
            return response()->json(['message' => 'Error al eliminar póliza'], 500);
        }
    }

    public function asignarBienes(Request $request, $id)
    {
        try {
            $user = auth()->user();

            DB::transaction(function () use ($request, $id, $user) {
                $poliza = Poliza::where('id', $id)
                    ->where('id_aseguradora', $user->id_aseguradora)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->sincronizarBienes(
                    $poliza,
                    (array) $request->get('bienes_ids', []),
                    (array) $request->get('precios_bienes', [])
                );
            });

            return response()->json(['message' => 'Bienes asignados correctamente']);
        } catch (\Exception $e) {
            Log::error('Error en asignarBienes: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
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
     *
     *  - Ramo: letra del tipo de póliza.
     *  - Año: año de emisión.
     *  - Oficina: id de la aseguradora a 3 dígitos.
     *  - Consecutivo: uno por ramo + año + aseguradora, a 6 dígitos. Sale de un contador con
     *    bloqueo de fila: nunca se repite y nunca se reutiliza, aunque se borre una póliza.
     *
     * Debe llamarse dentro de una transacción (el bloqueo dura hasta que termina).
     */
    private function generarNumeroPoliza(string $tipo, int $idAseguradora): string
    {
        $ramo = self::LETRAS_TIPO[$tipo];
        $anio = (int) now()->year;
        $prefijo = sprintf('%s-%d-%03d-', $ramo, $anio, $idAseguradora);

        $llave = ['id_aseguradora' => $idAseguradora, 'ramo' => $ramo, 'anio' => $anio];

        // Crea el contador del ramo/año si no existe (si ya existe, no hace nada)
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

    /** Prima total = prima neta + derecho de póliza + IVA. */
    private function calcularPrimaTotal($primaNeta, $derecho, $iva): float
    {
        return round((float) $primaNeta + (float) $derecho + (float) $iva, 2);
    }

    /**
     * Calendario de pagos al crear la póliza:
     *  - primer pago: el día de inicio de vigencia
     *  - día de pago: solo en mensual, trimestral y semestral (máximo 28 para que exista en todos los meses)
     *  - siguiente pago: inicio + 1, 3, 6 o 12 meses; en contado no hay
     */
    private function calendarioDePagos(string $formaPago, string $fechaInicio): array
    {
        $inicio = Carbon::parse($fechaInicio);
        $meses = self::MESES_POR_FORMA_PAGO[$formaPago] ?? null;

        return [
            'fecha_primer_pago' => $inicio->toDateString(),
            'dia_pago' => in_array($formaPago, ['mensual', 'trimestral', 'semestral'], true)
                ? min($inicio->day, 28)
                : null,
            'siguiente_pago' => $meses ? $inicio->copy()->addMonthsNoOverflow($meses)->toDateString() : null,
        ];
    }

    // ───────────────────────── Endosos y bienes ─────────────────────────

    /**
     * Registra el endoso. El N° lo asigna el sistema a partir de la póliza:
     * la póliza ya viene bloqueada (lockForUpdate / transacción), así que el consecutivo no se repite.
     */
    private function crearEndoso(Poliza $poliza, Request $request): PolizaEndoso
    {
        $consecutivo = ((int) PolizaEndoso::where('id_poliza', $poliza->id)->max('consecutivo')) + 1;

        $doc = $this->guardarPdf(
            $request->file('endoso_documento'),
            "polizas/{$poliza->id_aseguradora}/{$poliza->id}/endosos"
        );

        return PolizaEndoso::create([
            'id_poliza' => $poliza->id,
            'id_aseguradora' => $poliza->id_aseguradora,
            'id_usuario' => auth()->id(),
            'consecutivo' => $consecutivo,
            'numero_endoso' => $this->formarNumeroEndoso($poliza->numero_poliza, $consecutivo),
            'descripcion' => $request->descripcion_endoso,
            'documento_ruta' => $doc['ruta'],
            'documento_nombre' => $doc['nombre'],
            'documento_tamanio' => $doc['tamanio'],
        ]);
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