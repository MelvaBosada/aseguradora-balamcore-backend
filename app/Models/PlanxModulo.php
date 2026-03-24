<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PlanxModulo extends Model
{
    public $timestamps = false;
    protected $table = 'planxmodulo';
    protected $fillable = [
        'id_plan',
        'id_modulo'
    ];
}
