<?php

namespace App\Http\Controllers;

use App\Models\Aseguradora;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class AuthController extends Controller
{
    public function enviarOtp(Request $request)
    {
        $request->validate(['correo' => 'required|email']);

        if (Usuario::where('correo', $request->correo)->exists()) {
            return response()->json(['message' => 'Este correo ya está registrado'], 422);
        }

        $codigo = str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        DB::table('otps')->where('correo', $request->correo)->delete();
        DB::table('otps')->insert([
            'correo'     => $request->correo,
            'codigo'     => $codigo,
            'verificado' => false,
            'expira_en'  => Carbon::now()->addMinutes(10),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        Mail::send('emails.otp', ['codigo' => $codigo], function ($msg) use ($request) {
            $msg->to($request->correo)
                ->subject('Tu código de verificación - BalamCore');
        });

        return response()->json(['message' => 'Código enviado correctamente']);
    }

    public function verificarOtp(Request $request)
    {
        $request->validate([
            'correo' => 'required|email',
            'codigo' => 'required|string|size:6',
        ]);

        $otp = DB::table('otps')
            ->where('correo',     $request->correo)
            ->where('codigo',     $request->codigo)
            ->where('verificado', false)
            ->where('expira_en',  '>=', Carbon::now())
            ->first();

        if (!$otp) {
            return response()->json(['message' => 'Código incorrecto o expirado'], 422);
        }

        DB::table('otps')->where('id', $otp->id)->update(['verificado' => true]);

        return response()->json(['message' => 'Correo verificado correctamente']);
    }

    public function registro(Request $request)
    {
        $otp = DB::table('otps')
            ->where('correo',     $request->correo)
            ->where('verificado', true)
            ->first();

        if (!$otp) {
            return response()->json(['message' => 'Correo no verificado'], 422);
        }

        $aseguradoraController = new AseguradoraController();
        $resAseguradora = $aseguradoraController->store($request);

        if ($resAseguradora->getStatusCode() !== 201) {
            return $resAseguradora;
        }

        $aseguradora = json_decode($resAseguradora->getContent())->aseguradora;

        $request->merge([
            'id_aseguradora' => $aseguradora->id,
            'id_rol'         => 1,
        ]);

        $usuarioController = new UsuarioController();
        $resUsuario = $usuarioController->store($request);

        if ($resUsuario->getStatusCode() !== 201) {
            Aseguradora::findOrFail($aseguradora->id)->delete();
            return $resUsuario;
        }

        $planPro = DB::table('planes')->where('nombre', 'Pro')->first();

        if ($planPro) {
            DB::table('suscripciones')->insert([
                'id_aseguradora' => $aseguradora->id,
                'id_plan'        => $planPro->id,
                'estatus'        => 'prueba',
                'fecha_inicio'   => Carbon::now(),
                'fecha_fin'      => Carbon::now()->addDays(15),
                'created_at'     => Carbon::now(),
                'updated_at'     => Carbon::now(),
            ]);
        }

        DB::table('otps')->where('correo', $request->correo)->delete();

        return response()->json(['message' => 'Registro exitoso'], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'correo'   => 'required|email',
            'password' => 'required',
        ]);

        $usuario = DB::table('usuarios')
            ->join('roles', 'usuarios.id_rol', '=', 'roles.id')
            ->leftJoin('aseguradoras', 'usuarios.id_aseguradora', '=', 'aseguradoras.id')
            ->leftJoin('empleados', 'empleados.id_usuario', '=', 'usuarios.id')
            ->select(
                'usuarios.*',
                'roles.nombre as rol',
                'aseguradoras.nombre as aseguradora',
                'aseguradoras.fecha_registro',
                'empleados.nombre as nombre',
                'empleados.apellidos as apellidos',
            )
            ->where('usuarios.correo', $request->correo)
            ->first();

        if (!$usuario || !Hash::check($request->password, $usuario->contrasena)) {
            return response()->json(['message' => 'Credenciales incorrectas'], 401);
        }

        // Permisos del rol
        $permisos = DB::table('rolxpermiso')
            ->join('permisos', 'rolxpermiso.id_permiso', '=', 'permisos.id')
            ->where('rolxpermiso.id_rol', $usuario->id_rol)
            ->pluck('permisos.clave')
            ->toArray();

        // Suscripción activa con TODOS los campos del plan que necesita el frontend
        $suscripcion = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->select(
                'suscripciones.*',
                'planes.nombre as plan',
                'planes.precio',
                'planes.precio_anual',
                'planes.limite_polizas_vencer_dia',
                'planes.limite_siniestros_mes',
                'planes.limite_bienes_por_cliente',
                'planes.pdf_ilimitado',
                'planes.soporte_prioritario',
            )
            ->where('suscripciones.id_aseguradora', $usuario->id_aseguradora)
            ->whereIn('suscripciones.estatus', ['prueba', 'activo', 'activa', 'trial'])
            ->orderBy('suscripciones.id', 'desc')
            ->first();

        // Módulos del plan activo
        $modulos = [];
        if ($suscripcion) {
            $modulos = DB::table('planxmodulo')
                ->join('modulos', 'planxmodulo.id_modulo', '=', 'modulos.id')
                ->where('planxmodulo.id_plan', $suscripcion->id_plan)
                ->pluck('modulos.clave')
                ->toArray();
        }

        $usuarioModel = \App\Models\Usuario::find($usuario->id);
        $token = $usuarioModel->createToken('auth_token')->plainTextToken;

        return response()->json([
            'message'     => 'Login exitoso',
            'token'       => $token,
            'usuario'     => $usuario,
            'permisos'    => $permisos,
            'modulos'     => $modulos,
            'suscripcion' => $suscripcion,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Sesión cerrada']);
    }
}
