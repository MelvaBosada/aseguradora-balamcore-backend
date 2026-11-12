<?php

namespace App\Http\Controllers;

use App\Models\Indemnizaciones;
use App\Models\Siniestro;
use App\Services\IndemnizacionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Las indemnizaciones ya NO se crean, editan ni borran a mano: las genera el sistema al aprobarse el
 * dictamen y solo avanzan con confirmar-pago, pagar y cancelar. El monto no se puede tocar.
 */
class IndemnizacionesController extends Controller
{
    public function __construct(private IndemnizacionService $servicio)
    {
    }

    /** Con "indemnizaciones.pagar" (tesorería) se ven todas las de la aseguradora; si no, solo las de "mis casos". */
    private function consulta(Request $request)
    {
        $user = $request->user();

        $q = DB::table('indemnizaciones as i')
            ->join('siniestros as s', 'i.id_siniestro', '=', 's.id')
            ->join('reportes as r', 's.id_reporte', '=', 'r.id')
            ->leftJoin('polizas as p', 'r.id_poliza', '=', 'p.id')
            ->leftJoin('evaluaciones as ev', 'i.id_evaluaciones', '=', 'ev.id')
            ->leftJoin('empleados as e', 'ev.id_empleado', '=', 'e.id')
            ->leftJoin('clientes as c', 'p.id_cliente', '=', 'c.id')
            ->where('s.id_aseguradora', $user->id_aseguradora);

        if (!$user->tienePermiso('indemnizaciones.pagar')) {
            $q->whereIn('i.id_siniestro', Siniestro::visiblePara($user)->select('siniestros.id'));
        }

        return $q->select(
            'i.*',
            'r.nombre_reportante as asegurado_nombre',
            'p.numero_poliza as poliza_numero',
            'r.telefono_reportante as asegurado_tel',
            'r.ubicacion_accidente as lugar',
            'r.fecha_ocurrencia',
            'c.rfc as asegurado_rfc',
            'c.domicilio as asegurado_domicilio',
            'c.correo as asegurado_correo',
            'c.nombre as cliente_nombre',
            'c.apellidos as cliente_apellidos',
            's.numero_siniestro',
            's.estatus as siniestro_estatus',
            's.tipo as tipo_siniestro',
            's.monto_reclamado',
            's.deducible_aplicado',
            's.prioridad',
            'ev.dictamen as resultado_eval',
            'ev.fecha_dictamen',
            'e.nombre as ajustador_nom',
            'e.apellidos as ajustador_ape'
        );
    }

    private function formatear($fila)
    {
        if ($fila && is_string($fila->desglose ?? null)) {
            $fila->desglose = json_decode($fila->desglose, true);
        }

        return $fila;
    }

    /** Indemnización de MI aseguradora (y de mis casos, si no soy de tesorería). */
    private function buscar(Request $request, $id): Indemnizaciones
    {
        $ids = $this->consulta($request)->where('i.id', $id)->pluck('i.id');
        abort_if($ids->isEmpty(), 404, 'Indemnización no encontrada.');

        return Indemnizaciones::findOrFail($id);
    }

    public function index(Request $request)
    {
        $filas = $this->consulta($request)->orderByDesc('i.id')->get()->map(fn ($f) => $this->formatear($f));

        return response()->json($filas->values());
    }

    public function show(Request $request, $id)
    {
        $fila = $this->consulta($request)->where('i.id', $id)->first();

        return $fila
            ? response()->json($this->formatear($fila))
            : response()->json(['message' => 'No encontrada'], 404);
    }

    /** GET /siniestros/{id}/indemnizaciones — para la pestaña del expediente. */
    public function porSiniestro(Request $request, $id)
    {
        $user = $request->user();

        $siniestro = Siniestro::where('siniestros.id', $id)->where('siniestros.id_aseguradora', $user->id_aseguradora)->firstOrFail();

        // Quien paga ve el expediente completo; el resto, solo sus casos
        if (!$user->tienePermiso('indemnizaciones.pagar')) {
            Siniestro::visiblePara($user)->where('siniestros.id', $siniestro->id)->firstOrFail();
        }

        $filas = $this->consulta($request)->where('i.id_siniestro', $siniestro->id)->orderByDesc('i.id')->get()
            ->map(fn ($f) => $this->formatear($f));

        return response()->json($filas->values());
    }

    /** POST /indemnizaciones/{id}/confirmar-pago */
    public function confirmarPago(Request $request, $id)
    {
        $i = $this->buscar($request, $id);

        $data = $request->validate([
            'forma_pago'      => ['required', Rule::in(['transferencia', 'cheque', 'deposito', 'efectivo'])],
            'banco'           => ['required_if:forma_pago,transferencia,deposito', 'nullable', 'string', 'max:100'],
            'cuenta_bancaria' => ['required_if:forma_pago,transferencia,deposito', 'nullable', 'regex:/^\d{10,18}$/'],
            'titular_cuenta'  => ['required', 'string', 'min:3', 'max:100'],
            'observaciones'   => ['nullable', 'string', 'max:255'],
        ], [
            'cuenta_bancaria.regex' => 'La cuenta debe tener entre 10 y 18 dígitos (CLABE de 18).',
        ]);

        return response()->json($this->servicio->confirmarPago($i, $data, $request->user()));
    }

    /** POST /indemnizaciones/{id}/pagar */
    public function pagar(Request $request, $id)
    {
        $i = $this->buscar($request, $id);

        $data = $request->validate([
            'fecha_pago'      => ['required', 'date', 'before_or_equal:tomorrow'],
            'referencia_pago' => ['required', 'string', 'min:3', 'max:100'],
        ]);

        return response()->json($this->servicio->pagar($i, $data, $request->user()));
    }

    /** POST /indemnizaciones/{id}/cancelar */
    public function cancelar(Request $request, $id)
    {
        $i = $this->buscar($request, $id);

        $data = $request->validate(['motivo' => ['required', 'string', 'max:500']]);

        return response()->json($this->servicio->cancelar($i, $data['motivo'], $request->user()));
    }

    /** POST /siniestros/{id}/indemnizacion/generar — reintento manual (tras una cancelación o un fallo). */
    public function generar(Request $request, $id)
    {
        $siniestro = Siniestro::where('siniestros.id', $id)
            ->where('siniestros.id_aseguradora', $request->user()->id_aseguradora)->firstOrFail();

        if ($siniestro->estatus !== 'autorizado') {
            return response()->json(['message' => 'Solo se genera la indemnización de un siniestro "Autorizado".'], 422);
        }

        $i = $this->servicio->regenerar($siniestro);

        if (!$i) {
            return response()->json([
                'message' => 'No se generó indemnización: el monto neto es $0.00 o ya existe una vigente.',
                'estatus' => $siniestro->fresh()->estatus,
            ], 422);
        }

        return response()->json($i, 201);
    }
}