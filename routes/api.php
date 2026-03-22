<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AseguradoraController;
use App\Http\Controllers\UsuarioController;
use App\Http\Controllers\RolController;
use App\Http\Controllers\PermisoController;
use App\Http\Controllers\EmpleadoController;
use Illuminate\Support\Facades\Route;

// ── Auth  ──────────────────────────────
Route::post('/enviar-otp',    [AuthController::class, 'enviarOtp']);
Route::post('/verificar-otp', [AuthController::class, 'verificarOtp']);
Route::post('/registro',      [AuthController::class, 'registro']);
Route::post('/login',         [AuthController::class, 'login']);
Route::post('/logout',        [AuthController::class, 'logout']);

// ── CRUD Aseguradoras ────────────────────────────
Route::get   ('/aseguradoras',      [AseguradoraController::class, 'index']);
Route::get   ('/aseguradoras/{id}', [AseguradoraController::class, 'show']);
Route::post  ('/aseguradoras',      [AseguradoraController::class, 'store']);
Route::put   ('/aseguradoras/{id}', [AseguradoraController::class, 'update']);
Route::delete('/aseguradoras/{id}', [AseguradoraController::class, 'destroy']);

// ── CRUD Usuarios ────────────────────────────────
Route::get   ('/usuarios',      [UsuarioController::class, 'index']);
Route::get   ('/usuarios/{id}', [UsuarioController::class, 'show']);
Route::post  ('/usuarios',      [UsuarioController::class, 'store']);
Route::put   ('/usuarios/{id}', [UsuarioController::class, 'update']);
Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);


// ── CRUD Roles ────────────────────────────────────────
Route::get   ('/roles',         [RolController::class, 'index']);
Route::post  ('/roles',         [RolController::class, 'store']);
Route::put   ('/roles/{id}',    [RolController::class, 'update']);
Route::delete('/roles/{id}',    [RolController::class, 'destroy']);

// ── CRUD Permisos ─────────────────────────────────────
Route::get   ('/permisos',                      [PermisoController::class, 'index']);
Route::post  ('/permisos',                      [PermisoController::class, 'store']);
Route::put   ('/permisos/{id}',                 [PermisoController::class, 'update']);
Route::delete('/permisos/{id}',                 [PermisoController::class, 'destroy']);
Route::get   ('/roles/{id_rol}/permisos',       [PermisoController::class, 'getPermisosPorRol']);
Route::post  ('/roles/{id_rol}/permisos',       [PermisoController::class, 'asignarPermisos']);

// ── CRUD Empleados ─────────────────────────────────────
Route::get   ('/empleados',      [EmpleadoController::class, 'index']);
Route::get   ('/empleados/{id}', [EmpleadoController::class, 'show']);
Route::post  ('/empleados',      [EmpleadoController::class, 'store']);
Route::put   ('/empleados/{id}', [EmpleadoController::class, 'update']);
Route::delete('/empleados/{id}', [EmpleadoController::class, 'destroy']);