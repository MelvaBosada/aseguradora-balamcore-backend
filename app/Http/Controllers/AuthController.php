<?php

namespace App\Http\Controllers;

use App\Models\Aseguradora;
use App\Models\Usuario;
use App\Models\Rol;
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

        // El AseguradoraObserver ya creó los roles base (Administrador, Ajustador)
        // para ESTA aseguradora. Buscamos el id real de su "Administrador"
        // en vez de asumir que siempre es 1.
        $rolAdmin = Rol::where('aseguradora_id', $aseguradora->id)
            ->where('nombre', 'Administrador')
            ->firstOrFail();

        $request->merge([
            'id_aseguradora' => $aseguradora->id,
            'id_rol'         => $rolAdmin->id,
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
                'usuarios.id',
                'usuarios.correo',
                'usuarios.contrasena',
                'usuarios.id_rol',
                'usuarios.id_aseguradora',
                'usuarios.es_superadmin',
                'usuarios.activo',
                'usuarios.tour_completado',
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

        if (!$usuario->activo) {
            return response()->json(['message' => 'Tu cuenta está desactivada. Contacta al administrador.'], 403);
        }

        $permisos = DB::table('rolxpermiso')
            ->join('permisos', 'rolxpermiso.id_permiso', '=', 'permisos.id')
            ->where('rolxpermiso.id_rol', $usuario->id_rol)
            ->pluck('permisos.clave')
            ->toArray();

        $suscripcion = $usuario->id_aseguradora
            ? \App\Services\SuscripcionService::resumenActual((int) $usuario->id_aseguradora)
            : null;

        $modulos = $suscripcion ? ($suscripcion->modulos ?? []) : [];

        DB::table('usuarios')->where('id', $usuario->id)->update(['ultimo_acceso' => Carbon::now()]);

        $token = \App\Models\Usuario::find($usuario->id)->createToken('auth_token')->plainTextToken;

        // El hash de la contraseña NUNCA sale del servidor
        $datosUsuario = (array) $usuario;
        unset($datosUsuario['contrasena']);
        $datosUsuario['tour_completado'] = (bool) $usuario->tour_completado;

        return response()->json([
            'message'     => 'Login exitoso',
            'token'       => $token,
            'usuario'     => $datosUsuario,
            'permisos'    => $permisos,
            'modulos'     => $modulos,
            'suscripcion' => $suscripcion,
        ]);
    }

    public function completarTour(Request $request)
    {
        DB::table('usuarios')->where('id', $request->user()->id)->update(['tour_completado' => true]);
        return response()->json(['message' => 'Guía completada']);
    }

    public function logout(Request $request)
    {
        $request->user()?->currentAccessToken()?->delete();
        return response()->json(['message' => 'Sesión cerrada']);
    }
}