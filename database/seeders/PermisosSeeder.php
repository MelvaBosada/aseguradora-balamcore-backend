<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
class PermisosSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('permisos')->insertOrIgnore([
            ['nombre' => 'Dashboard',       'descripcion' => 'Acceso al panel principal',                    'clave' => 'dashboard'],
            ['nombre' => 'Pólizas',         'descripcion' => 'Gestión de pólizas de seguro',                 'clave' => 'polizas'],
            ['nombre' => 'Siniestros',      'descripcion' => 'Gestión de siniestros y reportes de daños',    'clave' => 'siniestros'],
            ['nombre' => 'Indemnizaciones', 'descripcion' => 'Gestión de pagos por indemnización',           'clave' => 'indemnizaciones'],
            ['nombre' => 'Pagos',           'descripcion' => 'Control de pagos y cobranza',                  'clave' => 'pagos'],
            ['nombre' => 'Clientes',        'descripcion' => 'Gestión del catálogo de clientes',             'clave' => 'clientes'],
            ['nombre' => 'Empleados',       'descripcion' => 'Gestión del catálogo de empleados',            'clave' => 'empleados'],
            ['nombre' => 'Bienes',          'descripcion' => 'Gestión de bienes asegurados',                 'clave' => 'bienes'],
            ['nombre' => 'Coberturas',      'descripcion' => 'Gestión de coberturas disponibles',            'clave' => 'coberturas'],
            ['nombre' => 'Evaluaciones',    'descripcion' => 'Evaluaciones periciales de siniestros',        'clave' => 'evaluaciones'],
            ['nombre' => 'Perfiles',        'descripcion' => 'Gestión de roles del sistema',                 'clave' => 'perfiles'],
            ['nombre' => 'Permisos',        'descripcion' => 'Gestión de permisos por rol',                  'clave' => 'permisos'],
            ['nombre' => 'Reportes',        'descripcion' => 'Acceso a reportes y estadísticas',             'clave' => 'reportes'],
            ['nombre' => 'Renovaciones',    'descripcion' => 'Gestión de renovaciones y pólizas por vencer', 'clave' => 'renovaciones'],
        ]);
    }
}