<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bienes extends Model 
{
    protected $table = 'bienes';
    public $timestamps = false;

    protected $fillable = [
        'id_aseguradora', 'id_cliente', 'id_poliza', 'tipo', 'descripcion', 'marca', 'modelo', 
        'numero_serie', 'valor', 'nivel_riesgo', 'fecha_adquisicion', 'estado_bienes', 
        'numero_factura', 'proveedor', 'tipo_vehiculo', 'anio', 'color', 'numero_placas', 
        'numero_motor', 'numero_vin', 'tipo_combustible', 'uso_vehiculo', 'tipo_inmueble', 
        'uso_inueble', 'colonia', 'numero_exterior', 'numero_interior', 'metros_cuadrados', 
        'num_pisos', 'tipo_construccion', 'anio_construccion', 'numero_escritura', 'ubicacion', 
        'tipo_electronico', 'numero_imei', 'color_electronico', 'tipo_maquinaria', 
        'uso_maquinaria', 'estatus'
    ];
}