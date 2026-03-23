<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AseguradoraController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\PermisoController;
use App\Http\Controllers\RolxPermisoController;
use App\Http\Controllers\EmpleadoController;
use Illuminate\Support\Facades\Route;

// ── Auth públicas ──────────────────────────────
Route::post('/enviar-otp',    [AuthController::class, 'enviarOtp']);
Route::post('/verificar-otp', [AuthController::class, 'verificarOtp']);
Route::post('/registro',      [AuthController::class, 'registro']);
Route::post('/login',         [AuthController::class, 'login']);

// ── Rutas protegidas con Sanctum ───────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    // ── Aseguradoras ─────────────────────────────────
    Route::get   ('/aseguradoras',      [AseguradoraController::class, 'index']);
    Route::get   ('/aseguradoras/{id}', [AseguradoraController::class, 'show']);
    Route::post  ('/aseguradoras',      [AseguradoraController::class, 'store']);
    Route::put   ('/aseguradoras/{id}', [AseguradoraController::class, 'update']);
    Route::delete('/aseguradoras/{id}', [AseguradoraController::class, 'destroy']);

    // ── Usuarios ──────────────────────────────────────
    Route::get   ('/usuarios',      [UsuarioController::class, 'index']);
    Route::get   ('/usuarios/{id}', [UsuarioController::class, 'show']);
    Route::post  ('/usuarios',      [UsuarioController::class, 'store']);
    Route::put   ('/usuarios/{id}', [UsuarioController::class, 'update']);
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);

    // ── Roles ─────────────────────────────────────────
    Route::get   ('/roles',      [RolController::class, 'index']);
    Route::post  ('/roles',      [RolController::class, 'store']);
    Route::put   ('/roles/{id}', [RolController::class, 'update']);
    Route::delete('/roles/{id}', [RolController::class, 'destroy']);

    // ── Permisos ──────────────────────────────────────
    Route::get   ('/permisos',      [PermisoController::class, 'index'])->middleware('permiso:permisos');
    Route::post  ('/permisos',      [PermisoController::class, 'store'])->middleware('permiso:permisos');
    Route::put   ('/permisos/{id}', [PermisoController::class, 'update'])->middleware('permiso:permisos');
    Route::delete('/permisos/{id}', [PermisoController::class, 'destroy'])->middleware('permiso:permisos');

    // ── RolxPermiso ───────────────────────────────────
    Route::get ('/roles/{id_rol}/permisos', [RolxPermisoController::class, 'getPermisosPorRol'])->middleware('permiso:perfiles');
    Route::post('/roles/{id_rol}/permisos', [RolxPermisoController::class, 'asignarPermisos'])->middleware('permiso:perfiles');

    // ── Empleados ─────────────────────────────────────
    Route::get   ('/empleados',      [EmpleadoController::class, 'index'])->middleware('permiso:empleados');
    Route::get   ('/empleados/{id}', [EmpleadoController::class, 'show'])->middleware('permiso:empleados');
    Route::post  ('/empleados',      [EmpleadoController::class, 'store'])->middleware('permiso:empleados');
    Route::put   ('/empleados/{id}', [EmpleadoController::class, 'update'])->middleware('permiso:empleados');
    Route::delete('/empleados/{id}', [EmpleadoController::class, 'destroy'])->middleware('permiso:empleados');
});