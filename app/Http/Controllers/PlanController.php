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
        $planes = DB::table('planes')
            ->leftJoin('planxmodulo', 'planes.id', '=', 'planxmodulo.id_plan')
            ->select(
                'planes.id', 'planes.nombre', 'planes.descripcion',
                'planes.precio', 'planes.precio_anual',
                'planes.duracion_dias', 'planes.dias_prueba',
                'planes.stripe_price_id',
                'planes.limite_polizas_vencer_dia',
                'planes.limite_siniestros_mes',
                'planes.limite_bienes_por_cliente',
                'planes.limite_roles',
                'planes.pdf_ilimitado',
                'planes.soporte_prioritario',
                'planes.estatus',
                DB::raw('COUNT(planxmodulo.id_modulo) as modulos_count')
            )
            ->where('planes.estatus', 'activo')
            ->groupBy(
                'planes.id', 'planes.nombre', 'planes.descripcion',
                'planes.precio', 'planes.precio_anual',
                'planes.duracion_dias', 'planes.dias_prueba',
                'planes.stripe_price_id',
                'planes.limite_polizas_vencer_dia',
                'planes.limite_siniestros_mes',
                'planes.limite_bienes_por_cliente',
                'planes.limite_roles',
                'planes.pdf_ilimitado',
                'planes.soporte_prioritario',
                'planes.estatus'
            )
            ->get();

        return response()->json($planes);
    }

    public function show($id)
    {
        $plan = Plan::findOrFail($id);

        $modulos = DB::table('planxmodulo')
            ->join('modulos', 'planxmodulo.id_modulo', '=', 'modulos.id')
            ->where('planxmodulo.id_plan', $id)
            ->select('modulos.*')
            ->get();

        $plan->modulos = $modulos;

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
            'limite_roles'  => 'nullable|integer|min:0',
        ]);

        $plan = Plan::create([
            'nombre'         => $request->nombre,
            'descripcion'    => $request->descripcion ?? null,
            'precio'         => $request->precio,
            'precio_anual'   => $request->precio_anual ?? null,
            'duracion_dias'  => $request->duracion_dias,
            'dias_prueba'    => $request->dias_prueba ?? 15,
            'stripe_price_id'=> $request->stripe_price_id ?? null,
            'limite_polizas_vencer_dia' => $request->limite_polizas_vencer_dia ?? null,
            'limite_siniestros_mes'     => $request->limite_siniestros_mes ?? null,
            'limite_bienes_por_cliente' => $request->limite_bienes_por_cliente ?? null,
            'limite_roles'              => $request->input('limite_roles', 0),
            'pdf_ilimitado'             => $request->boolean('pdf_ilimitado'),
            'soporte_prioritario'       => $request->boolean('soporte_prioritario'),
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
}