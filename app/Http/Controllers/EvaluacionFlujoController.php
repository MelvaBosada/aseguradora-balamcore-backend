<?php

namespace App\Http\Controllers;

use App\Models\Evaluacion;
use App\Models\Siniestro;
use App\Services\EvaluacionService;
use App\Services\SiniestroEstadoService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Captura, envío y autorización del dictamen. La lectura (listado y PDF) sigue en EvaluacionController.
 * El monto NUNCA lo captura la persona: lo calcula el sistema (ver CalculoIndemnizacionService).
 */
class EvaluacionFlujoController extends Controller
{
    public function __construct(private EvaluacionService $servicio)
    {
    }

    private function reglas(): array
    {
        return [
            'fecha_inspeccion' => ['required', 'date', 'before_or_equal:tomorrow'],
            'dictamen'         => ['required', 'string', 'min:10', 'max:255'],
            'causas'           => ['required', 'string', 'min:5', 'max:255'],
            'responsable'      => ['nullable', 'string', 'max:255'],
            'recomendacion'    => ['required', Rule::in(['reparar', 'reemplazar', 'indemnizar', 'rechazar'])],
            // Qué datos se piden depende del ramo de la póliza: lo valida CalculoIndemnizacionService
            'costo_reparacion' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'costo_remplazo'   => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'valor_residual'   => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'valor_real_bien'  => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'gastos_procedentes' => ['nullable', 'numeric', 'min:0', 'max:9999999999.99'],
            'conserva_salvamento' => ['nullable', Rule::in(['aseguradora', 'asegurado'])],
            'id_cobertura'     => ['nullable', 'integer'],
        ];
    }

    private function siniestro(Request $request, $id): Siniestro
    {
        return Siniestro::visiblePara($request->user())->where('siniestros.id', $id)->with('reporte')->firstOrFail();
    }

    private function evaluacion(Request $request, $id): Evaluacion
    {
        return Evaluacion::whereHas('siniestro', fn ($q) => $q->visiblePara($request->user()))->findOrFail($id);
    }

    private function presentar(Evaluacion $e): array
    {
        $e->loadMissing('siniestro:id,numero_siniestro,estatus');

        return [
            'id'                 => $e->id,
            'id_siniestro'       => $e->id_siniestro,
            'numero_siniestro'   => $e->siniestro?->numero_siniestro,
            'id_empleado'        => $e->id_empleado,
            'estatus'            => $e->estatus,
            'siniestro_estatus'  => SiniestroEstadoService::etiqueta($e->siniestro?->estatus),
            'recomendacion'      => $e->recomendacion,
            'dictamen'           => $e->dictamen,
            'causas'             => $e->causas,
            'responsable'        => $e->responsable,
            'fecha_inspeccion'   => $e->fecha_inspeccion?->format('Y-m-d'),
            'costo_reparacion'   => $e->costo_reparacion,
            'costo_remplazo'     => $e->costo_remplazo,
            'valor_residual'     => $e->valor_residual,
            'valor_real_bien'    => $e->valor_real_bien,
            'gastos_procedentes' => $e->gastos_procedentes,
            'conserva_salvamento' => $e->conserva_salvamento,
            'monto_evaluado'     => $e->monto_evaluado,
            'monto_recomendado'  => $e->monto_recomendado,
            'nivel_autorizacion' => $e->nivel_autorizacion,
            'id_cobertura'       => $e->id_cobertura,
            'calculo'            => $e->calculo,
            'fecha_envio'        => $e->fecha_envio?->format('Y-m-d H:i'),
            'fecha_autorizacion' => $e->fecha_autorizacion?->format('Y-m-d H:i'),
            'motivo_resolucion'  => $e->motivo_resolucion,
        ];
    }

    /** GET /siniestros/{id}/evaluaciones — historial de dictámenes del siniestro (el más nuevo primero). */
    public function porSiniestro(Request $request, $id)
    {
        $user = $request->user();

        // Quien autoriza revisa casos de otros ajustadores; el resto, solo los suyos
        $autoriza = $user->tienePermiso('evaluaciones.autorizar') || $user->tienePermiso('evaluaciones.autorizar_alto');
        $s = $autoriza
            ? Siniestro::where('siniestros.id', $id)->where('siniestros.id_aseguradora', $user->id_aseguradora)->firstOrFail()
            : $this->siniestro($request, $id);

        return response()->json(
            Evaluacion::where('id_siniestro', $s->id)->orderByDesc('id')->get()
                ->map(fn ($e) => $this->presentar($e))->values()
        );
    }

    /** POST /siniestros/{id}/evaluaciones/calcular — desglose sin guardar. */
    public function calcular(Request $request, $id)
    {
        $s    = $this->siniestro($request, $id);
        $data = $request->validate($this->reglas());

        return response()->json($this->servicio->previsualizar($s, $data));
    }

    /** POST /siniestros/{id}/evaluaciones */
    public function store(Request $request, $id)
    {
        $s    = $this->siniestro($request, $id);
        $data = $request->validate($this->reglas());

        return response()->json($this->presentar($this->servicio->guardar($s, $data, $request->user())), 201);
    }

    /** PUT /evaluaciones/{id} — solo en borrador. */
    public function update(Request $request, $id)
    {
        $e    = $this->evaluacion($request, $id);
        $data = $request->validate($this->reglas());

        return response()->json($this->presentar(
            $this->servicio->guardar($e->siniestro()->with('reporte')->first(), $data, $request->user(), $e)
        ));
    }

    /** POST /evaluaciones/{id}/enviar */
    public function enviar(Request $request, $id)
    {
        $e = $this->evaluacion($request, $id);

        return response()->json($this->presentar($this->servicio->enviar($e, $request->user())));
    }

    /** POST /evaluaciones/{id}/resolver  { accion: aprobar|devolver, motivo } — lo hace quien autoriza. */
    public function resolver(Request $request, $id)
    {
        $user = $request->user();

        $data = $request->validate([
            'accion' => ['required', Rule::in(['aprobar', 'devolver'])],
            'motivo' => ['nullable', 'string', 'max:500'],
        ]);

        // Quien autoriza supervisa casos de otros ajustadores: aquí basta con que sea de su aseguradora
        $e = Evaluacion::whereHas('siniestro', fn ($q) => $q->where('siniestros.id_aseguradora', $user->id_aseguradora))
            ->findOrFail($id);

        return response()->json($this->presentar($this->servicio->resolver($e, $user, $data['accion'], $data['motivo'] ?? null)));
    }

    /** GET /evaluaciones/pendientes-autorizacion — dictámenes que ESTE usuario puede autorizar. */
    public function pendientes(Request $request)
    {
        $user = $request->user();

        $niveles = $user->tienePermiso('evaluaciones.autorizar_alto')
            ? ['supervisor', 'gerente']
            : ($user->tienePermiso('evaluaciones.autorizar') ? ['supervisor'] : []);

        abort_if(empty($niveles), 403, 'No tienes permiso para autorizar dictámenes.');

        $query = Evaluacion::where('estatus', 'en_revision')
            ->whereIn('nivel_autorizacion', $niveles)
            ->whereHas('siniestro', fn ($q) => $q->where('siniestros.id_aseguradora', $user->id_aseguradora))
            ->with(['siniestro:id,numero_siniestro,tipo', 'empleado:id,nombre,apellidos'])
            ->orderBy('fecha_envio');

        if ($user->empleado) {
            $query->where('id_empleado', '!=', $user->empleado->id); // no autorizas lo tuyo
        }

        return response()->json($query->get()->map(fn ($e) => [
            'id'                 => $e->id,
            'id_siniestro'       => $e->id_siniestro,
            'numero_siniestro'   => $e->siniestro?->numero_siniestro,
            'tipo'               => $e->siniestro?->tipo,
            'ajustador'          => $e->empleado?->nombre_completo,
            'recomendacion'      => $e->recomendacion,
            'monto_recomendado'  => $e->monto_recomendado,
            'nivel_autorizacion' => $e->nivel_autorizacion,
            'advertencias'       => $e->calculo['advertencias'] ?? [],
            'fecha_envio'        => $e->fecha_envio?->format('Y-m-d H:i'),
        ])->values());
    }
}
