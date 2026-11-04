<?php

namespace App\Http\Controllers;

use App\Models\Empleado;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

class PerfilController extends Controller
{
    private const PASSWORD_MAX_INTENTOS = 3;
    private const PASSWORD_BLOQUEO_SEG  = 900; // 15 minutos

    /**
     * Oculta el centro de un dato sensible. El valor completo solo sale
     * por sensibles(), cuando la persona pulsa el ojo.
     */
    private function mascara(?string $valor, int $inicio = 0, int $fin = 0): ?string
    {
        if (!$valor) {
            return null;
        }

        $n = mb_strlen($valor);
        if ($n <= $inicio + $fin) {
            return str_repeat('•', $n);
        }

        return mb_substr($valor, 0, $inicio)
            . str_repeat('•', $n - $inicio - $fin)
            . ($fin > 0 ? mb_substr($valor, -$fin) : '');
    }

    /**
     * Ficha del usuario que inició sesión. Sirve para cualquier rol:
     * un administrador también puede tener ficha de empleado. Si no la tiene, devuelve null.
     */
    public function show(Request $request)
    {
        $e = DB::table('empleados')
            ->leftJoin('usuarios', 'empleados.id_usuario', '=', 'usuarios.id')
            ->leftJoin('roles', 'usuarios.id_rol', '=', 'roles.id')
            ->where('empleados.id_usuario', $request->user()->id)
            ->select(
                'empleados.id', 'empleados.nombre', 'empleados.apellidos', 'empleados.correo',
                'empleados.puesto', 'empleados.departamento', 'empleados.tipo_empleado', 'empleados.horario',
                'empleados.estado_civil', 'empleados.estado_laboral', 'empleados.fecha_ingreso',
                'empleados.curp', 'empleados.rfc', 'empleados.nss',
                'empleados.numero_de_telefono', 'empleados.telefono_alternativo',
                'empleados.domicilio', 'empleados.municipio', 'empleados.estado', 'empleados.codigo_postal',
                'empleados.contacto_emergencia_nombre', 'empleados.contacto_emergencia_parentesco',
                'empleados.contacto_emergencia_telefono',
                'empleados.cedula_profesional', 'empleados.fecha_vencimiento_cedula', 'empleados.especialidad',
                'roles.nombre as rol'
            )
            ->first();

        if (!$e) {
            return response()->json(['empleado' => null]);
        }

        $d = (array) $e;

        $d['numero_empleado'] = 'EMP-' . str_pad((string) $d['id'], 5, '0', STR_PAD_LEFT);
        $d['curp_mask']       = $this->mascara($d['curp'], 4, 2);
        $d['rfc_mask']        = $this->mascara($d['rfc'], 4, 3);
        $d['nss_mask']        = $this->mascara($d['nss'], 0, 3);

        unset($d['curp'], $d['rfc'], $d['nss']);

        return response()->json(['empleado' => $d]);
    }

    /**
     * Datos completos de CURP, RFC y NSS. Solo los del propio usuario y solo
     * cuando pulsa el ojo (el front los vuelve a ocultar a los 20 segundos).
     */
    public function sensibles(Request $request)
    {
        $u = $request->user();

        $e = DB::table('empleados')->where('id_usuario', $u->id)->first(['curp', 'rfc', 'nss']);
        abort_if(!$e, 404, 'Tu cuenta no tiene una ficha de empleado.');

        Log::info('Perfil: datos sensibles consultados', ['id_usuario' => $u->id]);

        return response()->json(['curp' => $e->curp, 'rfc' => $e->rfc, 'nss' => $e->nss]);
    }

    /**
     * El empleado edita SOLO datos personales de contacto.
     * Puesto, salario, banco, CURP, RFC, NSS, horario, correo de acceso y rol
     * los controla el administrador desde Empleados.
     */
    public function update(Request $request)
    {
        $e = Empleado::where('id_usuario', $request->user()->id)->first();
        abort_if(!$e, 404, 'Tu cuenta no tiene una ficha de empleado.');

        $data = $request->validate([
            'numero_de_telefono'             => 'required|string|max:15',
            'telefono_alternativo'           => 'nullable|string|max:15',
            'estado_civil'                   => 'required|in:soltero,casado,divorciado,viudo',
            'domicilio'                      => 'required|string|max:255',
            'municipio'                      => 'required|string|max:100',
            'estado'                         => 'required|string|max:50',
            'codigo_postal'                  => 'required|string|max:10',
            'contacto_emergencia_nombre'     => 'required|string|max:150',
            'contacto_emergencia_parentesco' => 'required|string|max:50',
            'contacto_emergencia_telefono'   => 'required|string|max:15',
        ]);

        $e->update($data);

        return response()->json(['message' => 'Datos actualizados']);
    }

    /**
     * Cada persona cambia su propia contraseña, pidiendo la actual.
     * Solo hay 3 intentos con la contraseña actual equivocada; al tercero se
     * bloquea 15 minutos y se avisa por correo (al administrador, o a soporte
     * si quien falló es el propio administrador).
     */
    public function password(Request $request)
    {
        $u     = $request->user();
        $clave = 'perfil-password:' . $u->id;

        if (RateLimiter::tooManyAttempts($clave, self::PASSWORD_MAX_INTENTOS)) {
            return $this->respuestaBloqueo($u, RateLimiter::availableIn($clave), false);
        }

        $request->validate([
            'actual' => 'required|string',
            'nueva'  => 'required|string|min:8|different:actual',
        ]);

        if (!Hash::check($request->actual, $u->contrasena)) {
            RateLimiter::hit($clave, self::PASSWORD_BLOQUEO_SEG);

            if (RateLimiter::tooManyAttempts($clave, self::PASSWORD_MAX_INTENTOS)) {
                return $this->respuestaBloqueo($u, RateLimiter::availableIn($clave), true);
            }

            $quedan = RateLimiter::remaining($clave, self::PASSWORD_MAX_INTENTOS);

            return response()->json([
                'message'            => 'La contraseña actual no es correcta. '
                    . ($quedan === 1 ? 'Te queda 1 intento.' : "Te quedan {$quedan} intentos."),
                'intentos_restantes' => $quedan,
            ], 422);
        }

        RateLimiter::clear($clave);

        DB::table('usuarios')->where('id', $u->id)->update(['contrasena' => Hash::make($request->nueva)]);

        // Cierra las demás sesiones abiertas; conserva la actual
        $tokenActual = $u->currentAccessToken()->id ?? 0;
        $u->tokens()->where('id', '!=', $tokenActual)->delete();

        return response()->json(['message' => 'Contraseña actualizada']);
    }

    /* ───────── Bloqueo por intentos fallidos ───────── */

    private function respuestaBloqueo($u, int $segundos, bool $avisarAhora)
    {
        $claveAviso = 'perfil-password-aviso:' . $u->id;
        $esAdmin    = DB::table('roles')->where('id', $u->id_rol)->value('nombre') === 'Administrador';

        if ($avisarAhora) {
            $avisado = $this->avisarIntentosFallidos($u, $esAdmin);
            Cache::put($claveAviso, $avisado, $segundos);
        } else {
            $avisado = (bool) Cache::get($claveAviso, false);
        }

        $min = max(1, (int) ceil($segundos / 60));
        $espera = $min === 1 ? '1 minuto' : "{$min} minutos";

        if ($esAdmin) {
            $mensaje = 'Superaste los ' . self::PASSWORD_MAX_INTENTOS . " intentos permitidos. Por seguridad, espera {$espera} para volver a intentarlo. "
                . ($avisado ? 'Ya avisamos a soporte.' : 'Si necesitas ayuda, contacta a soporte.');
        } else {
            $mensaje = 'Superaste los ' . self::PASSWORD_MAX_INTENTOS . ' intentos permitidos. '
                . ($avisado
                    ? 'Ya avisamos a tu administrador; contáctalo para que te ayude a restablecer tu contraseña.'
                    : 'Contacta a tu administrador para que te ayude a restablecer tu contraseña.')
                . " También puedes volver a intentarlo en {$espera}.";
        }

        return response()->json([
            'message'         => $mensaje,
            'bloqueado'       => true,
            'avisado'         => $avisado,
            'espera_segundos' => $segundos,
        ], 429);
    }

    /** Devuelve true si el aviso llegó a enviarse al menos a una persona. */
    private function avisarIntentosFallidos($u, bool $esAdmin): bool
    {
        if ($esAdmin) {
            $destinos = array_filter([config('services.soporte.correo') ?: config('mail.from.address')]);
        } else {
            $destinos = DB::table('usuarios')
                ->join('roles', 'usuarios.id_rol', '=', 'roles.id')
                ->where('usuarios.id_aseguradora', $u->id_aseguradora)
                ->where('roles.nombre', 'Administrador')
                ->where('usuarios.activo', true)
                ->where('usuarios.id', '!=', $u->id)
                ->pluck('usuarios.correo')
                ->all();
        }

        if (!$destinos) {
            return false;
        }

        $emp     = DB::table('empleados')->where('id_usuario', $u->id)->first(['nombre', 'apellidos']);
        $persona = $emp ? trim("{$emp->nombre} {$emp->apellidos}") : $u->correo;

        $texto = "{$persona} ({$u->correo}) falló " . self::PASSWORD_MAX_INTENTOS
            . " veces la contraseña actual al intentar cambiarla desde su perfil.\n\n"
            . 'Se bloqueó el cambio de contraseña por ' . (self::PASSWORD_BLOQUEO_SEG / 60) . " minutos.\n\n"
            . "Si no fue esa persona, revisa su cuenta. Si olvidó su contraseña, puedes restablecerla desde Empleados.";

        $enviados = 0;
        foreach ($destinos as $correo) {
            try {
                Mail::raw($texto, fn ($m) => $m->to($correo)->subject("Intentos fallidos de contraseña — {$persona}"));
                $enviados++;
            } catch (\Throwable $e) {
                Log::error('Aviso de intentos fallidos: ' . $e->getMessage());
            }
        }

        return $enviados > 0;
    }
}