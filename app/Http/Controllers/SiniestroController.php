<?php

namespace App\Http\Controllers;

use App\Models\Siniestro;
use App\Models\Reportes;  // IMPORTANTE: Reportes con 's'
use App\Models\Poliza;
use App\Models\Cliente;
use App\Models\Empleado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class SiniestroController extends Controller
{
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $perPage = $request->get('per_page', 5);
            $search = $request->get('search', '');
            $estatus = $request->get('estatus', 'Todos');
            
            $query = Siniestro::select(
                    'siniestros.*',
                    'reportes.id_poliza',
                    'clientes.nombre',
                    'clientes.apellidos',
                    'clientes.id as cliente_id'
                )
                ->leftJoin('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
                ->leftJoin('polizas', 'reportes.id_poliza', '=', 'polizas.id')
                ->leftJoin('clientes', 'polizas.id_cliente', '=', 'clientes.id')
                ->where('siniestros.id_aseguradora', $user->id_aseguradora); // Filtro por aseguradora
            
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('siniestros.numero_siniestro', 'LIKE', "%{$search}%")
                      ->orWhere('clientes.nombre', 'LIKE', "%{$search}%")
                      ->orWhere('clientes.apellidos', 'LIKE', "%{$search}%")
                      ->orWhere('siniestros.tipo', 'LIKE', "%{$search}%");
                });
            }
            
            if ($estatus !== 'Todos') {
                $query->where('siniestros.estatus', $estatus);
            }
            
            $siniestros = $query->orderBy('siniestros.id', 'desc')->paginate($perPage);

            $data = [];
            foreach ($siniestros as $siniestro) {
                $data[] = [
                    'id' => $siniestro->id,
                    'nSiniestro' => $siniestro->numero_siniestro,
                    'tipo' => $this->traducirTipo($siniestro->tipo),
                    'cliente' => ($siniestro->nombre ? $siniestro->nombre . ' ' . $siniestro->apellidos : 'Sin cliente'),
                    'estatus' => $this->traducirEstatus($siniestro->estatus),
                    'prioridad' => $this->traducirPrioridad($siniestro->prioridad),
                    'monto_reclamado' => $siniestro->monto_reclamado,
                    'fecha_creacion' => $siniestro->fecha_creacion ? date('d-m-Y', strtotime($siniestro->fecha_creacion)) : '',
                    'id_cliente' => $siniestro->cliente_id,
                    'id_poliza' => $siniestro->id_poliza,
                    'id_reporte' => $siniestro->id_reporte
                ];
            }

            return response()->json([
                'data' => $data,
                'current_page' => $siniestros->currentPage(),
                'last_page' => $siniestros->lastPage(),
                'per_page' => $siniestros->perPage(),
                'total' => $siniestros->total(),
            ]);

        } catch (\Exception $e) {
            Log::error('Error en index siniestros: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function getPolizasPorRfc(Request $request)
    {
        try {
            $user = auth()->user();
            $request->validate(['rfc' => 'required|string|max:13']);
            
            $cliente = Cliente::where('rfc', $request->rfc)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->first();
            
            if (!$cliente) {
                return response()->json(['message' => 'Cliente no encontrado'], 404);
            }
            
            $polizas = Poliza::where('id_cliente', $cliente->id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->select('id', 'numero_poliza', 'tipo')
                ->get();
            
            return response()->json([
                'cliente' => [
                    'id' => $cliente->id,
                    'nombre' => $cliente->nombre,
                    'apellido' => $cliente->apellidos
                ],
                'polizas' => $polizas
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en getPolizasPorRfc: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function getReportesPorPoliza($polizaId)
    {
        try {
            $user = auth()->user();
            
            // Verificar que la póliza pertenece a la aseguradora
            $poliza = Poliza::where('id', $polizaId)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();
            
            // Mostrar TODOS los reportes de la póliza, sin filtrar por id_siniestro
            $reportes = Reportes::where('id_poliza', $polizaId)
                ->select('id', 'fecha_reporte', 'descripcion', 'estado', 'id_siniestro')
                ->get();
            
            $reportesFormateados = $reportes->map(function($reporte) {
                $fecha = date('d-m-Y', strtotime($reporte->fecha_reporte));
                $tieneSiniestro = $reporte->id_siniestro && $reporte->id_siniestro > 0;
                
                return [
                    'id' => $reporte->id,
                    'numero_reporte' => 'REP-' . str_pad($reporte->id, 6, '0', STR_PAD_LEFT),
                    'fecha_reporte' => $fecha,
                    'descripcion' => $reporte->descripcion,
                    'estado' => $reporte->estado,
                    'tiene_siniestro' => $tieneSiniestro
                ];
            });
            
            return response()->json($reportesFormateados);
            
        } catch (\Exception $e) {
            Log::error('Error en getReportesPorPoliza: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function getDetalleCompleto($id)
    {
        try {
            $user = auth()->user();
            
            $siniestro = Siniestro::where('id', $id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();
            
            $reporte = null;
            $poliza = null;
            $cliente = null;
            
            if ($siniestro->id_reporte) {
                $reporte = Reportes::find($siniestro->id_reporte);
                if ($reporte && $reporte->id_poliza) {
                    $poliza = Poliza::where('id', $reporte->id_poliza)
                        ->where('id_aseguradora', $user->id_aseguradora)
                        ->first();
                    if ($poliza && $poliza->id_cliente) {
                        $cliente = Cliente::where('id', $poliza->id_cliente)
                            ->where('id_aseguradora', $user->id_aseguradora)
                            ->first();
                    }
                }
            }
            
            return response()->json([
                'id' => $siniestro->id,
                'numero_siniestro' => $siniestro->numero_siniestro,
                'tipo' => $this->traducirTipo($siniestro->tipo),
                'estatus' => $this->traducirEstatus($siniestro->estatus),
                'prioridad' => $this->traducirPrioridad($siniestro->prioridad),
                'monto_reclamado' => $siniestro->monto_reclamado,
                'deducible_aplicado' => $siniestro->deducible_aplicado,
                'fecha_creacion' => $siniestro->fecha_creacion ? date('d-m-Y H:i', strtotime($siniestro->fecha_creacion)) : '',
                'fecha_cierre' => $siniestro->fecha_cierre ? date('d-m-Y H:i', strtotime($siniestro->fecha_cierre)) : '',
                'motivo_cierre' => $siniestro->motivo_cierre,
                'cliente' => $cliente ? $cliente->nombre . ' ' . $cliente->apellidos : 'Sin cliente',
                'cliente_id' => $cliente ? $cliente->id : null,
                'poliza' => $poliza ? $poliza->numero_poliza : 'Sin póliza',
                'poliza_id' => $poliza ? $poliza->id : null,
                'id_reporte' => $siniestro->id_reporte
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en getDetalleCompleto: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = auth()->user();
            
            $validator = Validator::make($request->all(), [
                'id_reporte' => 'required|exists:reportes,id',
                'tipo' => 'required|string',
                'monto_reclamado' => 'required|numeric|min:0',
                'prioridad' => 'required|string'
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $reporte = Reportes::find($request->id_reporte);
            
            // Verificar que el reporte pertenece a una póliza de la aseguradora
            $poliza = Poliza::where('id', $reporte->id_poliza)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();
            
            // Verificar si el reporte ya tiene un siniestro
            if ($reporte->id_siniestro && $reporte->id_siniestro > 0) {
                return response()->json([
                    'message' => 'Este reporte ya tiene un siniestro asociado (ID: ' . $reporte->id_siniestro . ')'
                ], 422);
            }
            
            // Obtener un empleado válido de la aseguradora
            $empleado = Empleado::where('id_aseguradora', $user->id_aseguradora)->first();
            
            if (!$empleado) {
                return response()->json(['message' => 'No hay empleados registrados para esta aseguradora'], 422);
            }
            
            $year = date('Y');
            $lastSiniestro = Siniestro::whereYear('fecha_creacion', $year)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->count();
            $numeroSiniestro = 'SIN-' . $year . '-' . str_pad($lastSiniestro + 1, 6, '0', STR_PAD_LEFT);
            
            $siniestro = Siniestro::create([
                'id_aseguradora' => $user->id_aseguradora,
                'id_reporte' => $request->id_reporte,
                'id_empleado' => $empleado->id,
                'numero_siniestro' => $numeroSiniestro,
                'tipo' => $request->tipo,
                'estatus' => 'abierto',
                'prioridad' => $request->prioridad,
                'monto_reclamado' => $request->monto_reclamado,
                'deducible_aplicado' => $request->deducible_aplicado,
                'fecha_creacion' => now(),
                'fecha_actualizacion' => now()
            ]);

            // Actualizar el reporte con el id_siniestro
            $reporte->update(['id_siniestro' => $siniestro->id]);

            return response()->json([
                'message' => 'Siniestro creado exitosamente',
                'siniestro' => $siniestro
            ], 201);
            
        } catch (\Exception $e) {
            Log::error('Error en store siniestro: ' . $e->getMessage());
            return response()->json(['message' => 'Error al crear siniestro: ' . $e->getMessage()], 500);
        }
    }

    public function update(Request $request, $id)
    {
        try {
            $user = auth()->user();
            
            $siniestro = Siniestro::where('id', $id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();
            
            $siniestro->update([
                'tipo' => $request->tipo ?? $siniestro->tipo,
                'prioridad' => $request->prioridad ?? $siniestro->prioridad,
                'estatus' => $request->estatus ?? $siniestro->estatus,
                'monto_reclamado' => $request->monto_reclamado ?? $siniestro->monto_reclamado,
                'deducible_aplicado' => $request->deducible_aplicado,
                'fecha_actualizacion' => now()
            ]);
            
            if ($request->estatus === 'cerrado' && !$siniestro->fecha_cierre) {
                $siniestro->update([
                    'fecha_cierre' => now(),
                    'motivo_cierre' => $request->motivo_cierre
                ]);
            }
            
            return response()->json([
                'message' => 'Siniestro actualizado exitosamente',
                'siniestro' => $siniestro
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en update siniestro: ' . $e->getMessage());
            return response()->json(['message' => 'Error al actualizar siniestro'], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $user = auth()->user();
            
            $siniestro = Siniestro::where('id', $id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->firstOrFail();
            
            Reportes::where('id_siniestro', $id)->update(['id_siniestro' => null]);
            
            $siniestro->delete();
            
            return response()->json(['message' => 'Siniestro eliminado exitosamente']);
            
        } catch (\Exception $e) {
            Log::error('Error en destroy siniestro: ' . $e->getMessage());
            return response()->json(['message' => 'Error al eliminar siniestro'], 500);
        }
    }

    public function getReportesPorRfc(Request $request)
    {
        try {
            $user = auth()->user();
            $request->validate(['rfc' => 'required|string|max:13']);
            
            $cliente = Cliente::where('rfc', $request->rfc)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->first();
            
            if (!$cliente) {
                return response()->json(['message' => 'Cliente no encontrado'], 404);
            }
            
            $polizasIds = Poliza::where('id_cliente', $cliente->id)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->pluck('id');
            
            if ($polizasIds->isEmpty()) {
                return response()->json([
                    'cliente' => [
                        'id' => $cliente->id,
                        'nombre' => $cliente->nombre,
                        'apellido' => $cliente->apellidos
                    ],
                    'polizas' => [],
                    'reportes' => []
                ]);
            }
            
            $reportes = Reportes::whereIn('id_poliza', $polizasIds)
                ->where(function($query) {
                    $query->whereNull('id_siniestro')
                          ->orWhere('id_siniestro', 0);
                })
                ->get();
            
            $polizas = Poliza::whereIn('id', $polizasIds)
                ->where('id_aseguradora', $user->id_aseguradora)
                ->get();
            
            $reportesFormateados = $reportes->map(function($reporte) use ($polizas) {
                $poliza = $polizas->firstWhere('id', $reporte->id_poliza);
                return [
                    'id' => $reporte->id,
                    'numero_reporte' => 'REP-' . str_pad($reporte->id, 6, '0', STR_PAD_LEFT),
                    'fecha_reporte' => date('d-m-Y', strtotime($reporte->fecha_reporte)),
                    'descripcion' => $reporte->descripcion,
                    'estado' => $reporte->estado,
                    'id_poliza' => $reporte->id_poliza,
                    'numero_poliza' => $poliza ? $poliza->numero_poliza : 'N/A'
                ];
            });
            
            return response()->json([
                'cliente' => [
                    'id' => $cliente->id,
                    'nombre' => $cliente->nombre,
                    'apellido' => $cliente->apellidos
                ],
                'polizas' => $polizas,
                'reportes' => $reportesFormateados
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en getReportesPorRfc: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }

    private function traducirTipo($tipo)
    {
        $map = [
            'robo' => 'Robo',
            'accidente' => 'Accidente',
            'incendio' => 'Incendio',
            'inundacion' => 'Inundación',
            'responsabilidad_civil' => 'Responsabilidad Civil',
            'fallecimiento' => 'Fallecimiento',
            'invalidez' => 'Invalidez',
            'otro' => 'Otro'
        ];
        return $map[$tipo] ?? $tipo;
    }

    private function traducirEstatus($estatus)
    {
        $map = [
            'abierto' => 'Abierto',
            'en_proceso' => 'En proceso',
            'en_evaluacion' => 'En evaluación',
            'resuelto' => 'Resuelto',
            'cerrado' => 'Cerrado',
            'rechazado' => 'Rechazado'
        ];
        return $map[$estatus] ?? $estatus;
    }

    private function traducirPrioridad($prioridad)
    {
        $map = [
            'baja' => 'Baja',
            'media' => 'Media',
            'alta' => 'Alta',
            'urgente' => 'Urgente'
        ];
        return $map[$prioridad] ?? $prioridad;
    }
}