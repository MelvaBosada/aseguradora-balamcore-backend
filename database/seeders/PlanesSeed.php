<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PlanesSeed extends Seeder
{
    public function run(): void
    {
        // ── MÓDULOS ──────────────────────────────────────
        $modulos = [
            ['nombre' => 'Dashboard',       'clave' => 'dashboard',       'orden' => 1],
            ['nombre' => 'Pólizas',         'clave' => 'polizas',         'orden' => 2],
            ['nombre' => 'Siniestros',      'clave' => 'siniestros',      'orden' => 3],
            ['nombre' => 'Indemnizaciones', 'clave' => 'indemnizaciones', 'orden' => 4],
            ['nombre' => 'Pagos',           'clave' => 'pagos',           'orden' => 5],
            ['nombre' => 'Clientes',        'clave' => 'clientes',        'orden' => 6],
            ['nombre' => 'Empleados',       'clave' => 'empleados',       'orden' => 7],
            ['nombre' => 'Bienes',          'clave' => 'bienes',          'orden' => 8],
            ['nombre' => 'Coberturas',      'clave' => 'coberturas',      'orden' => 9],
            ['nombre' => 'Evaluaciones',    'clave' => 'evaluaciones',    'orden' => 10],
            ['nombre' => 'Perfiles',        'clave' => 'perfiles',        'orden' => 11],
            ['nombre' => 'Permisos',        'clave' => 'permisos',        'orden' => 12],
            ['nombre' => 'Reportes',        'clave' => 'reportes',        'orden' => 13],
            ['nombre' => 'Pólizas por vencer', 'clave' => 'polizasV',     'orden' => 14],
            ['nombre' => 'Renovaciones',    'clave' => 'renovaciones',    'orden' => 15],
        ];

        foreach ($modulos as $m) {
            DB::table('modulos')->insertOrIgnore([
                'nombre'  => $m['nombre'],
                'clave'   => $m['clave'],
                'orden'   => $m['orden'],
                'estatus' => 'activo',
            ]);
        }

        // ── PLANES ───────────────────────────────────────
        $planBasicoId = DB::table('planes')->insertGetId([
            'nombre'        => 'Básico',
            'descripcion'   => 'Ideal para comenzar a gestionar tu aseguradora',
            'precio'        => 299.00,
            'duracion_dias' => 30,
            'dias_prueba'   => 15,
            'estatus'       => 'activo',
            'fecha_creacion'=> Carbon::now(),
        ]);

        $planEstandarId = DB::table('planes')->insertGetId([
            'nombre'        => 'Estándar',
            'descripcion'   => 'Para aseguradoras en crecimiento',
            'precio'        => 499.00,
            'duracion_dias' => 30,
            'dias_prueba'   => 15,
            'estatus'       => 'activo',
            'fecha_creacion'=> Carbon::now(),
        ]);

        $planProId = DB::table('planes')->insertGetId([
            'nombre'        => 'Pro',
            'descripcion'   => 'Acceso completo a todas las funcionalidades',
            'precio'        => 749.00,
            'duracion_dias' => 30,
            'dias_prueba'   => 15,
            'estatus'       => 'activo',
            'fecha_creacion'=> Carbon::now(),
        ]);

        // ── MÓDULOS POR PLAN ──────────────────────────────
        // Básico: clientes, bienes, coberturas, reportes, evaluaciones, polizas, siniestros, indemnizaciones
        $modulosBasico = ['clientes', 'bienes', 'coberturas', 'reportes', 'evaluaciones', 'polizas', 'siniestros', 'indemnizaciones'];

        // Estándar: básico + dashboard, empleados
        $modulosEstandar = array_merge($modulosBasico, ['dashboard', 'empleados']);

        // Pro: todo
        $modulosPro = ['dashboard', 'polizas', 'siniestros', 'indemnizaciones', 'pagos', 'clientes', 'empleados', 'bienes', 'coberturas', 'evaluaciones', 'perfiles', 'permisos', 'reportes', 'polizasV', 'renovaciones'];

        $asignar = function($planId, $claves) {
            foreach ($claves as $clave) {
                $modulo = DB::table('modulos')->where('clave', $clave)->first();
                if ($modulo) {
                    DB::table('planxmodulo')->insertOrIgnore([
                        'id_plan'   => $planId,
                        'id_modulo' => $modulo->id,
                    ]);
                }
            }
        };

        $asignar($planBasicoId,   $modulosBasico);
        $asignar($planEstandarId, $modulosEstandar);
        $asignar($planProId,      $modulosPro);
    }
}