<?php
namespace App\Http\Controllers;

use App\Models\Plan;
use App\Models\PlanxModulo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class PlanController extends Controller
{
    public function index()
    {
        $planes = Plan::where('estatus', 'activo')->get();
        return response()->json($planes);
    }

    public function show($id)
    {
        $plan = Plan::findOrFail($id);
        return response()->json($plan);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nombre'        => 'required|string|max:100',
            'descripcion'   => 'nullable|string|max:255',
            'precio'        => 'required|numeric',
            'duracion_dias' => 'required|integer',
            'dias_prueba'   => 'nullable|integer',
        ]);

        $plan = Plan::create([
            'nombre'         => $request->nombre,
            'descripcion'    => $request->descripcion ?? null,
            'precio'         => $request->precio,
            'duracion_dias'  => $request->duracion_dias,
            'dias_prueba'    => $request->dias_prueba ?? 15,
            'stripe_price_id'=> $request->stripe_price_id ?? null,
            'estatus'        => 'activo',
            'fecha_creacion' => Carbon::now(),
        ]);

        return response()->json(['message' => 'Plan creado', 'plan' => $plan], 201);
    }

    public function update(Request $request, $id)
    {
        $plan = Plan::findOrFail($id);
        $plan->update($request->all());
        return response()->json(['message' => 'Plan actualizado', 'plan' => $plan]);
    }

    public function destroy($id)
    {
        Plan::findOrFail($id)->delete();
        return response()->json(['message' => 'Plan eliminado']);
    }

    public function getModulos($id_plan)
    {
        $modulos = DB::table('planxmodulo')
            ->join('modulos', 'planxmodulo.id_modulo', '=', 'modulos.id')
            ->where('planxmodulo.id_plan', $id_plan)
            ->select('modulos.*')
            ->get();
        return response()->json($modulos);
    }

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