<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmpleadoController extends Controller
{
    public function index(Request $request)
    {
        $usuario        = json_decode($request->header('X-Usuario'), true);
        $id_aseguradora = $usuario['id_aseguradora'];

        $empleados = DB::table('empleados')
            ->leftJoin('usuarios', 'empleados.id_usuario', '=', 'usuarios.id')
            ->leftJoin('roles',    'usuarios.id_rol',      '=', 'roles.id')
            ->select('empleados.*', 'roles.nombre as rol')
            ->where('empleados.id_aseguradora', $id_aseguradora)
            ->get();

        return response()->json($empleados);
    }

    public function show($id)
    {
        $empleado = DB::table('empleados')
            ->leftJoin('usuarios', 'empleados.id_usuario', '=', 'usuarios.id')
            ->leftJoin('roles',    'usuarios.id_rol',      '=', 'roles.id')
            ->select('empleados.*', 'roles.nombre as rol')
            ->where('empleados.id', $id)
            ->first();

        if (!$empleado) {
            return response()->json(['message' => 'Empleado no encontrado'], 404);
        }

        return response()->json($empleado);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre'                         => 'required|string|max:150',
            'apellidos'                      => 'required|string|max:150',
            'fecha_nacimiento'               => 'required|date',
            'genero'                         => 'required|string',
            'estado_civil'                   => 'required|string',
            'nacionalidad'                   => 'required|string',
            'curp'                           => 'required|string|max:18',
            'rfc'                            => 'required|string|max:13',
            'nss'                            => 'required|string|max:11',
            'tipo_identificacion'            => 'required|string',
            'numero_identificacion'          => 'required|string',
            'numero_de_telefono'             => 'required|string',
            'correo'                         => 'required|email',
            'domicilio'                      => 'required|string',
            'municipio'                      => 'required|string',
            'estado'                         => 'required|string',
            'codigo_postal'                  => 'required|string',
            'fecha_ingreso'                  => 'required|date',
            'departamento'                   => 'required|string',
            'tipo_empleado'                  => 'required|string',
            'puesto'                         => 'required|string',
            'horario'                        => 'required|string',
            'estado_laboral'                 => 'required|string',
            'salario'                        => 'required|numeric',
            'tipo_nomina'                    => 'required|string',
            'banco'                          => 'required|string',
            'cuenta_bancaria'                => 'required|string',
            'clabe_interbancaria'            => 'required|string',
            'contacto_emergencia_nombre'     => 'required|string',
            'contacto_emergencia_parentesco' => 'required|string',
            'contacto_emergencia_telefono'   => 'required|string',
            'id_aseguradora'                 => 'required|integer',
            'password'                       => 'required|min:5',
            'id_rol'                         => 'required|integer',
        ]);

        // 1. Crear usuario
        $usuario = \App\Models\Usuario::create([
            'correo'         => $request->correo,
            'contrasena'     => Hash::make($request->password),
            'id_rol'         => $request->id_rol,
            'id_aseguradora' => $request->id_aseguradora,
            'es_superadmin'  => false,
        ]);

        // 2. Crear empleado con el id_usuario recién creado
        $empleado = Empleado::create([
            'id_aseguradora'                 => $request->id_aseguradora,
            'id_usuario'                     => $usuario->id,
            'nombre'                         => $request->nombre,
            'apellidos'                      => $request->apellidos,
            'fecha_nacimiento'               => $request->fecha_nacimiento,
            'genero'                         => $request->genero,
            'estado_civil'                   => $request->estado_civil,
            'nacionalidad'                   => $request->nacionalidad,
            'curp'                           => strtoupper($request->curp),
            'rfc'                            => strtoupper($request->rfc),
            'nss'                            => $request->nss,
            'tipo_identificacion'            => $request->tipo_identificacion,
            'numero_identificacion'          => $request->numero_identificacion,
            'numero_de_telefono'             => $request->numero_de_telefono,
            'telefono_alternativo'           => $request->telefono_alternativo           ?? null,
            'correo'                         => $request->correo,
            'domicilio'                      => $request->domicilio,
            'municipio'                      => $request->municipio,
            'estado'                         => $request->estado,
            'codigo_postal'                  => $request->codigo_postal,
            'fecha_ingreso'                  => $request->fecha_ingreso,
            'departamento'                   => $request->departamento,
            'tipo_empleado'                  => $request->tipo_empleado,
            'puesto'                         => $request->puesto,
            'horario'                        => $request->horario,
            'estado_laboral'                 => $request->estado_laboral,
            'fecha_baja'                     => $request->fecha_baja                     ?? null,
            'motivo_baja'                    => $request->motivo_baja                    ?? null,
            'salario'                        => $request->salario,
            'tipo_nomina'                    => $request->tipo_nomina,
            'banco'                          => $request->banco,
            'cuenta_bancaria'                => $request->cuenta_bancaria,
            'clabe_interbancaria'            => $request->clabe_interbancaria,
            'cedula_profesional'             => $request->cedula_profesional             ?? null,
            'fecha_vencimiento_cedula'       => $request->fecha_vencimiento_cedula       ?? null,
            'especialidad'                   => $request->especialidad                   ?? null,
            'contacto_emergencia_nombre'     => $request->contacto_emergencia_nombre,
            'contacto_emergencia_parentesco' => $request->contacto_emergencia_parentesco,
            'contacto_emergencia_telefono'   => $request->contacto_emergencia_telefono,
        ]);

        return response()->json([
            'message'  => 'Empleado creado correctamente',
            'empleado' => $empleado,
        ], 201);
    }
    
 public function update(Request $request, $id)
    {
        $empleado = Empleado::findOrFail($id);

        // Actualizar empleado
        $empleado->update($request->except(['password', 'id_rol']));

        // Actualizar rol en la tabla usuarios
        if ($request->filled('id_rol')) {
            \App\Models\Usuario::where('id', $empleado->id_usuario)
                ->update(['id_rol' => $request->id_rol]);
        }

        // Actualizar password solo si viene
        if ($request->filled('password')) {
            \App\Models\Usuario::where('id', $empleado->id_usuario)
                ->update(['contrasena' => \Illuminate\Support\Facades\Hash::make($request->password)]);
        }

        return response()->json([
            'message'  => 'Empleado actualizado correctamente',
            'empleado' => $empleado,
        ]);
    }

    public function destroy($id)
    {
        Empleado::findOrFail($id)->delete();
        return response()->json(['message' => 'Empleado eliminado correctamente']);
    }
}