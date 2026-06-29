<?php

namespace App\Http\Controllers;

use App\Models\PlanxModulo;
use App\Models\Modulo;
use Illuminate\Http\Request;

class PlanxModuloController extends Controller
{
    // GET /planes/{id_plan}/modulos — obtener módulos asignados a un plan
    public function getModulosPorPlan($id_plan)
    {
        $modulos = PlanxModulo::where('id_plan', $id_plan)
            ->join('modulos', 'planxmodulo.id_modulo', '=', 'modulos.id')
            ->select('modulos.*')
            ->get();

        return response()->json($modulos);
    }

    // POST /planes/{id_plan}/modulos — asignar módulos a un plan
    public function asignarModulos(Request $request, $id_plan)
    {
        $request->validate(['modulos' => 'required|array']);

        PlanxModulo::where('id_plan', $id_plan)->delete();

        foreach ($request->modulos as $id_modulo) {
            PlanxModulo::create([
                'id_plan'   => $id_plan,
                'id_modulo' => $id_modulo,
            ]);
        }

        return response()->json(['message' => 'Módulos asignados correctamente']);
    }
}
