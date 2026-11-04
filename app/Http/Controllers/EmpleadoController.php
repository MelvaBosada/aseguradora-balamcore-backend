<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use App\Models\Rol;
use App\Models\Usuario;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class EmpleadoController extends Controller
{
    /** Campos que se pueden editar. Estado laboral y baja se cambian solo con sus acciones. */
    private const EDITABLES = [
        'nombre', 'apellidos', 'fecha_nacimiento', 'genero', 'estado_civil', 'nacionalidad',
        'curp', 'rfc', 'nss', 'tipo_identificacion', 'numero_identificacion',
        'numero_de_telefono', 'telefono_alternativo', 'correo',
        'domicilio', 'municipio', 'estado', 'codigo_postal',
        'fecha_ingreso', 'departamento', 'tipo_empleado', 'puesto', 'horario',
        'salario', 'tipo_nomina', 'banco', 'cuenta_bancaria', 'clabe_interbancaria',
        'cedula_profesional', 'fecha_vencimiento_cedula', 'especialidad',
        'contacto_emergencia_nombre', 'contacto_emergencia_parentesco', 'contacto_emergencia_telefono',
    ];

    private function idAseg(Request $request): int
    {
        return (int) $request->user()->id_aseguradora;
    }

    /** Solo empleados de MI aseguradora. Si el id es de otra, responde 404. */
    private function buscar(Request $request, $id): Empleado
    {
        return Empleado::where('id_aseguradora', $this->idAseg($request))->findOrFail($id);
    }

    private function conRol()
    {
        return DB::table('empleados')
            ->leftJoin('usuarios', 'empleados.id_usuario', '=', 'usuarios.id')
            ->leftJoin('roles', 'usuarios.id_rol', '=', 'roles.id')
            ->select('empleados.*', 'usuarios.id_rol', 'roles.nombre as rol', 'usuarios.ultimo_acceso');
    }

    private function reglas(): array
    {
        return [
            'nombre'                         => 'required|string|max:150',
            'apellidos'                      => 'required|string|max:150',
            'fecha_nacimiento'               => 'required|date',
            'genero'                         => 'required|in:M,F',
            'estado_civil'                   => 'required|in:soltero,casado,divorciado,viudo',
            'nacionalidad'                   => 'required|string|max:50',
            'curp'                           => 'required|string|size:18',
            'rfc'                            => 'required|string|max:13',
            'nss'                            => 'required|string|max:11',
            'tipo_identificacion'            => 'required|in:INE,pasaporte,cedula',
            'numero_identificacion'          => 'required|string|max:30',
            'numero_de_telefono'             => 'required|string|max:15',
            'telefono_alternativo'           => 'nullable|string|max:15',
            'correo'                         => 'required|email|max:100',
            'domicilio'                      => 'required|string|max:255',
            'municipio'                      => 'required|string|max:100',
            'estado'                         => 'required|string|max:50',
            'codigo_postal'                  => 'required|string|max:10',
            'fecha_ingreso'                  => 'required|date',
            'departamento'                   => 'required|string|max:50',
            'tipo_empleado'                  => 'required|in:ajustador,perito,agente,promotor,cobrador,abogado,admin',
            'puesto'                         => 'required|string|max:100',
            'horario'                        => 'required|in:matutino,vespertino,nocturno,mixto',
            'salario'                        => 'required|numeric|min:0',
            'tipo_nomina'                    => 'required|in:semanal,quincenal,mensual',
            'banco'                          => 'required|string|max:100',
            'cuenta_bancaria'                => 'required|string|max:30',
            'clabe_interbancaria'            => 'required|string|max:18',
            'cedula_profesional'             => 'nullable|string|max:20',
            'fecha_vencimiento_cedula'       => 'nullable|date',
            'especialidad'                   => 'nullable|string|max:100',
            'contacto_emergencia_nombre'     => 'required|string|max:150',
            'contacto_emergencia_parentesco' => 'required|string|max:50',
            'contacto_emergencia_telefono'   => 'required|string|max:15',
        ];
    }

    public function index(Request $request)
    {
        return response()->json(
            $this->conRol()->where('empleados.id_aseguradora', $this->idAseg($request))->get()
        );
    }

    public function show(Request $request, $id)
    {
        $empleado = $this->conRol()
            ->where('empleados.id_aseguradora', $this->idAseg($request))
            ->where('empleados.id', $id)
            ->first();

        abort_if(!$empleado, 404, 'Empleado no encontrado');
        return response()->json($empleado);
    }

    public function store(Request $request)
    {
        $idAseg = $this->idAseg($request);

        $request->validate($this->reglas() + [
            'password' => 'required|string|min:5',
            'id_rol'   => 'required|integer',
        ]);

        // El rol tiene que ser de esta aseguradora
        Rol::where('aseguradora_id', $idAseg)->findOrFail($request->id_rol);

        abort_if(
            Usuario::where('correo', $request->correo)->exists(),
            422,
            'Ya existe un usuario con ese correo.'
        );
        abort_if(
            Empleado::where('id_aseguradora', $idAseg)->where('curp', strtoupper($request->curp))->exists(),
            422,
            'Ya existe un empleado con esa CURP.'
        );

        $empleado = DB::transaction(function () use ($request, $idAseg) {
            $usuario = Usuario::create([
                'correo'         => $request->correo,
                'contrasena'     => Hash::make($request->password),
                'id_rol'         => $request->id_rol,
                'id_aseguradora' => $idAseg,
                'es_superadmin'  => false,
            ]);

            $datos = $request->only(self::EDITABLES);
            $datos['curp'] = strtoupper($datos['curp']);
            $datos['rfc']  = strtoupper($datos['rfc']);

            return Empleado::create($datos + [
                'id_aseguradora' => $idAseg,
                'id_usuario'     => $usuario->id,
                'estado_laboral' => 'activo',
            ]);
        });

        return response()->json(['message' => 'Empleado creado correctamente', 'empleado' => $empleado], 201);
    }

    public function update(Request $request, $id)
    {
        $idAseg   = $this->idAseg($request);
        $empleado = $this->buscar($request, $id);

        $reglas = collect($this->reglas())->map(fn ($r) => 'sometimes|' . $r)->all();
        $request->validate($reglas + [
            'password' => 'nullable|string|min:5',
            'id_rol'   => 'nullable|integer',
        ]);

        if ($request->filled('correo')) {
            abort_if(
                Usuario::where('correo', $request->correo)->where('id', '!=', $empleado->id_usuario)->exists(),
                422,
                'Ya existe un usuario con ese correo.'
            );
        }

        if ($request->filled('curp')) {
            abort_if(
                Empleado::where('id_aseguradora', $idAseg)->where('id', '!=', $empleado->id)
                    ->where('curp', strtoupper($request->curp))->exists(),
                422,
                'Ya existe un empleado con esa CURP.'
            );
        }

        if ($request->filled('id_rol')) {
            Rol::where('aseguradora_id', $idAseg)->findOrFail($request->id_rol);
            abort_if(
                (int) $empleado->id_usuario === (int) $request->user()->id,
                422,
                'No puedes cambiar tu propio rol.'
            );
        }

        DB::transaction(function () use ($request, $empleado) {
            $datos = $request->only(self::EDITABLES);
            if (isset($datos['curp'])) $datos['curp'] = strtoupper($datos['curp']);
            if (isset($datos['rfc']))  $datos['rfc']  = strtoupper($datos['rfc']);
            $empleado->update($datos);

            $cambiosUsuario = [];
            if ($request->filled('id_rol'))   $cambiosUsuario['id_rol']     = $request->id_rol;
            if ($request->filled('correo'))   $cambiosUsuario['correo']     = $request->correo;
            if ($request->filled('password')) $cambiosUsuario['contrasena'] = Hash::make($request->password);

            if ($cambiosUsuario) {
                DB::table('usuarios')->where('id', $empleado->id_usuario)->update($cambiosUsuario);
            }
        });

        return response()->json(['message' => 'Empleado actualizado correctamente', 'empleado' => $empleado->fresh()]);
    }

    /* ───────── Baja lógica ───────── */

    private function cambiarEstado(Request $request, $id, string $estado, ?string $motivo = null)
    {
        $empleado = $this->buscar($request, $id);

        if ($estado !== 'activo') {
            abort_if(
                (int) $empleado->id_usuario === (int) $request->user()->id,
                422,
                'No puedes dar de baja ni suspender tu propia cuenta.'
            );

            $esAdmin = DB::table('usuarios')
                ->join('roles', 'usuarios.id_rol', '=', 'roles.id')
                ->where('usuarios.id', $empleado->id_usuario)
                ->value('roles.nombre') === 'Administrador';
            abort_if($esAdmin, 422, 'No se puede dar de baja a un administrador. Cámbiale el rol primero.');
        }

        DB::transaction(function () use ($request, $empleado, $estado, $motivo) {
            $empleado->update([
                'estado_laboral' => $estado,
                'fecha_baja'     => $estado === 'baja' ? Carbon::today() : null,
                'motivo_baja'    => $estado === 'activo' ? null : $motivo,
                'baja_por'       => $estado === 'activo' ? null : $request->user()->id,
            ]);

            DB::table('usuarios')->where('id', $empleado->id_usuario)->update(['activo' => $estado === 'activo']);

            // Corta las sesiones abiertas para que no siga entrando con su token
            if ($estado !== 'activo') {
                Usuario::find($empleado->id_usuario)?->tokens()->delete();
            }
        });

        return $empleado->fresh();
    }

    public function baja(Request $request, $id)
    {
        $request->validate(['motivo' => 'required|string|max:255']);
        $e = $this->cambiarEstado($request, $id, 'baja', $request->motivo);
        return response()->json(['message' => 'Empleado dado de baja. Ya no puede entrar al sistema.', 'empleado' => $e]);
    }

    public function suspender(Request $request, $id)
    {
        $request->validate(['motivo' => 'nullable|string|max:255']);
        $e = $this->cambiarEstado($request, $id, 'suspendido', $request->motivo);
        return response()->json(['message' => 'Empleado suspendido. No puede entrar mientras dure la suspensión.', 'empleado' => $e]);
    }

    public function reactivar(Request $request, $id)
    {
        $e = $this->cambiarEstado($request, $id, 'activo');
        return response()->json(['message' => 'Empleado reactivado.', 'empleado' => $e]);
    }

    public function destroy()
    {
        abort(409, 'Los empleados no se eliminan: tienen historial. Usa "Dar de baja".');
    }
}