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


use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\CronController;
use App\Http\Controllers\FacturacionController;
use App\Http\Middleware\SoloSuperadmin;
use App\Http\Controllers\PerfilController;
use App\Http\Controllers\ReporteEvidenciasController;
use App\Http\Controllers\PapeleraController;
use App\Http\Controllers\SiniestroDocumentosController;
use App\Http\Controllers\CatalogoDocumentosController;
use App\Http\Controllers\ConfiguracionSiniestrosController;
use App\Http\Controllers\PolizaCoberturaController;
use App\Http\Controllers\EvaluacionFlujoController;
use App\Http\Controllers\MarcaAseguradoraController;
use App\Http\Controllers\PolizaPdfController;





// ── Auth públicas ──────────────────────────────
Route::post('/enviar-otp',    [AuthController::class, 'enviarOtp']);
Route::post('/verificar-otp', [AuthController::class, 'verificarOtp']);
Route::post('/registro',      [AuthController::class, 'registro']);
Route::post('/login',         [AuthController::class, 'login']);

Route::post('/stripe/webhook',    [StripeWebhookController::class, 'handle']);
Route::get('/cron/suscripciones', [CronController::class, 'suscripciones']);

// ── Rutas protegidas con Sanctum ───────────────
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);

    // ==================================================
    // RUTAS LIBRES DE SUSCRIPCIÓN (Para poder pagar/administrar aunque esté vencida)
    // ==================================================

    // ── Suscripciones (administración) ────────────────────
    Route::get('/suscripciones', [SuscripcionController::class, 'index']);
    Route::get('/suscripciones/{id_aseguradora}', [SuscripcionController::class, 'getByAseguradora']);
    Route::post('/suscripciones/{id_aseguradora}/activar', [SuscripcionController::class, 'activar']);
    Route::put('/suscripciones/{id}/cancelar', [SuscripcionController::class, 'cancelar']);
    Route::delete('/suscripciones/{id}', [SuscripcionController::class, 'destroy']);

    // ── Suscripción del usuario ───────────────────────────
    Route::get('/suscripcion/actual',                 [SuscripcionController::class, 'actual']);
    Route::get('/suscripcion/mi-plan',                [SuscripcionController::class, 'miPlan']);
    Route::get('/suscripcion/limite/{tipo}',          [SuscripcionController::class, 'verificarLimite']);
    Route::post('/suscripcion/cotizar',               [SuscripcionController::class, 'cotizarCambio']);
    Route::post('/suscripcion/checkout',              [SuscripcionController::class, 'crearCheckout']);
    Route::post('/suscripcion/verificar-pago',        [SuscripcionController::class, 'verificarPago']);
    Route::post('/suscripcion/cancelar-cambio',       [SuscripcionController::class, 'cancelarCambio']);
    Route::post('/suscripcion/renovacion-auto',       [FacturacionController::class, 'renovacionAuto']);
    Route::post('/suscripcion/metodo-pago/checkout',  [FacturacionController::class, 'metodoPagoCheckout']);
    Route::post('/suscripcion/metodo-pago/confirmar', [FacturacionController::class, 'metodoPagoConfirmar']);
    Route::post('/suscripcion/cancelar-fin-periodo',  [FacturacionController::class, 'cancelarFinPeriodo']);
    Route::post('/suscripcion/reanudar',              [FacturacionController::class, 'reanudar']);
    Route::post('/suscripcion/reembolso',             [FacturacionController::class, 'solicitarReembolso']);
    Route::get('/suscripcion/comprobante/{id}',       [FacturacionController::class, 'comprobante']);
    Route::get('/suscripcion/factura-ejemplo/{id}',   [FacturacionController::class, 'facturaEjemplo']);

    Route::post('/tour/completar', [AuthController::class, 'completarTour']);

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

    Route::get('/usuarios',         [UsuarioController::class, 'index'])->middleware(SoloSuperadmin::class);
    Route::get('/usuarios/{id}',    [UsuarioController::class, 'show'])->middleware(SoloSuperadmin::class);
    Route::post('/usuarios',        [UsuarioController::class, 'store'])->middleware(SoloSuperadmin::class);
    Route::put('/usuarios/{id}',    [UsuarioController::class, 'update'])->middleware(SoloSuperadmin::class);
    Route::delete('/usuarios/{id}', [UsuarioController::class, 'destroy'])->middleware(SoloSuperadmin::class);

    Route::get('/aseguradoras',         [AseguradoraController::class, 'index'])->middleware(SoloSuperadmin::class);
    Route::get('/aseguradoras/{id}',    [AseguradoraController::class, 'show']);   // el controlador valida que sea la suya
    Route::post('/aseguradoras',        [AseguradoraController::class, 'store'])->middleware(SoloSuperadmin::class);
    Route::put('/aseguradoras/{id}',    [AseguradoraController::class, 'update']); // el controlador valida admin de esa aseguradora
    Route::delete('/aseguradoras/{id}', [AseguradoraController::class, 'destroy'])->middleware(SoloSuperadmin::class);
    Route::get('/aseguradora/marca', [MarcaAseguradoraController::class, 'show']);

    // ── Perfil ─────────────────────────────────────
    Route::get('/perfil',           [PerfilController::class, 'show']);
    Route::put('/perfil',           [PerfilController::class, 'update']);
    Route::post('/perfil/password', [PerfilController::class, 'password'])->middleware('throttle:5,1');
    Route::get('/perfil/sensibles', [PerfilController::class, 'sensibles'])->middleware('throttle:10,1');

    // ── SuperAdministrador ─────────────────────────────────────
    Route::prefix('superadmin')->group(function () {
        Route::get('/dashboard',                   [SuperAdminController::class, 'dashboard']);
        Route::get('/aseguradoras',                [SuperAdminController::class, 'aseguradoras']);
        Route::patch('/aseguradoras/{id}/estatus', [SuperAdminController::class, 'cambiarEstatusAseguradora']);
        Route::get('/reembolsos',                  [FacturacionController::class, 'reembolsosListar']);
        Route::post('/reembolsos/{id}/resolver',   [FacturacionController::class, 'reembolsosResolver']);
    });


    // ==================================================
    // DATOS DE OPERACIÓN (REQUIEREN SUSCRIPCIÓN VIGENTE)
    // ==================================================
    Route::middleware('suscripcion.vigente')->group(function () {

        // ── Evidencias ─────────────────────────────────────
        Route::get('/evidencias', [EvidenciasController::class, 'index']);
        Route::get('/evidencias/{id}', [EvidenciasController::class, 'show']);
        Route::post('/evidencias', [EvidenciasController::class, 'store']);
        Route::put('/evidencias/{id}', [EvidenciasController::class, 'update']);
        Route::delete('/evidencias/{id}', [EvidenciasController::class, 'destroy']);
        Route::post('/evidencias/{id}/validar', [EvidenciasController::class, 'validar']);
        Route::get('/evidencias/{id}/descargar', [EvidenciasController::class, 'descargar']);
        Route::get('/siniestros/{id_siniestro}/evidencias', [EvidenciasController::class, 'getBySiniestro']);
        Route::get('evidencias/reporte/buscar', [ReporteEvidenciasController::class, 'buscar']);
        Route::post('evidencias/reporte/generar', [ReporteEvidenciasController::class, 'generar']);
        Route::get('evidencias/{id}/historial', [EvidenciasController::class, 'historial']);


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
        Route::get('/polizas/parametros', [PolizaController::class, 'parametros']);
        Route::apiResource('polizas', PolizaController::class);
        Route::get('/polizas/{id}/documento', [PolizaController::class, 'documento']);
        Route::get('/polizas/{id}/endosos/{endosoId}/documento', [PolizaController::class, 'documentoEndoso']);
        Route::get('/polizas/{id}/pdf', [PolizaPdfController::class, 'descargar'])->whereNumber('id');
        Route::post('/polizas/{id}/cancelar', [PolizaController::class, 'cancelar'])->whereNumber('id');

        // ── Coberturas contratadas en una póliza ──────────
        Route::get('/polizas/{id}/coberturas', [PolizaCoberturaController::class, 'index']);
        Route::put('/polizas/{id}/coberturas', [PolizaCoberturaController::class, 'update']);

        // ── Siniestros ──────────────────────────────────────
        Route::get('/siniestros', [SiniestroController::class, 'index']);

        // Rutas fijas: SIEMPRE antes de /siniestros/{id}
        Route::get('/siniestros/ajustadores', [SiniestroController::class, 'getAjustadores']);
        Route::get('/siniestros/reportes/por-id/{reporteId}', [SiniestroController::class, 'getReportePorId']);
        Route::get('/siniestros/reportes/por-rfc', [SiniestroController::class, 'getReportesPorRfc']);
        Route::get('/siniestros/polizas/por-rfc', [SiniestroController::class, 'getPolizasPorRfc']);
        Route::get('/siniestros/reportes/por-poliza/{polizaId}', [SiniestroController::class, 'getReportesPorPoliza']);

        // Rutas con {id}
        Route::get('/siniestros/{id}', [SiniestroController::class, 'getDetalleCompleto'])->whereNumber('id');
        Route::post('/siniestros', [SiniestroController::class, 'store'])->middleware('permiso:siniestros');
        Route::post('/siniestros/{id}/estatus', [SiniestroController::class, 'cambiarEstatus'])->middleware('permiso:siniestros');
        Route::post('/siniestros/{id}/ajustador', [SiniestroController::class, 'asignarAjustador']); // valida 'siniestros.reasignar' en el controlador
        Route::post('/siniestros/{id}/arribo', [SiniestroController::class, 'registrarArribo'])->middleware('permiso:siniestros');
        Route::put('/siniestros/{id}', [SiniestroController::class, 'update'])->whereNumber('id')->middleware('permiso:siniestros');
        Route::delete('/siniestros/{id}', [SiniestroController::class, 'destroy'])->whereNumber('id')->middleware('permiso:siniestros');

        // ── Documentos del siniestro ──────────────────────────
        Route::get('/siniestros/{id}/documentos', [SiniestroDocumentosController::class, 'index'])->whereNumber('id');
        Route::post('/siniestros/{id}/documentos', [SiniestroDocumentosController::class, 'store'])->whereNumber('id')->middleware('permiso:siniestros');
        Route::post('/siniestros/{id}/documentos/generar', [SiniestroDocumentosController::class, 'generar'])->whereNumber('id')->middleware('permiso:siniestros');
        Route::post('/siniestros/{id}/documentos/notificar', [SiniestroDocumentosController::class, 'notificar'])->whereNumber('id')->middleware('permiso:siniestros');
        Route::get('/siniestros/{id}/documentos/{docId}/descargar', [SiniestroDocumentosController::class, 'descargar'])->whereNumber(['id', 'docId']);
        Route::post('/siniestros/{id}/documentos/{docId}/archivo', [SiniestroDocumentosController::class, 'subir'])->whereNumber(['id', 'docId'])->middleware('permiso:siniestros');
        Route::post('/siniestros/{id}/documentos/{docId}/revisar', [SiniestroDocumentosController::class, 'revisar'])->whereNumber(['id', 'docId'])->middleware('permiso:siniestros');
        Route::delete('/siniestros/{id}/documentos/{docId}', [SiniestroDocumentosController::class, 'destroy'])->whereNumber(['id', 'docId'])->middleware('permiso:siniestros');

        // ── Catálogo de documentos y configuración (escribir exige 'siniestros.configurar') ──
        Route::get('/catalogo-documentos', [CatalogoDocumentosController::class, 'index']);
        Route::post('/catalogo-documentos', [CatalogoDocumentosController::class, 'store'])->middleware('permiso:siniestros.configurar');
        Route::put('/catalogo-documentos/{id}', [CatalogoDocumentosController::class, 'update'])->middleware('permiso:siniestros.configurar');
        Route::delete('/catalogo-documentos/{id}', [CatalogoDocumentosController::class, 'destroy'])->middleware('permiso:siniestros.configurar');
        Route::get('/configuracion/siniestros', [ConfiguracionSiniestrosController::class, 'show']);
        Route::put('/configuracion/siniestros', [ConfiguracionSiniestrosController::class, 'update'])->middleware('permiso:siniestros.configurar');

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
        Route::post('/empleados/{id}/baja',      [EmpleadoController::class, 'baja'])->middleware('permiso:empleados');
        Route::post('/empleados/{id}/suspender', [EmpleadoController::class, 'suspender'])->middleware('permiso:empleados');
        Route::post('/empleados/{id}/reactivar', [EmpleadoController::class, 'reactivar'])->middleware('permiso:empleados');

        // ── Reportes ─────────────────────────────────────
        Route::prefix('reportes')->group(function () {
            Route::get('/', [ReportesController::class, 'index']);
            Route::get('/buscar-poliza', [ReportesController::class, 'buscarPoliza']); // ANTES de /{id}
            Route::post('/', [ReportesController::class, 'store']);
            Route::get('/{id}', [ReportesController::class, 'show'])->whereNumber('id');
            Route::put('/{id}', [ReportesController::class, 'update'])->whereNumber('id');
            Route::post('/{id}/validar', [ReportesController::class, 'validar'])->whereNumber('id');
            Route::post('/{id}/descartar', [ReportesController::class, 'descartar'])->whereNumber('id');
            Route::delete('/{id}', [ReportesController::class, 'destroy'])->whereNumber('id');
        });

        // ── Evaluaciones ─────────────────────────────────────
        Route::get('/evaluaciones', [EvaluacionController::class, 'index']);
        // OJO: pendientes-autorizacion va ANTES de /evaluaciones/{id}, si no Laravel la toma como un id
        Route::get('/evaluaciones/pendientes-autorizacion', [EvaluacionFlujoController::class, 'pendientes']);
        Route::get('/evaluaciones/{id}', [EvaluacionController::class, 'show']);

        // ── Evaluaciones (flujo) ─────────────────────────────
        // Capturar y enviar requiere el módulo; aprobar/devolver se valida por nivel en EvaluacionService
        // resolver y pendientes: SIN permiso:evaluaciones (un supervisor puede tener solo evaluaciones.autorizar)
        Route::post('/siniestros/{id}/evaluaciones/calcular', [EvaluacionFlujoController::class, 'calcular'])->middleware('permiso:evaluaciones');
        Route::post('/siniestros/{id}/evaluaciones',          [EvaluacionFlujoController::class, 'store'])->middleware('permiso:evaluaciones');
        Route::put('/evaluaciones/{id}',                      [EvaluacionFlujoController::class, 'update'])->middleware('permiso:evaluaciones');
        Route::post('/evaluaciones/{id}/enviar',              [EvaluacionFlujoController::class, 'enviar'])->middleware('permiso:evaluaciones');
        Route::post('/evaluaciones/{id}/resolver',            [EvaluacionFlujoController::class, 'resolver']);

        // ── Indemnizaciones (las genera el sistema) ───────────
        Route::get('/indemnizaciones',      [IndemnizacionesController::class, 'index']);
        Route::get('/indemnizaciones/{id}', [IndemnizacionesController::class, 'show']);
        Route::post('/indemnizaciones/{id}/confirmar-pago', [IndemnizacionesController::class, 'confirmarPago'])->middleware('permiso:indemnizaciones.confirmar');
        Route::post('/indemnizaciones/{id}/pagar',          [IndemnizacionesController::class, 'pagar'])->middleware('permiso:indemnizaciones.pagar');
        Route::post('/indemnizaciones/{id}/cancelar',       [IndemnizacionesController::class, 'cancelar'])->middleware('permiso:indemnizaciones.pagar');
        Route::post('/siniestros/{id}/indemnizacion/generar', [IndemnizacionesController::class, 'generar'])->middleware('permiso:indemnizaciones.pagar');

        // ── Para las pestañas del expediente ──────────────────
        Route::get('/siniestros/{id}/indemnizaciones', [IndemnizacionesController::class, 'porSiniestro']);
        Route::get('/siniestros/{id}/evaluaciones',    [EvaluacionFlujoController::class, 'porSiniestro']);

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

        // ── Papelera (solo administrador; lo valida el controlador) ──
        Route::get('/papelera/resumen', [PapeleraController::class, 'resumen']);
        Route::get('/papelera/{modulo}', [PapeleraController::class, 'index']);
        Route::post('/papelera/{modulo}/{id}/restaurar', [PapeleraController::class, 'restaurar']);
        Route::delete('/papelera/{modulo}/{id}', [PapeleraController::class, 'destroy']);

        // ── Exportaciones (Excel / CSV / PDF) ──
        foreach (config('exportables', []) as $slug => $cfg) {
            $middleware = !empty($cfg['permiso']) ? ['permiso:' . $cfg['permiso']] : [];

            Route::get("/exportar/{$slug}", [ExportController::class, 'descargar'])
                ->defaults('catalogo', $slug)
                ->middleware($middleware);

            Route::get("/exportar/{$slug}/vista", [ExportController::class, 'vista'])
                ->defaults('catalogo', $slug)
                ->middleware($middleware);
        }

    }); // <-- FIN DEL GRUPO SUSCRIPCION VIGENTE

}); // <-- FIN DEL GRUPO SANCTUM