<?php
// app/Http/Controllers/PolizaController.php

namespace App\Http\Controllers;

use App\Models\Poliza;
use App\Models\Cliente;
use App\Models\Bienes;
use App\Models\Empleado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class PolizaController extends Controller
{
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
                ->leftJoin('clientes', 'polizas.id_cliente', '=', 'clientes.id')
                ->where('polizas.id_aseguradora', $user->id_aseguradora); // Filtro por aseguradora
            
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
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
                $data[] = [
                    'id' => $poliza->id,
                    'nPoliza' => $poliza->numero_poliza,
                    'cliente' => ($poliza->nombre ? $poliza->nombre . ' ' . $poliza->apellidos : 'Sin cliente'),
                    'id_cliente' => $poliza->cliente_id,
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

            return response()->json([
                'id' => $cliente->id,
                'nombre' => $cliente->nombre,
                'apellido' => $cliente->apellidos,
                'rfc' => $cliente->rfc,
                'curp' => $cliente->curp,
                'domicilio' => $cliente->domicilio,
                'correo' => $cliente->correo,
                'telefono' => $cliente->numero_de_telefono,
            ]);
        } catch (\Exception $e) {
            Log::error('Error al buscar cliente: ' . $e->getMessage());
            return response()->json(['message' => 'Error al buscar cliente'], 500);
        }
    }

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
            
            $ultimaPoliza = Poliza::where('id_cliente', $cliente->id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->orderBy('id', 'desc')
                ->first();
            
            if (!$ultimaPoliza) {
                return response()->json([
                    'cliente' => [
                        'id' => $cliente->id,
                        'nombre' => $cliente->nombre,
                        'apellido' => $cliente->apellidos,
                        'rfc' => $cliente->rfc,
                        'curp' => $cliente->curp,
                        'domicilio' => $cliente->domicilio,
                        'correo' => $cliente->correo,
                        'telefono' => $cliente->numero_de_telefono,
                    ],
                    'tienePoliza' => false
                ], 200);
            }
            
            return response()->json([
                'cliente' => [
                    'id' => $cliente->id,
                    'nombre' => $cliente->nombre,
                    'apellido' => $cliente->apellidos,
                    'rfc' => $cliente->rfc,
                    'curp' => $cliente->curp,
                    'domicilio' => $cliente->domicilio,
                    'correo' => $cliente->correo,
                    'telefono' => $cliente->numero_de_telefono,
                ],
                'ultimaPoliza' => [
                    'numero_poliza' => $ultimaPoliza->numero_poliza . '-NUEVA',
                    'tipo' => $ultimaPoliza->tipo,
                    'suma_asegurada' => $ultimaPoliza->suma_asegurada,
                    'prima_neta' => $ultimaPoliza->prima_neta,
                    'derecho_poliza' => $ultimaPoliza->derecho_poliza,
                    'iva' => $ultimaPoliza->iva,
                    'prima_total' => $ultimaPoliza->prima_total,
                    'forma_pago' => $ultimaPoliza->forma_pago,
                    'observaciones' => $ultimaPoliza->observaciones,
                ],
                'tienePoliza' => true
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en getUltimaPolizaPorRfc: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            Log::info('=== INICIO STORE POLIZA ===');
            Log::info('Datos completos recibidos:', $request->all());
            
            $validator = Validator::make($request->all(), [
                'numero_poliza' => 'required|string|max:30|unique:polizas,numero_poliza',
                'id_cliente' => 'required|exists:clientes,id',
                'tipo' => 'required|string',
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after:fecha_inicio',
                'suma_asegurada' => 'required|numeric|min:0',
                'prima_neta' => 'required|numeric|min:0',
                'derecho_poliza' => 'required|numeric|min:0',
                'iva' => 'required|numeric|min:0',
                'prima_total' => 'required|numeric|min:0',
                'forma_pago' => 'required|string',
            ]);

            if ($validator->fails()) {
                Log::error('Validación fallida:', $validator->errors()->toArray());
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $user = auth()->user();
            
            if (!$user) {
                Log::error('Usuario no autenticado');
                return response()->json(['message' => 'Usuario no autenticado'], 401);
            }
            
            // Obtener un empleado válido de la aseguradora
            $empleado = Empleado::where('id_aseguradora', $user->id_aseguradora)->first();
            
            if (!$empleado) {
                Log::error('No hay empleados registrados para la aseguradora ID: ' . $user->id_aseguradora);
                return response()->json(['message' => 'No hay empleados registrados. Por favor, registra un empleado primero.'], 422);
            }
            
            Log::info('Usuario autenticado ID: ' . $user->id . ', Aseguradora ID: ' . $user->id_aseguradora);
            Log::info('Empleado seleccionado ID: ' . $empleado->id);
            
            $polizaData = [
                'id_aseguradora' => $user->id_aseguradora,
                'id_cliente' => $request->id_cliente,
                'id_empleado' => $empleado->id,
                'numero_poliza' => $request->numero_poliza,
                'tipo' => $request->tipo,
                'estatus' => 'activa',
                'fecha_emision' => now()->format('Y-m-d'),
                'fecha_inicio' => $request->fecha_inicio,
                'fecha_fin' => $request->fecha_fin,
                'suma_asegurada' => $request->suma_asegurada,
                'prima_neta' => $request->prima_neta,
                'derecho_poliza' => $request->derecho_poliza,
                'iva' => $request->iva,
                'prima_total' => $request->prima_total,
                'forma_pago' => $request->forma_pago,
                'observaciones' => $request->observaciones,
            ];
            
            Log::info('Datos a insertar:', $polizaData);
            
            $poliza = Poliza::create($polizaData);

            Log::info('Póliza creada ID: ' . $poliza->id);

            // Asignar bienes
            $bienesIds = $request->get('bienes_ids', []);
            $preciosBienes = $request->get('precios_bienes', []);
            
            Log::info('Bienes IDs: ' . json_encode($bienesIds));
            Log::info('Precios bienes: ' . json_encode($preciosBienes));
            
            if (!empty($bienesIds)) {
                foreach ($bienesIds as $bienId) {
                    $updateData = ['id_poliza' => $poliza->id];
                    if (isset($preciosBienes[$bienId])) {
                        $updateData['valor'] = floatval($preciosBienes[$bienId]);
                    }
                    Bienes::where('id', $bienId)->update($updateData);
                }
                Log::info('Bienes asignados correctamente');
            }

            return response()->json(['message' => 'Póliza creada exitosamente', 'poliza' => $poliza], 201);
            
        } catch (\Exception $e) {
            Log::error('Error en store: ' . $e->getMessage());
            Log::error('Stack trace: ' . $e->getTraceAsString());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function show($id)
    {
        try {
            $user = auth()->user();
            
            $poliza = Poliza::with('cliente')
                ->where('id', $id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();
            
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
            
            $poliza = Poliza::where('id', $id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();
            
            $poliza->update([
                'numero_poliza' => $request->numero_poliza ?? $poliza->numero_poliza,
                'tipo' => $request->tipo ?? $poliza->tipo,
                'fecha_inicio' => $request->fecha_inicio ?? $poliza->fecha_inicio,
                'fecha_fin' => $request->fecha_fin ?? $poliza->fecha_fin,
                'suma_asegurada' => $request->suma_asegurada ?? $poliza->suma_asegurada,
                'prima_neta' => $request->prima_neta ?? $poliza->prima_neta,
                'derecho_poliza' => $request->derecho_poliza ?? $poliza->derecho_poliza,
                'iva' => $request->iva ?? $poliza->iva,
                'prima_total' => $request->prima_total ?? $poliza->prima_total,
                'forma_pago' => $request->forma_pago ?? $poliza->forma_pago,
                'observaciones' => $request->observaciones ?? $poliza->observaciones,
                'estatus' => $request->estatus ?? $poliza->estatus,
            ]);
            
            // Actualizar bienes si se enviaron
            $bienesIds = $request->get('bienes_ids', []);
            $preciosBienes = $request->get('precios_bienes', []);
            
            if (!empty($bienesIds)) {
                Bienes::where('id_poliza', $id)
                    ->whereNotIn('id', $bienesIds)
                    ->update(['id_poliza' => null]);
                
                foreach ($bienesIds as $bienId) {
                    $updateData = ['id_poliza' => $id];
                    if (isset($preciosBienes[$bienId])) {
                        $updateData['valor'] = floatval($preciosBienes[$bienId]);
                    }
                    Bienes::where('id', $bienId)->update($updateData);
                }
            }
            
            return response()->json(['message' => 'Póliza actualizada exitosamente', 'poliza' => $poliza]);
            
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
                
            Bienes::where('id_poliza', $id)->update(['id_poliza' => null]);
            $poliza->delete();
            
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
            
            // Verificar que la póliza pertenece a la aseguradora
            $poliza = Poliza::where('id', $id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();
            
            $bienesIds = $request->get('bienes_ids', []);
            $preciosBienes = $request->get('precios_bienes', []);
            
            Bienes::where('id_poliza', $id)
                ->whereNotIn('id', $bienesIds)
                ->update(['id_poliza' => null]);
            
            foreach ($bienesIds as $bienId) {
                $updateData = ['id_poliza' => $id];
                if (isset($preciosBienes[$bienId])) {
                    $updateData['valor'] = floatval($preciosBienes[$bienId]);
                }
                Bienes::where('id', $bienId)->update($updateData);
            }
            
            return response()->json(['message' => 'Bienes asignados correctamente']);
        } catch (\Exception $e) {
            Log::error('Error en asignarBienes: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
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