<?php
// app/Http/Controllers/RenovacionController.php

namespace App\Http\Controllers;

use App\Models\RenovacionPoliza;
use App\Models\Poliza;
use App\Models\Bienes;
use App\Models\Empleado;
use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class RenovacionController extends Controller
{
    /**
     * Obtener lista de pólizas próximas a vencer o que pueden renovarse
     */
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $perPage = $request->get('per_page', 8);
            $search = $request->get('search', '');
            $estatusFilter = $request->get('estatus', 'Todos');
            
            // Obtener todas las pólizas activas y vencidas
            $query = Poliza::select(
                    'polizas.*',
                    'clientes.nombre',
                    'clientes.apellidos',
                    'clientes.correo as cliente_correo',
                    'clientes.numero_de_telefono as cliente_telefono'
                )
                ->leftJoin('clientes', 'polizas.id_cliente', '=', 'clientes.id')
                ->where('polizas.id_aseguradora', $user->id_aseguradora)
                ->whereIn('polizas.estatus', ['activa', 'vencida']);
            
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('polizas.numero_poliza', 'LIKE', "%{$search}%")
                      ->orWhere('clientes.nombre', 'LIKE', "%{$search}%")
                      ->orWhere('clientes.apellidos', 'LIKE', "%{$search}%");
                });
            }
            
            $polizas = $query->orderBy('polizas.fecha_fin', 'asc')->get();
            
            // Filtrar y procesar en PHP
            $filteredPolizas = [];
            foreach ($polizas as $poliza) {
                $fechaFin = new \DateTime($poliza->fecha_fin);
                $ahora = new \DateTime();
                $diff = $ahora->diff($fechaFin);
                
                if ($ahora > $fechaFin) {
                    $diasRestantes = -$diff->days;
                } else {
                    $diasRestantes = $diff->days;
                }
                
                // Determinar estado de renovación
                if ($diasRestantes <= 0) {
                    $estadoRenovacion = 'Vencida';
                } elseif ($diasRestantes <= 7) {
                    $estadoRenovacion = 'Urgente';
                } elseif ($diasRestantes <= 60) {
                    $estadoRenovacion = 'Por vencer';
                } else {
                    continue;
                }
                
                // Aplicar filtro por estatus
                if ($estatusFilter !== 'Todos' && $estadoRenovacion !== $estatusFilter) {
                    continue;
                }
                
                $poliza->estado_renovacion = $estadoRenovacion;
                $poliza->dias_restantes = $diasRestantes;
                $filteredPolizas[] = $poliza;
            }
            
            // Paginación manual
            $total = count($filteredPolizas);
            $currentPage = (int)$request->get('page', 1);
            $offset = ($perPage * ($currentPage - 1));
            $paginated = array_slice($filteredPolizas, $offset, $perPage);
            
            // Transformar datos
            $data = [];
            foreach ($paginated as $poliza) {
                $tieneRenovacion = RenovacionPoliza::where('id_poliza_anterior', $poliza->id)->exists();
                
                $nombreAsesor = 'No asignado';
                if ($poliza->id_empleado) {
                    $empleado = Empleado::find($poliza->id_empleado);
                    if ($empleado) {
                        $nombreAsesor = $empleado->nombre . ' ' . ($empleado->apellidos ?? '');
                    }
                }
                
                $dias = $poliza->dias_restantes;
                if ($dias <= 0) {
                    $vigencia = 'Vencida';
                } elseif ($dias <= 7) {
                    $vigencia = $dias . ' días';
                } elseif ($dias <= 30) {
                    $vigencia = round($dias / 7) . ' semanas';
                } elseif ($dias <= 365) {
                    $vigencia = round($dias / 30) . ' meses';
                } else {
                    $vigencia = round($dias / 365) . ' años';
                }
                
                $data[] = [
                    'id' => $poliza->id,
                    'nPoliza' => $poliza->numero_poliza,
                    'nombre' => $poliza->nombre ?? '',
                    'apellido' => $poliza->apellidos ?? '',
                    'tipo' => $poliza->tipo,
                    'vigencia' => $vigencia,
                    'vence' => $poliza->fecha_fin,
                    'asesor' => $nombreAsesor,
                    'estatus' => $poliza->estado_renovacion,
                    'correo' => $poliza->cliente_correo ?? '',
                    'telefono' => $poliza->cliente_telefono ?? '',
                    'tieneRenovacion' => $tieneRenovacion,
                    'suma_asegurada' => $poliza->suma_asegurada,
                    'prima_total' => $poliza->prima_total
                ];
            }
            
            return response()->json([
                'data' => $data,
                'current_page' => $currentPage,
                'last_page' => ceil($total / $perPage),
                'per_page' => $perPage,
                'total' => $total,
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en index renovaciones: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Obtener detalles de una póliza para renovación (incluye bienes)
     */
    public function getDetalleParaRenovar($id)
    {
        try {
            $user = auth()->user();
            
            $poliza = Poliza::with('cliente')
                ->where('id', $id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();
            
            // Obtener bienes de la póliza
            $bienes = Bienes::where('id_poliza', $id)
                ->select('id', 'tipo', 'descripcion', 'marca', 'modelo', 'valor', 'numero_serie')
                ->get();
            
            // Calcular nueva prima (incremento del 5%)
            $incremento = 1.05;
            $nuevaPrimaNeta = round($poliza->prima_neta * $incremento, 2);
            $nuevoDerecho = round($poliza->derecho_poliza * $incremento, 2);
            $nuevoIva = round(($nuevaPrimaNeta + $nuevoDerecho) * 0.16, 2);
            $nuevaPrimaTotal = round($nuevaPrimaNeta + $nuevoDerecho + $nuevoIva, 2);
            
            return response()->json([
                'id' => $poliza->id,
                'numero_poliza' => $poliza->numero_poliza,
                'cliente_nombre' => $poliza->cliente ? $poliza->cliente->nombre : '',
                'cliente_apellido' => $poliza->cliente ? $poliza->cliente->apellidos : '',
                'tipo' => $poliza->tipo,
                'estatus_actual' => $poliza->estatus,
                'fecha_inicio_actual' => $poliza->fecha_inicio,
                'fecha_fin_actual' => $poliza->fecha_fin,
                'suma_asegurada_actual' => $poliza->suma_asegurada,
                'prima_total_actual' => $poliza->prima_total,
                'forma_pago_actual' => $poliza->forma_pago,
                'bienes' => $bienes,
                'sugerencia' => [
                    'fecha_inicio' => date('Y-m-d'),
                    'fecha_fin' => date('Y-m-d', strtotime('+1 year')),
                    'suma_asegurada' => $poliza->suma_asegurada,
                    'prima_neta' => $nuevaPrimaNeta,
                    'derecho_poliza' => $nuevoDerecho,
                    'iva' => $nuevoIva,
                    'prima_total' => $nuevaPrimaTotal,
                    'forma_pago' => $poliza->forma_pago
                ]
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en getDetalleParaRenovar: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Crear una renovación de póliza
     */
    public function store(Request $request)
    {
        try {
            $user = auth()->user();
            
            $validator = Validator::make($request->all(), [
                'id_poliza_anterior' => 'required|exists:polizas,id',
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
                return response()->json(['errors' => $validator->errors()], 422);
            }
            
            // Verificar que la póliza pertenece a la aseguradora
            $polizaAnterior = Poliza::where('id', $request->id_poliza_anterior)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();
            
            // Obtener empleado
            $empleado = Empleado::where('id_aseguradora', $user->id_aseguradora)->first();
            
            if (!$empleado) {
                return response()->json(['message' => 'No hay empleados registrados'], 422);
            }
            
            // Calcular cambios
            $cambios = [
                'prima_neta_anterior' => $polizaAnterior->prima_neta,
                'prima_neta_nueva' => $request->prima_neta,
                'derecho_poliza_anterior' => $polizaAnterior->derecho_poliza,
                'derecho_poliza_nuevo' => $request->derecho_poliza,
                'iva_anterior' => $polizaAnterior->iva,
                'iva_nuevo' => $request->iva,
                'prima_total_anterior' => $polizaAnterior->prima_total,
                'prima_total_nueva' => $request->prima_total,
                'incremento_porcentaje' => round((($request->prima_neta / $polizaAnterior->prima_neta) - 1) * 100, 2),
                'fecha_renovacion' => now()->format('Y-m-d H:i:s')
            ];
            
            $renovacion = RenovacionPoliza::create([
                'id_poliza_anterior' => $request->id_poliza_anterior,
                'id_empleado' => $empleado->id,
                'fecha_inicio' => $request->fecha_inicio,
                'fecha_fin' => $request->fecha_fin,
                'suma_asegurada' => $request->suma_asegurada,
                'prima_neta' => $request->prima_neta,
                'derecho_poliza' => $request->derecho_poliza,
                'iva' => $request->iva,
                'prima_total' => $request->prima_total,
                'forma_pago' => $request->forma_pago,
                'estatus' => 'activa',
                'fecha_renovacion' => now(),
                'cambios' => json_encode($cambios),
                'observaciones' => $request->observaciones
            ]);
            
            // Generar nuevo número de póliza para la renovación
            $nuevoNumeroPoliza = $this->generarNuevoNumeroPoliza($polizaAnterior->numero_poliza);
            
            // Crear la nueva póliza con el nuevo número
            $nuevaPoliza = Poliza::create([
                'id_aseguradora' => $user->id_aseguradora,
                'id_cliente' => $polizaAnterior->id_cliente,
                'id_empleado' => $empleado->id,
                'numero_poliza' => $nuevoNumeroPoliza,
                'tipo' => $polizaAnterior->tipo,
                'estatus' => 'activa',
                'fecha_emision' => now(),
                'fecha_inicio' => $request->fecha_inicio,
                'fecha_fin' => $request->fecha_fin,
                'suma_asegurada' => $request->suma_asegurada,
                'prima_neta' => $request->prima_neta,
                'derecho_poliza' => $request->derecho_poliza,
                'iva' => $request->iva,
                'prima_total' => $request->prima_total,
                'forma_pago' => $request->forma_pago,
                'observaciones' => "Renovación de póliza anterior: " . $polizaAnterior->numero_poliza
            ]);
            
            // Transferir bienes a la nueva póliza
            Bienes::where('id_poliza', $request->id_poliza_anterior)
                ->update(['id_poliza' => $nuevaPoliza->id]);
            
            // IMPORTANTE: Cambiar estatus de la póliza anterior a 'cancelada' (válido en el enum)
            // Los valores válidos son: activa, vencida, cancelada, suspendida, en_renovacion
            $polizaAnterior->update(['estatus' => 'cancelada']);
            
            return response()->json([
                'message' => 'Renovación creada exitosamente',
                'renovacion' => $renovacion,
                'nueva_poliza' => $nuevaPoliza,
                'nuevo_numero_poliza' => $nuevoNumeroPoliza
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Error en store renovacion: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
    
    /**
     * Obtener historial de renovaciones de una póliza
     */
    public function getHistorial($polizaId)
    {
        try {
            $user = auth()->user();
            
            $renovaciones = RenovacionPoliza::with(['polizaAnterior', 'empleado'])
                ->whereHas('polizaAnterior', function($query) use ($user) {
                    $query->where('id_aseguradora', $user->id_aseguradora);
                })
                ->where('id_poliza_anterior', $polizaId)
                ->orderBy('fecha_renovacion', 'desc')
                ->get();
            
            return response()->json($renovaciones);
            
        } catch (\Exception $e) {
            Log::error('Error en getHistorial: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
    
    private function generarNuevoNumeroPoliza($numeroAnterior)
    {
        // Limpiar el número anterior (eliminar -R y año si existe)
        $baseNumero = preg_replace('/-R\d{4}$/', '', $numeroAnterior);
        
        // Generar nuevo número con el formato: NUMERO-R+AÑO
        return $baseNumero . '-R' . date('Y');
    }
}