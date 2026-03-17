<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AseguradoraController;
use App\Http\Controllers\UsuarioController;
use Illuminate\Support\Facades\Route;

// ── Auth (públicas) ──────────────────────────────
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