<?php
namespace App\Http\Controllers;

use App\Models\Suscripcion;
use App\Models\Plan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class SuscripcionController extends Controller
{
    public function index()
    {
        $suscripciones = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->join('aseguradoras', 'suscripciones.id_aseguradora', '=', 'aseguradoras.id')
            ->select(
                'suscripciones.*',
                'planes.nombre as plan',
                'planes.precio',
                'aseguradoras.nombre as aseguradora'
            )
            ->get();

        return response()->json($suscripciones);
    }

    public function getByAseguradora($id_aseguradora)
    {
        $suscripcion = DB::table('suscripciones')
            ->join('planes', 'suscripciones.id_plan', '=', 'planes.id')
            ->select('suscripciones.*', 'planes.nombre as plan', 'planes.precio')
            ->where('suscripciones.id_aseguradora', $id_aseguradora)
            ->orderBy('suscripciones.id', 'desc')
            ->first();

        return response()->json($suscripcion);
    }

    public function activar(Request $request, $id_aseguradora)
    {
        $request->validate(['id_plan' => 'required|integer']);

        $plan = Plan::findOrFail($request->id_plan);

        Suscripcion::where('id_aseguradora', $id_aseguradora)
            ->whereIn('estatus', ['prueba', 'activo'])
            ->update(['estatus' => 'cancelado']);

        $suscripcion = Suscripcion::create([
            'id_aseguradora' => $id_aseguradora,
            'id_plan'        => $plan->id,
            'estatus'        => 'activo',
            'fecha_inicio'   => Carbon::now(),
            'fecha_fin'      => Carbon::now()->addDays($plan->duracion_dias),
        ]);

        return response()->json(['message' => 'Suscripción activada', 'suscripcion' => $suscripcion]);
    }

    public function cancelar($id)
    {
        $suscripcion = Suscripcion::findOrFail($id);
        $suscripcion->update(['estatus' => 'cancelado']);
        return response()->json(['message' => 'Suscripción cancelada']);
    }

    public function destroy($id)
    {
        Suscripcion::findOrFail($id)->delete();
        return response()->json(['message' => 'Suscripción eliminada']);
    }
}