<?php
// app/Http/Controllers/ClienteController.php

namespace App\Http\Controllers;

use App\Models\Cliente;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ClienteController extends Controller
{
    /**
     * Obtener listado de clientes con paginación
     */
    public function index(Request $request)
    {
        $query = Cliente::query();

        // Filtro por búsqueda
        if ($request->has('search') && $request->search) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('nombre', 'LIKE', "%{$search}%")
                  ->orWhere('apellidos', 'LIKE', "%{$search}%")
                  ->orWhere('rfc', 'LIKE', "%{$search}%")
                  ->orWhere('curp', 'LIKE', "%{$search}%")
                  ->orWhere('numero_de_telefono', 'LIKE', "%{$search}%")
                  ->orWhere('correo', 'LIKE', "%{$search}%");
            });
        }

        // Filtro por estatus
        if ($request->has('estatus') && $request->estatus !== 'Todos') {
            $query->where('estatus', $request->estatus);
        }

        // Paginación
        $perPage = $request->get('per_page', 8);
        $clientes = $query->orderBy('id', 'desc')->paginate($perPage);

        // Transformar datos para que coincidan con el frontend
        $clientes->getCollection()->transform(function($cliente) {
            return [
                'id' => $cliente->id,
                'nombre' => $cliente->nombre,
                'apellido' => $cliente->apellidos,
                'telefono' => $cliente->numero_de_telefono,
                'curp' => $cliente->curp,
                'rfc' => $cliente->rfc,
                'domicilio' => $cliente->domicilio,
                'correo' => $cliente->correo,
                'estatus' => $cliente->estatus,
                'fechaAlta' => $cliente->fecha_registro ? $cliente->fecha_registro->format('Y-m-d') : null,
            ];
        });

        return response()->json([
            'data' => $clientes->items(),
            'current_page' => $clientes->currentPage(),
            'last_page' => $clientes->lastPage(),
            'per_page' => $clientes->perPage(),
            'total' => $clientes->total(),
        ]);
    }

    /**
     * Obtener un cliente específico
     */
    public function show($id)
    {
        $cliente = Cliente::findOrFail($id);
        
        return response()->json([
            'id' => $cliente->id,
            'nombre' => $cliente->nombre,
            'apellido' => $cliente->apellidos,
            'telefono' => $cliente->numero_de_telefono,
            'curp' => $cliente->curp,
            'rfc' => $cliente->rfc,
            'domicilio' => $cliente->domicilio,
            'correo' => $cliente->correo,
            'estatus' => $cliente->estatus,
            'fechaAlta' => $cliente->fecha_registro ? $cliente->fecha_registro->format('Y-m-d') : null,
            'fecha_nacimiento' => $cliente->fecha_nacimiento,
            'genero' => $cliente->genero,
            'estado_civil' => $cliente->estado_civil,
            'nacionalidad' => $cliente->nacionalidad,
            'tipo_identificacion' => $cliente->tipo_identificacion,
            'numero_identificacion' => $cliente->numero_identificacion,
            'telefono_alternativo' => $cliente->telefono_alternativo,
            'municipio' => $cliente->municipio,
            'estado' => $cliente->estado,
            'codigo_postal' => $cliente->codigo_postal,
            'ocupacion' => $cliente->ocupacion,
        ]);
    }

    /**
     * Crear un nuevo cliente
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'nombre' => 'required|string|max:150',
            'apellido' => 'required|string|max:150',
            'telefono' => 'required|string|max:15',
            'correo' => 'required|email|max:100|unique:clientes,correo',
            'rfc' => 'required|string|max:13|unique:clientes,rfc',
            'curp' => 'required|string|max:16|unique:clientes,curp',
            'domicilio' => 'required|string|max:255',
            'fechaAlta' => 'nullable|date',
            'fecha_nacimiento' => 'nullable|date',
            'genero' => 'nullable|in:masculino,femenino,otro',
            'estado_civil' => 'nullable|in:soltero,casado,divorciado,viudo,union_libre',
            'nacionalidad' => 'nullable|string|max:50',
            'tipo_identificacion' => 'nullable|in:ine,pasaporte,cedula_profesional,licencia',
            'numero_identificacion' => 'nullable|string|max:30',
            'telefono_alternativo' => 'nullable|string|max:15',
            'municipio' => 'nullable|string|max:100',
            'estado' => 'nullable|string|max:50',
            'codigo_postal' => 'nullable|string|max:10',
            'ocupacion' => 'nullable|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Obtener id_aseguradora e id_usuario del usuario autenticado
        $user = auth()->user();
        
        $cliente = Cliente::create([
            'id_aseguradora' => $user->id_aseguradora,
            'id_usuario' => $user->id,
            'nombre' => $request->nombre,
            'apellidos' => $request->apellido,
            'fecha_nacimiento' => $request->fecha_nacimiento,
            'genero' => $request->genero,
            'estado_civil' => $request->estado_civil,
            'nacionalidad' => $request->nacionalidad ?? 'Mexicana',
            'curp' => $request->curp,
            'rfc' => $request->rfc,
            'tipo_identificacion' => $request->tipo_identificacion,
            'numero_identificacion' => $request->numero_identificacion,
            'numero_de_telefono' => $request->telefono,
            'telefono_alternativo' => $request->telefono_alternativo,
            'correo' => $request->correo,
            'domicilio' => $request->domicilio,
            'municipio' => $request->municipio,
            'estado' => $request->estado,
            'codigo_postal' => $request->codigo_postal,
            'ocupacion' => $request->ocupacion,
            'fecha_registro' => $request->fechaAlta ?? now(),
            'estatus' => 'activo',
        ]);

        return response()->json([
            'message' => 'Cliente creado exitosamente',
            'cliente' => $cliente
        ], 201);
    }

    /**
     * Actualizar un cliente
     */
    public function update(Request $request, $id)
    {
        $cliente = Cliente::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'nombre' => 'string|max:150',
            'apellido' => 'string|max:150',
            'telefono' => 'string|max:15',
            'correo' => 'email|max:100|unique:clientes,correo,' . $id,
            'rfc' => 'string|max:13|unique:clientes,rfc,' . $id,
            'curp' => 'string|max:16|unique:clientes,curp,' . $id,
            'domicilio' => 'string|max:255',
            'fecha_nacimiento' => 'nullable|date',
            'genero' => 'nullable|in:masculino,femenino,otro',
            'estado_civil' => 'nullable|in:soltero,casado,divorciado,viudo,union_libre',
            'estatus' => 'nullable|in:activo,inactivo,suspendido',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $cliente->update([
            'nombre' => $request->nombre ?? $cliente->nombre,
            'apellidos' => $request->apellido ?? $cliente->apellidos,
            'fecha_nacimiento' => $request->fecha_nacimiento ?? $cliente->fecha_nacimiento,
            'genero' => $request->genero ?? $cliente->genero,
            'estado_civil' => $request->estado_civil ?? $cliente->estado_civil,
            'curp' => $request->curp ?? $cliente->curp,
            'rfc' => $request->rfc ?? $cliente->rfc,
            'numero_de_telefono' => $request->telefono ?? $cliente->numero_de_telefono,
            'correo' => $request->correo ?? $cliente->correo,
            'domicilio' => $request->domicilio ?? $cliente->domicilio,
            'estatus' => $request->estatus ?? $cliente->estatus,
        ]);

        return response()->json([
            'message' => 'Cliente actualizado exitosamente',
            'cliente' => $cliente
        ]);
    }

    /**
     * Eliminar un cliente
     */
    public function destroy($id)
    {
        $cliente = Cliente::findOrFail($id);
        
        // Verificar si tiene pólizas asociadas
        $tienePolizas = DB::table('polizas')->where('id_cliente', $id)->exists();
        
        if ($tienePolizas) {
            return response()->json([
                'message' => 'No se puede eliminar el cliente porque tiene pólizas asociadas'
            ], 400);
        }
        
        $cliente->delete();
        
        return response()->json([
            'message' => 'Cliente eliminado exitosamente'
        ]);
    }
}