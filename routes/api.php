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
use App\Http\Controllers\PolizaVencerController;
use App\Http\Controllers\CoberturaController;
use App\Http\Controllers\BienesController;
use App\Http\Controllers\ReportesController;
use App\Http\Controllers\IndemnizacionesController;
use App\Http\Controllers\ClienteController;
use App\Http\Controllers\PolizaController;
use App\Http\Controllers\SiniestroController;
use App\Http\Controllers\RenovacionController;
use App\Http\Controllers\EvaluacionController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\PlanxModuloController;
use App\Http\Controllers\EvidenciasController;
use App\Http\Controllers\ExportController;
use Illuminate\Support\Facades\Route;

// ── Auth públicas ──────────────────────────────
Route::post('/enviar-otp',    [AuthController::class, 'enviarOtp']);
Route::post('/verificar-otp', [AuthController::class, 'verificarOtp']);
Route::post('/registro',      [AuthController::class, 'registro']);
Route::post('/login',         [AuthController::class, 'login']);

// ── Rutas protegidas con Sanctum ───────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    // ==================================================
    // RUTAS LIBRES DE SUSCRIPCIÓN (Para poder pagar/administrar aunque esté vencida)
    // ==================================================

    // ── Suscripciones ─────────────────────────────────────
    Route::get('/suscripciones', [SuscripcionController::class, 'index']);
    Route::get('/suscripciones/{id_aseguradora}', [SuscripcionController::class, 'getByAseguradora']);
    Route::post('/suscripciones/{id_aseguradora}/activar', [SuscripcionController::class, 'activar']);
    Route::put('/suscripciones/{id}/cancelar', [SuscripcionController::class, 'cancelar']);
    Route::delete('/suscripciones/{id}', [SuscripcionController::class, 'destroy']);
    Route::get('/suscripcion/actual', [SuscripcionController::class, 'actual']);
    Route::post('/suscripcion/checkout', [SuscripcionController::class, 'crearCheckout']);
    Route::post('/suscripcion/verificar-pago', [SuscripcionController::class, 'verificarPago']);
    Route::get('/suscripcion/limite/{tipo}', [SuscripcionController::class, 'verificarLimite']);
    Route::post('/suscripcion/cotizar', [SuscripcionController::class, 'cotizarCambio']);
    Route::post('/suscripcion/cancelar-programada', [SuscripcionController::class, 'cancelarProgramada']);

    // ── Planes y Módulos ────────────────────────────────────────────
    Route::get('/planes', [PlanController::class, 'index']);
    Route::get('/planes/{id}', [PlanController::class, 'show']);
    Route::post('/planes', [PlanController::class, 'store']);
    Route::put('/planes/{id}', [PlanController::class, 'update']);
    Route::delete('/planes/{id}', [PlanController::class, 'destroy']);
    
    Route::get('/planes/{id_plan}/modulos', [PlanxModuloController::class, 'getModulosPorPlan']);
    Route::post('/planes/{id_plan}/modulos', [PlanxModuloController::class, 'asignarModulos']);

    Route::get('/modulos', [ModuloController::class, 'index']);
    Route::get('/modulos/{id}', [ModuloController::class, 'show']);
    Route::post('/modulos', [ModuloController::class, 'store']);
    Route::put('/modulos/{id}', [ModuloController::class, 'update']);
    Route::delete('/modulos/{id}', [ModuloController::class, 'destroy']);

    // ── Configuración Administrativa (Roles, Permisos, Usuarios, Aseguradoras) ──
    Route::get('/roles', [RolController::class, 'index']);
    Route::post('/roles', [RolController::class, 'store']);
    Route::put('/roles/{id}', [RolController::class, 'update']);
    Route::delete('/roles/{id}', [RolController::class, 'destroy']);

    Route::get('/permisos', [PermisoController::class, 'index'])->middleware('permiso:permisos');
    Route::post('/permisos', [PermisoController::class, 'store'])->middleware('permiso:permisos');
    Route::put('/permisos/{id}', [PermisoController::class, 'update'])->middleware('permiso:permisos');
    Route::delete('/permisos/{id}', [PermisoController::class, 'destroy'])->middleware('permiso:permisos');

    Route::get('/roles/{id_rol}/permisos', [RolxPermisoController::class, 'getPermisosPorRol'])->middleware('permiso:perfiles');
    Route::post('/roles/{id_rol}/permisos', [RolxPermisoController::class, 'asignarPermisos'])->middleware('permiso:perfiles');

    Route::get('/usuarios', [UsuarioController::class, 'index']);
    Route::get('/usuarios/{id}', [UsuarioController::class, 'show']);
    Route::post('/usuarios', [UsuarioController::class, 'store']);
    Route::put('/usuarios/{id}', [UsuarioController::class, 'update']);
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy']);

    Route::get('/aseguradoras', [AseguradoraController::class, 'index']);
    Route::get('/aseguradoras/{id}', [AseguradoraController::class, 'show']);
    Route::post('/aseguradoras', [AseguradoraController::class, 'store']);
    Route::put('/aseguradoras/{id}', [AseguradoraController::class, 'update']);
    Route::delete('/aseguradoras/{id}', [AseguradoraController::class, 'destroy']);

    // ── SuperAdministrador ─────────────────────────────────────
    Route::prefix('superadmin')->group(function () {
        Route::get('/dashboard',                   [SuperAdminController::class, 'dashboard']);
        Route::get('/aseguradoras',                [SuperAdminController::class, 'aseguradoras']);
        Route::patch('/aseguradoras/{id}/estatus', [SuperAdminController::class, 'cambiarEstatusAseguradora']);
    });

    // ── Evidencias ─────────────────────────────────────
    Route::get('/evidencias', [EvidenciasController::class, 'index']);
    Route::get('/evidencias/{id}', [EvidenciasController::class, 'show']);
    Route::post('/evidencias', [EvidenciasController::class, 'store']);
    Route::put('/evidencias/{id}', [EvidenciasController::class, 'update']);
    Route::delete('/evidencias/{id}', [EvidenciasController::class, 'destroy']);
    Route::post('/evidencias/{id}/validar', [EvidenciasController::class, 'validar']);
    Route::get('/evidencias/{id}/descargar', [EvidenciasController::class, 'descargar']);
    Route::get('/siniestros/{id_siniestro}/evidencias', [EvidenciasController::class, 'getBySiniestro']);


    // ==================================================
    // DATOS DE OPERACIÓN (REQUIEREN SUSCRIPCIÓN VIGENTE)
    // ==================================================
    Route::middleware('suscripcion.vigente')->group(function () {
        
        // ── Clientes ─────────────────────────────────────
        Route::get('/clientes', [ClienteController::class, 'index']);
        Route::get('/clientes/{id}', [ClienteController::class, 'show']);
        Route::post('/clientes', [ClienteController::class, 'store']);
        Route::put('/clientes/{id}', [ClienteController::class, 'update']);
        Route::delete('/clientes/{id}', [ClienteController::class, 'destroy']);

        // ── Pólizas ──────────────────────────────────────
        Route::get('/polizas/buscar-ultima-por-rfc', [PolizaController::class, 'getUltimaPolizaPorRfc']);
        Route::post('/polizas/{id}/asignar-bienes', [PolizaController::class, 'asignarBienes']);
        Route::get('/polizas/buscar-cliente', [PolizaController::class, 'buscarClientePorRfc']);
        Route::apiResource('polizas', PolizaController::class);

        // ── Siniestros ──────────────────────────────────────
        Route::get('/siniestros', [SiniestroController::class, 'index']);
        Route::get('/siniestros/reportes/por-id/{reporteId}', [SiniestroController::class, 'getReportePorId']);
        Route::get('/siniestros/reportes/por-rfc', [SiniestroController::class, 'getReportesPorRfc']);
        Route::get('/siniestros/polizas/por-rfc', [SiniestroController::class, 'getPolizasPorRfc']);
        Route::get('/siniestros/reportes/por-poliza/{polizaId}', [SiniestroController::class, 'getReportesPorPoliza']);
        Route::get('/siniestros/{id}', [SiniestroController::class, 'getDetalleCompleto']);
        Route::post('/siniestros', [SiniestroController::class, 'store']);
        Route::put('/siniestros/{id}', [SiniestroController::class, 'update']);
        Route::delete('/siniestros/{id}', [SiniestroController::class, 'destroy']);

        // ── Bienes ─────────────────────────────────────
        Route::get('/bienes/cliente/{clienteId}', [BienesController::class, 'getByClienteId']);
        Route::get('/bienes', [BienesController::class, 'index']);
        Route::get('/bienes/{id}', [BienesController::class, 'show']);
        Route::post('/bienes', [BienesController::class, 'store']);
        Route::put('/bienes/{id}', [BienesController::class, 'update']);
        Route::delete('/bienes/{id}', [BienesController::class, 'destroy']);
        Route::delete('bienes/{id}/foto', [BienesController::class, 'destroyFoto']);

        // ── Cobertura ─────────────────────────────────
        Route::get('/coberturas', [CoberturaController::class, 'index']);
        Route::get('/coberturas/{id}', [CoberturaController::class, 'show']);
        Route::post('/coberturas', [CoberturaController::class, 'store']);
        Route::put('/coberturas/{id}', [CoberturaController::class, 'update']);
        Route::delete('/coberturas/{id}', [CoberturaController::class, 'destroy']);

        // ── Empleados ─────────────────────────────────────
        Route::get('/empleados', [EmpleadoController::class, 'index'])->middleware('permiso:empleados');
        Route::get('/empleados/{id}', [EmpleadoController::class, 'show'])->middleware('permiso:empleados');
        Route::post('/empleados', [EmpleadoController::class, 'store'])->middleware('permiso:empleados');
        Route::put('/empleados/{id}', [EmpleadoController::class, 'update'])->middleware('permiso:empleados');
        Route::delete('/empleados/{id}', [EmpleadoController::class, 'destroy'])->middleware('permiso:empleados');

        // ── Reportes ─────────────────────────────────────
        Route::prefix('reportes')->group(function () {
            Route::get('/', [ReportesController::class, 'index']);        
            Route::post('/', [ReportesController::class, 'store']);       
            Route::get('/{id}', [ReportesController::class, 'show']);       
            Route::put('/{id}', [ReportesController::class, 'update']);     
            Route::delete('/{id}', [ReportesController::class, 'destroy']); 
        });

        // ── Evaluaciones ─────────────────────────────────────
        Route::get('/evaluaciones', [EvaluacionController::class, 'index']);
        Route::get('/evaluaciones/{id}', [EvaluacionController::class, 'show']);

        // ── Indemnizaciones ───────────────────────────────────
        Route::get('/indemnizaciones',          [IndemnizacionesController::class, 'index']);
        Route::get('/indemnizaciones/{id}',     [IndemnizacionesController::class, 'show']);
        Route::post('/indemnizaciones',         [IndemnizacionesController::class, 'store']);
        Route::put('/indemnizaciones/{id}',     [IndemnizacionesController::class, 'update']);
        Route::delete('/indemnizaciones/{id}',  [IndemnizacionesController::class, 'destroy']);

        // ── Renovaciones de Pólizas ────────────────────────────
        Route::get('/renovaciones', [RenovacionController::class, 'index']);
        Route::get('/renovaciones/poliza/{id}/detalle', [RenovacionController::class, 'getDetalleParaRenovar']);
        Route::post('/renovaciones', [RenovacionController::class, 'store']);
        Route::get('/renovaciones/poliza/{polizaId}/historial', [RenovacionController::class, 'getHistorial']);

        // ── Polizas por vencer ─────────────────────────────────────────
        Route::get('/polizas-vencer', [PolizaVencerController::class, 'index']);
        Route::post('/polizas-vencer/avisar', [PolizaVencerController::class, 'avisar']);

        // ── Dashboard ─────────────────────────────────────────
        Route::prefix('dashboard')->group(function () {
            Route::get('/kpis', [DashboardController::class, 'kpis']);
            Route::get('/ingresos', [DashboardController::class, 'ingresos']);
            Route::get('/polizas-por-tipo', [DashboardController::class, 'polizasPorTipo']);
            Route::get('/tendencia-siniestros', [DashboardController::class, 'tendenciaSiniestros']);
            Route::get('/ultimos-siniestros', [DashboardController::class, 'ultimosSiniestros']);
        });

        // ── Exportaciones (Excel / CSV / PDF) ──────────
        foreach (config('exportables', []) as $slug => $cfg) {
            $middleware = !empty($cfg['permiso']) ? ['permiso:' . $cfg['permiso']] : [];

            Route::get("/exportar/{$slug}", [ExportController::class, 'exportar'])
                ->defaults('catalogo', $slug)
                ->middleware($middleware);

            Route::get("/exportar/{$slug}/vista", [ExportController::class, 'vista'])
                ->defaults('catalogo', $slug)
                ->middleware($middleware);
        }

    }); // <-- FIN DEL GRUPO SUSCRIPCION VIGENTE

}); // <-- FIN DEL GRUPO SANCTUM