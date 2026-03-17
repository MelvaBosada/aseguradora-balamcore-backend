<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RolxPermiso extends Model
{
    public $timestamps = false;

    protected $table = 'rolxpermiso';

    protected $fillable = [
        'id_rol',
        'id_permiso',
    ];
}