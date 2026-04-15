<?php
// app/Http/Controllers/EvaluacionController.php

namespace App\Http\Controllers;

use App\Models\Evaluacion;
use App\Models\Siniestro;
use App\Models\Poliza;
use App\Models\Cliente;
use App\Models\Empleado;
use App\Models\Indemnizaciones;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class EvaluacionController extends Controller
{
    /**
     * Obtener lista de evaluaciones
     */
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            $perPage = $request->get('per_page', 5);
            $search = $request->get('search', '');
            $estatusFilter = $request->get('estatus', 'Todos');
            
            $query = Evaluacion::select(
                    'evaluaciones.*',
                    'siniestros.numero_siniestro',
                    'siniestros.tipo as tipo_siniestro',
                    'siniestros.monto_reclamado',
                    'siniestros.prioridad',
                    'clientes.nombre',
                    'clientes.apellidos',
                    'empleados.nombre as empleado_nombre',
                    'empleados.apellidos as empleado_apellidos'
                )
                ->leftJoin('siniestros', 'evaluaciones.id_siniestro', '=', 'siniestros.id')
                ->leftJoin('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
                ->leftJoin('polizas', 'reportes.id_poliza', '=', 'polizas.id')
                ->leftJoin('clientes', 'polizas.id_cliente', '=', 'clientes.id')
                ->leftJoin('empleados', 'evaluaciones.id_empleado', '=', 'empleados.id')
                ->where('polizas.id_aseguradora', $user->id_aseguradora);
            
            if (!empty($search)) {
                $query->where(function($q) use ($search) {
                    $q->where('siniestros.numero_siniestro', 'LIKE', "%{$search}%")
                      ->orWhere('clientes.nombre', 'LIKE', "%{$search}%")
                      ->orWhere('clientes.apellidos', 'LIKE', "%{$search}%")
                      ->orWhere('evaluaciones.dictamen', 'LIKE', "%{$search}%");
                });
            }
            
            if ($estatusFilter !== 'Todos') {
                $query->where('evaluaciones.estatus', $estatusFilter);
            }
            
            $evaluaciones = $query->orderBy('evaluaciones.id', 'desc')->paginate($perPage);
            
            $data = [];
            foreach ($evaluaciones as $eval) {
                // Obtener indemnización si existe
                $indemnizacion = Indemnizaciones::where('id_evaluaciones', $eval->id)->first();
                
                $data[] = [
                    'id' => $eval->id,
                    'nSiniestro' => $eval->numero_siniestro,
                    'dictamen' => $eval->dictamen,
                    'montoRecomendado' => '$' . number_format($eval->monto_recomendado, 2),
                    'ajustador' => ($eval->empleado_nombre ? $eval->empleado_nombre . ' ' . $eval->empleado_apellidos : 'No asignado'),
                    'valorResidual' => '$' . number_format($eval->valor_residual, 2),
                    'fechaInspeccion' => $eval->fecha_inspeccion ? $eval->fecha_inspeccion->format('d-m-Y') : '',
                    'prioridad' => $this->traducirPrioridad($eval->prioridad),
                    'estatus' => $this->traducirEstatus($eval->estatus),
                    'nEvaluacion' => 'EV-' . str_pad($eval->id, 6, '0', STR_PAD_LEFT),
                    // Datos adicionales para PDF
                    'aseguradora' => 'Aseguradora BalamCore',
                    'nPoliza' => $this->obtenerNumeroPoliza($eval->id_siniestro),
                    'fechaEmision' => $eval->fecha_dictamen ? $eval->fecha_dictamen->format('d \\d\\e F \\d\\e Y') : '',
                    'nombreAsegurado' => ($eval->nombre ?? '') . ' ' . ($eval->apellidos ?? ''),
                    'rfc' => $this->obtenerRfcCliente($eval->id_siniestro),
                    'domicilio' => $this->obtenerDomicilioCliente($eval->id_siniestro),
                    'telefono' => $this->obtenerTelefonoCliente($eval->id_siniestro),
                    'correo' => $this->obtenerCorreoCliente($eval->id_siniestro),
                    'tipoSiniestro' => $eval->tipo_siniestro,
                    'fechaOcurrencia' => $this->obtenerFechaOcurrencia($eval->id_siniestro),
                    'ubicacion' => $this->obtenerUbicacion($eval->id_siniestro),
                    'descripcion' => $this->obtenerDescripcion($eval->id_siniestro),
                    'montoReclamadoPDF' => '$' . number_format($eval->monto_reclamado, 2),
                    'montoAprobadoPDF' => '$' . number_format($eval->monto_recomendado, 2),
                    'deducible' => $this->obtenerDeducible($eval->id_siniestro),
                    'montoTotal' => $this->convertirLetras($eval->monto_recomendado),
                    'causas' => $eval->causas,
                    'recomendacion' => $eval->recomendacion,
                    'costoReparacion' => '$' . number_format($eval->costo_reparacion, 2),
                    'fechaDictamen' => $eval->fecha_dictamen ? $eval->fecha_dictamen->format('d-m-Y') : '',
                    'reporte' => $this->obtenerNumeroReporte($eval->id_siniestro),
                    'empleadoAjustador' => ($eval->empleado_nombre ?? '') . ' ' . ($eval->empleado_apellidos ?? ''),
                    'formaPago' => $indemnizacion ? $indemnizacion->forma_pago : 'No especificado',
                    'banco' => $indemnizacion ? $indemnizacion->banco : 'No especificado',
                    'titularBanco' => $indemnizacion ? $indemnizacion->titular_cuenta : 'No especificado',
                    'cuentaBancaria' => $indemnizacion ? $indemnizacion->cuenta_bancaria : 'No especificado',
                    'fechaAutorizacion' => $indemnizacion ? $indemnizacion->fecha_autorizacion : '',
                    'fechaPagoPDF' => $indemnizacion ? $indemnizacion->fecha_pago : '',
                    'referencia' => $indemnizacion ? $indemnizacion->id : 'No disponible',
                    'estatusPDF' => $indemnizacion ? 'Pagada' : 'Pendiente'
                ];
            }
            
            return response()->json([
                'data' => $data,
                'current_page' => $evaluaciones->currentPage(),
                'last_page' => $evaluaciones->lastPage(),
                'per_page' => $evaluaciones->perPage(),
                'total' => $evaluaciones->total(),
            ]);
            
        } catch (\Exception $e) {
            Log::error('Error en index evaluaciones: ' . $e->getMessage());
            return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
        }
    }
    
    public function show($id)
{
    try {
        $user = auth()->user();
        
        $evaluacion = Evaluacion::with(['siniestro.reporte.poliza.cliente', 'empleado'])
            ->where('id', $id)
            ->firstOrFail();
        
        // Verificar que pertenece a la aseguradora
        if ($evaluacion->siniestro->reporte->poliza->id_aseguradora !== $user->id_aseguradora) {
            return response()->json(['message' => 'No autorizado'], 403);
        }
        
        // Obtener indemnización si existe
        $indemnizacion = Indemnizaciones::where('id_evaluaciones', $id)->first();
        
        // Obtener datos del cliente
        $cliente = $evaluacion->siniestro->reporte->poliza->cliente;
        
        // Formatear fechas
        $fechaInspeccion = $evaluacion->fecha_inspeccion ? date('d/m/Y', strtotime($evaluacion->fecha_inspeccion)) : '';
        $fechaDictamen = $evaluacion->fecha_dictamen ? date('d/m/Y', strtotime($evaluacion->fecha_dictamen)) : '';
        $fechaOcurrencia = $evaluacion->siniestro->reporte->fecha_ocurrencia ? date('d/m/Y', strtotime($evaluacion->siniestro->reporte->fecha_ocurrencia)) : '';
        
        $data = [
            'id' => $evaluacion->id,
            'nEvaluacion' => 'EV-' . str_pad($evaluacion->id, 6, '0', STR_PAD_LEFT),
            'nSiniestro' => $evaluacion->siniestro->numero_siniestro,
            'dictamen' => $evaluacion->dictamen,
            'montoRecomendado' => '$' . number_format($evaluacion->monto_recomendado, 2),
            'ajustador' => $evaluacion->empleado ? $evaluacion->empleado->nombre . ' ' . $evaluacion->empleado->apellidos : 'No asignado',
            'valorResidual' => '$' . number_format($evaluacion->valor_residual, 2),
            'fechaInspeccion' => $fechaInspeccion,
            'prioridad' => $this->traducirPrioridad($evaluacion->siniestro->prioridad),
            'estatus' => $this->traducirEstatus($evaluacion->estatus),
            // Datos para el PDF
            'aseguradora' => 'Aseguradora BalamCore',
            'nPoliza' => $evaluacion->siniestro->reporte->poliza->numero_poliza,
            'fechaEmision' => $fechaDictamen ?: date('d/m/Y'),
            'nombreAsegurado' => $cliente ? $cliente->nombre . ' ' . $cliente->apellidos : 'No disponible',
            'rfc' => $cliente ? $cliente->rfc : 'No disponible',
            'domicilio' => $cliente ? $cliente->domicilio : 'No disponible',
            'telefono' => $cliente ? $cliente->numero_de_telefono : 'No disponible',
            'correo' => $cliente ? $cliente->correo : 'No disponible',
            'tipoSiniestro' => $evaluacion->siniestro->tipo,
            'fechaOcurrencia' => $fechaOcurrencia,
            'ubicacion' => $evaluacion->siniestro->reporte->ubicacion_accidente ?? 'No especificada',
            'descripcion' => $evaluacion->siniestro->reporte->descripcion ?? 'Sin descripción',
            'montoReclamadoPDF' => '$' . number_format($evaluacion->siniestro->monto_reclamado, 2),
            'montoAprobadoPDF' => '$' . number_format($evaluacion->monto_recomendado, 2),
            'deducible' => '$' . number_format($evaluacion->siniestro->deducible_aplicado ?? 0, 2),
            'montoTotal' => $this->convertirLetras($evaluacion->monto_recomendado),
            'causas' => $evaluacion->causas,
            'recomendacion' => $evaluacion->recomendacion,
            'costoReparacion' => '$' . number_format($evaluacion->costo_reparacion, 2),
            'fechaDictamen' => $fechaDictamen,
            'reporte' => 'REP-' . str_pad($evaluacion->siniestro->id_reporte, 6, '0', STR_PAD_LEFT),
            'empleadoAjustador' => $evaluacion->empleado ? $evaluacion->empleado->nombre . ' ' . $evaluacion->empleado->apellidos : 'No asignado',
            'nEvaluacionPDF' => 'EV-' . str_pad($evaluacion->id, 6, '0', STR_PAD_LEFT),
            'ajustadorPDF' => $evaluacion->empleado ? $evaluacion->empleado->nombre . ' ' . $evaluacion->empleado->apellidos : 'No asignado',
            'fechaEvaluacion' => $fechaDictamen,
            'resultadoEval' => $evaluacion->dictamen,
            'prioridadPDF' => $this->traducirPrioridad($evaluacion->siniestro->prioridad),
            'valorResidualPDF' => '$' . number_format($evaluacion->valor_residual, 2),
            'estatusPDF' => $indemnizacion ? 'Pagada' : 'Pendiente',
            'formaPago' => $indemnizacion ? $indemnizacion->forma_pago : 'No especificado',
            'banco' => $indemnizacion ? $indemnizacion->banco : 'No especificado',
            'titularBanco' => $indemnizacion ? $indemnizacion->titular_cuenta : 'No especificado',
            'cuentaBancaria' => $indemnizacion ? $indemnizacion->cuenta_bancaria : 'No especificado',
            'fechaAutorizacion' => $indemnizacion ? $indemnizacion->fecha_autorizacion : '',
            'fechaPagoPDF' => $indemnizacion ? $indemnizacion->fecha_pago : '',
            'referencia' => $indemnizacion ? $indemnizacion->id : 'No disponible'
        ];
        
        return response()->json($data);
        
    } catch (\Exception $e) {
        Log::error('Error en show evaluacion: ' . $e->getMessage());
        Log::error('Stack trace: ' . $e->getTraceAsString());
        return response()->json(['message' => 'Error: ' . $e->getMessage()], 500);
    }
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
    
    private function traducirEstatus($estatus)
    {
        $map = [
            'borrador' => 'Borrador',
            'en_revision' => 'En revisión',
            'aprobada' => 'Aprobada',
            'rechazada' => 'Rechazada'
        ];
        return $map[$estatus] ?? $estatus;
    }
    
    private function obtenerNumeroPoliza($siniestroId)
    {
        $result = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->join('polizas', 'reportes.id_poliza', '=', 'polizas.id')
            ->where('siniestros.id', $siniestroId)
            ->select('polizas.numero_poliza')
            ->first();
        return $result ? $result->numero_poliza : 'N/A';
    }
    
    private function obtenerRfcCliente($siniestroId)
    {
        $result = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->join('polizas', 'reportes.id_poliza', '=', 'polizas.id')
            ->join('clientes', 'polizas.id_cliente', '=', 'clientes.id')
            ->where('siniestros.id', $siniestroId)
            ->select('clientes.rfc')
            ->first();
        return $result ? $result->rfc : 'N/A';
    }
    
    private function obtenerDomicilioCliente($siniestroId)
    {
        $result = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->join('polizas', 'reportes.id_poliza', '=', 'polizas.id')
            ->join('clientes', 'polizas.id_cliente', '=', 'clientes.id')
            ->where('siniestros.id', $siniestroId)
            ->select('clientes.domicilio')
            ->first();
        return $result ? $result->domicilio : 'N/A';
    }
    
    private function obtenerTelefonoCliente($siniestroId)
    {
        $result = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->join('polizas', 'reportes.id_poliza', '=', 'polizas.id')
            ->join('clientes', 'polizas.id_cliente', '=', 'clientes.id')
            ->where('siniestros.id', $siniestroId)
            ->select('clientes.numero_de_telefono')
            ->first();
        return $result ? $result->numero_de_telefono : 'N/A';
    }
    
    private function obtenerCorreoCliente($siniestroId)
    {
        $result = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->join('polizas', 'reportes.id_poliza', '=', 'polizas.id')
            ->join('clientes', 'polizas.id_cliente', '=', 'clientes.id')
            ->where('siniestros.id', $siniestroId)
            ->select('clientes.correo')
            ->first();
        return $result ? $result->correo : 'N/A';
    }
    
    private function obtenerFechaOcurrencia($siniestroId)
    {
        $result = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->where('siniestros.id', $siniestroId)
            ->select('reportes.fecha_ocurrencia')
            ->first();
        return $result ? date('d \\d\\e F \\d\\e Y', strtotime($result->fecha_ocurrencia)) : 'N/A';
    }
    
    private function obtenerUbicacion($siniestroId)
    {
        $result = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->where('siniestros.id', $siniestroId)
            ->select('reportes.ubicacion_accidente')
            ->first();
        return $result ? $result->ubicacion_accidente : 'N/A';
    }
    
    private function obtenerDescripcion($siniestroId)
    {
        $result = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->where('siniestros.id', $siniestroId)
            ->select('reportes.descripcion')
            ->first();
        return $result ? $result->descripcion : 'N/A';
    }
    
    private function obtenerDeducible($siniestroId)
    {
        $result = DB::table('siniestros')
            ->where('id', $siniestroId)
            ->select('deducible_aplicado')
            ->first();
        return $result ? '$' . number_format($result->deducible_aplicado, 2) : '$0.00';
    }
    
    private function obtenerNumeroReporte($siniestroId)
    {
        $result = DB::table('siniestros')
            ->join('reportes', 'siniestros.id_reporte', '=', 'reportes.id')
            ->where('siniestros.id', $siniestroId)
            ->select('reportes.id')
            ->first();
        return $result ? 'REP-' . str_pad($result->id, 6, '0', STR_PAD_LEFT) : 'N/A';
    }
    
    private function convertirLetras($numero)
    {
        $parteEntera = floor($numero);
        $centavos = round(($numero - $parteEntera) * 100);
        
        $texto = number_format($parteEntera, 0, '', ' ') . ' pesos';
        if ($centavos > 0) {
            $texto .= ' ' . $centavos . '/100 M.N.';
        } else {
            $texto .= ' 00/100 M.N.';
        }
        
        return $texto;
    }
}