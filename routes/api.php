<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AseguradoraController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\PermisoController;
use App\Http\Controllers\RolxPermisoController;
use App\Http\Controllers\EmpleadoController;
use App\Http\Controllers\ModuloController;
use App\Http\Controllers\SuscripcionController;
use App\Http\Controllers\PlanController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ClienteController;
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


    // ── Dashboard ─────────────────────────────────────────
Route::prefix('dashboard')->group(function () {
    Route::get('/kpis',                 [DashboardController::class, 'kpis']);
    Route::get('/ingresos',             [DashboardController::class, 'ingresos']);
    Route::get('/polizas-por-tipo',     [DashboardController::class, 'polizasPorTipo']);
    Route::get('/tendencia-siniestros', [DashboardController::class, 'tendenciaSiniestros']);
    Route::get('/ultimos-siniestros',   [DashboardController::class, 'ultimosSiniestros']);
});
});


// ── Planes ────────────────────────────────────────────
Route::get   ('/planes',                         [PlanController::class, 'index']);
Route::get   ('/planes/{id}',                    [PlanController::class, 'show']);
Route::post  ('/planes',                         [PlanController::class, 'store']);
Route::put   ('/planes/{id}',                    [PlanController::class, 'update']);
Route::delete('/planes/{id}',                    [PlanController::class, 'destroy']);
Route::get   ('/planes/{id_plan}/modulos',       [PlanController::class, 'getModulos']);
Route::post  ('/planes/{id_plan}/modulos',       [PlanController::class, 'asignarModulos']);

// ── Módulos ───────────────────────────────────────────
Route::get   ('/modulos',      [ModuloController::class, 'index']);
Route::get   ('/modulos/{id}', [ModuloController::class, 'show']);
Route::post  ('/modulos',      [ModuloController::class, 'store']);
Route::put   ('/modulos/{id}', [ModuloController::class, 'update']);
Route::delete('/modulos/{id}', [ModuloController::class, 'destroy']);

// ── Suscripciones ─────────────────────────────────────
Route::get   ('/suscripciones',                          [SuscripcionController::class, 'index']);
Route::get   ('/suscripciones/{id_aseguradora}',         [SuscripcionController::class, 'getByAseguradora']);
Route::post  ('/suscripciones/{id_aseguradora}/activar', [SuscripcionController::class, 'activar']);
Route::put   ('/suscripciones/{id}/cancelar',            [SuscripcionController::class, 'cancelar']);
Route::delete('/suscripciones/{id}',                     [SuscripcionController::class, 'destroy']);

// Clientes
Route::middleware('auth:sanctum')->group(function () {
    // Clientes
    Route::get('/clientes', [ClienteController::class, 'index']);
    Route::get('/clientes/{id}', [ClienteController::class, 'show']);
    Route::post('/clientes', [ClienteController::class, 'store']);
    Route::put('/clientes/{id}', [ClienteController::class, 'update']);
    Route::delete('/clientes/{id}', [ClienteController::class, 'destroy']);
});