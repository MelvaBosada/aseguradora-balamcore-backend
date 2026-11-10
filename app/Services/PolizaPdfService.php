<?php

namespace App\Services;

use App\Models\Bienes;
use App\Models\Cobertura;
use App\Models\Poliza;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * Genera el PDF de una póliza con la MISMA información que muestra el detalle del frontend:
 * datos generales, cliente, cálculo de prima (subtotal / IVA / total), plan de pagos,
 * bienes asegurados, coberturas, endosos (con sus cambios y ajuste a prorrata) e historial.
 *
 * Todo se calcula en el servidor reutilizando PolizaFinanzasService, PolizaCoberturasService
 * y PolizaBitacoraService, así el PDF nunca difiere de lo que ve el usuario en pantalla.
 */
class PolizaPdfService
{
    private const RAMOS = [
        'vida'                  => 'Vida',
        'gastos_medicos'        => 'Gastos médicos',
        'autos'                 => 'Autos',
        'hogar'                 => 'Hogar',
        'empresarial'           => 'Empresarial',
        'responsabilidad_civil' => 'Responsabilidad civil',
        'otro'                  => 'Otro',
    ];

    private const ESTATUS = [
        'activa'        => 'Activa',
        'vencida'       => 'Vencida',
        'cancelada'     => 'Cancelada',
        'suspendida'    => 'Suspendida',
        'en_renovacion' => 'En renovación',
        'borrador'      => 'Borrador',
    ];

    private const TIPOS_ENDOSO = [
        'A' => 'Aumento de prima',
        'B' => 'Disminución de prima',
        'D' => 'Sin efecto en la prima',
    ];

    private const ETIQUETAS_CAMBIO = [
        'suma_asegurada' => 'Suma asegurada',
        'fecha_inicio'   => 'Inicio de vigencia',
        'fecha_fin'      => 'Fin de vigencia',
        'prima_neta'     => 'Prima neta',
        'derecho_poliza' => 'Derecho de póliza',
        'iva'            => 'IVA',
        'prima_total'    => 'Prima total',
        'forma_pago'     => 'Forma de pago',
        'bienes'         => 'Bienes asegurados',
        'coberturas'     => 'Coberturas',
    ];

    public function __construct(
        private PolizaFinanzasService $finanzas,
        private PolizaCoberturasService $coberturas,
        private PolizaBitacoraService $bitacora
    ) {
    }

    /** PDF listo para descargar (->output()) o mostrar. */
    public function generar(Poliza $poliza)
    {
        return Pdf::loadView('pdf.poliza', $this->datos($poliza))
            ->setPaper('letter', 'portrait');
    }

    /** Todos los datos que necesita la vista. */
    public function datos(Poliza $poliza): array
    {
        $poliza->loadMissing(['cliente', 'endosos']);
        $idAseg = (int) $poliza->id_aseguradora;

        // ── Cálculos
        $fin  = $this->finanzas->calcular($poliza->tipo, $poliza->prima_neta, $poliza->derecho_poliza, $idAseg);
        $plan = $this->finanzas->plan($poliza->forma_pago, (float) $poliza->prima_total, $poliza->fecha_inicio, $poliza->fecha_fin);

        $subtotal = round((float) $poliza->prima_neta + (float) $poliza->derecho_poliza, 2);
        // La tasa mostrada es la que se aplicó al emitir (igual que en el detalle de la pantalla)
        $tasa = ($subtotal > 0 && (float) $poliza->iva > 0)
            ? round((float) $poliza->iva / $subtotal * 100, 2)
            : $fin['tasa_iva'];

        // ── Bienes
        $bienes = Bienes::where('id_poliza', $poliza->id)->orderBy('id')->get()
            ->map(fn ($b) => [
                'tipo'        => $b->tipo ? ucfirst((string) $b->tipo) : '—',
                'descripcion' => $b->descripcion ?: 'Sin descripción',
                'marca'       => $b->marca ?? null,
                'modelo'      => $b->modelo ?? null,
                'valor'       => (float) $b->valor,
            ])->values()->all();

        $valorBienes = round(array_sum(array_column($bienes, 'valor')), 2);
        $suma        = (float) $poliza->suma_asegurada;

        // Regla proporcional (igual que el aviso de infraseguro del formulario)
        $infraseguro = (in_array($poliza->tipo, ['hogar', 'empresarial'], true) && $valorBienes > 0 && $suma > 0 && $suma < $valorBienes - 0.005)
            ? round($suma / $valorBienes * 100, 2)
            : null;

        // ── Coberturas contratadas
        $idsCob = $this->coberturas->idsAsignados($poliza->id);
        $coberturas = Cobertura::whereIn('id', $idsCob)->orderBy('nombre')->get()
            ->map(fn ($c) => $this->coberturaParaPdf($this->coberturas->ficha($c)))
            ->values()->all();

        // ── Endosos (del primero al último)
        $endosos = $this->endosos($poliza, $idAseg);

        $ajusteNeto = round(array_sum(array_map(fn ($e) => (float) $e['ajuste_prima'], $endosos)), 2);

        // ── Calendario de pagos (si la póliza no los guardó, se calculan igual que al emitirla)
        $meses = PolizaFinanzasService::MESES_POR_FORMA_PAGO[$poliza->forma_pago] ?? null;
        $base  = Carbon::parse($poliza->fecha_primer_pago ?? $poliza->fecha_inicio);

        $diaPago = $poliza->dia_pago;
        if (!$diaPago && in_array($poliza->forma_pago, ['mensual', 'trimestral', 'semestral'], true)) {
            $diaPago = min($base->day, 28);
        }

        $ultimoPago = ($meses && $plan['numero_pagos'] > 1)
            ? $base->copy()->addMonthsNoOverflow($meses * ($plan['numero_pagos'] - 1))->toDateString()
            : null;

        return [
            'aseguradora' => $this->nombreAseguradora($idAseg),
            'generado_en' => now()->format('d/m/Y H:i'),
            'generado_por' => $this->nombreUsuario(),

            'poliza' => [
                'numero'         => $poliza->numero_poliza,
                'ramo'           => self::RAMOS[$poliza->tipo] ?? $poliza->tipo,
                'estatus'        => $this->estatus($poliza),
                'fecha_emision'  => $poliza->fecha_emision,
                'fecha_inicio'   => $poliza->fecha_inicio,
                'fecha_fin'      => $poliza->fecha_fin,
                'forma_pago'     => ucfirst((string) $poliza->forma_pago),
                'suma_asegurada' => $suma,
                'observaciones'  => $poliza->observaciones,
                'tiene_endoso'   => count($endosos) > 0,
            ],

            'cliente' => [
                'nombre'    => trim(($poliza->cliente->nombre ?? '') . ' ' . ($poliza->cliente->apellidos ?? '')) ?: 'Sin cliente',
                'rfc'       => $poliza->cliente->rfc ?? null,
                'curp'      => $poliza->cliente->curp ?? null,
                'correo'    => $poliza->cliente->correo ?? null,
                'telefono'  => $poliza->cliente->numero_de_telefono ?? null,
                'domicilio' => $poliza->cliente->domicilio ?? null,
            ],

            'calculo' => [
                'prima_neta'     => (float) $poliza->prima_neta,
                'derecho_poliza' => (float) $poliza->derecho_poliza,
                'subtotal'       => $subtotal,
                'iva'            => (float) $poliza->iva,
                'iva_exento'     => $fin['iva_exento'],
                'tasa_iva'       => $tasa,
                'prima_total'    => (float) $poliza->prima_total,
            ],

            'pagos' => [
                'numero'         => $plan['numero_pagos'],
                'pago_periodico' => $plan['pago_periodico'],
                'primer_pago'    => $poliza->fecha_primer_pago ?? $poliza->fecha_inicio,
                'siguiente_pago' => $poliza->siguiente_pago ?? null,
                'ultimo_pago'    => $ultimoPago,
                'dia_pago'       => $diaPago,
            ],

            'bienes'            => $bienes,
            'valor_bienes'      => $valorBienes,
            'infraseguro'       => $infraseguro,
            'coberturas'        => $coberturas,
            'endosos'           => $endosos,
            'ajuste_neto'       => $ajusteNeto,
            'historial'         => $this->bitacora->historial($poliza->id, $idAseg, 100),
        ];
    }

    // ───────────────────────── Coberturas ─────────────────────────

    private const NOMBRES_SINIESTRO = [
        'robo' => 'Robo', 'accidente' => 'Accidente', 'incendio' => 'Incendio', 'inundacion' => 'Inundación',
        'responsabilidad_civil' => 'Resp. civil', 'fallecimiento' => 'Fallecimiento', 'invalidez' => 'Invalidez', 'otro' => 'Otro',
    ];

    private const NOMBRES_BIEN = [
        'vehiculo' => 'Vehículo', 'inmueble' => 'Inmueble', 'electronico' => 'Electrónico', 'maquinaria' => 'Maquinaria', 'otro' => 'Otro',
    ];

    private const SOBRE = [
        'valor_bien' => 'el valor del bien', 'suma_asegurada' => 'la suma asegurada',
        'monto_reclamado' => 'el monto reclamado', 'costo_reparacion' => 'el costo de reparación',
    ];

    /** Mismo texto que muestra FichaCobertura.jsx en pantalla. */
    private function coberturaParaPdf(array $f): array
    {
        $sobre = self::SOBRE[$f['aplica_sobre'] ?? ''] ?? 'el daño';
        $ded = (float) $f['deducible'];
        $pct = (float) $f['porcentaje_deducible'];

        if (($f['tipo_calculo'] ?? null) === 'porcentaje' && $pct > 0) {
            $deducible = $this->num($pct) . "% sobre {$sobre}";
        } elseif (($f['tipo_calculo'] ?? null) === 'mixto' && ($ded > 0 || $pct > 0)) {
            $deducible = 'el mayor entre ' . $this->dinero0($ded) . ' y ' . $this->num($pct) . "% sobre {$sobre}";
        } else {
            $deducible = $ded > 0 ? $this->dinero0($ded) . ' fijo' : 'sin deducible';
        }

        $ampara = !empty($f['tipos_siniestro'])
            ? implode(', ', array_map(fn ($t) => self::NOMBRES_SINIESTRO[$t] ?? $t, $f['tipos_siniestro']))
            : 'todos los tipos de siniestro';

        $tope = (float) $f['monto_maximo_siniestro'] > 0 ? $this->dinero0($f['monto_maximo_siniestro']) : 'la suma asegurada';

        $lineas = [
            'Ampara' => $ampara,
            'Deducible' => $deducible,
            'La aseguradora paga' => $this->num($f['porcentaje_cobertura']) . '%',
            'Tope por siniestro' => $tope,
        ];

        if (!empty($f['valor_minimo']) || !empty($f['valor_maximo'])) {
            $rango = (!empty($f['valor_minimo']) ? $this->dinero0($f['valor_minimo']) : 'sin mínimo')
                . ' a '
                . (!empty($f['valor_maximo']) ? $this->dinero0($f['valor_maximo']) : 'sin máximo');

            if (!empty($f['tipos_bien'])) {
                $rango .= ' (' . implode(', ', array_map(fn ($t) => self::NOMBRES_BIEN[$t] ?? $t, $f['tipos_bien'])) . ')';
            }
            $lineas['Valor asegurable'] = $rango;
        }

        return [
            'nombre'       => $f['nombre'],
            'descripcion'  => $f['descripcion'] ?? null,
            'primer_riesgo' => !empty($f['primer_riesgo']),
            'lineas'       => $lineas,
        ];
    }

    private function num($v): string
    {
        return rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.');
    }

    private function dinero0($v): string
    {
        return '$' . rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    }

    // ───────────────────────── Endosos ─────────────────────────

    private function endosos(Poliza $poliza, int $idAseg): array
    {
        $lista = $poliza->endosos->sortBy('consecutivo')->values();
        if ($lista->isEmpty()) {
            return [];
        }

        $nombresUsuario = $this->bitacora->nombresPorUsuario($lista->pluck('id_usuario')->all(), $idAseg);

        // Para mostrar nombres (y no solo números) en los cambios de bienes y coberturas
        $idsBienes = [];
        $idsCob = [];
        $cambiosPorEndoso = [];

        foreach ($lista as $e) {
            $c = $e->cambios;
            $c = is_string($c) ? json_decode($c, true) : $c;
            $c = is_array($c) ? $c : [];
            $cambiosPorEndoso[$e->id] = $c;

            foreach (['antes', 'despues'] as $lado) {
                if (isset($c['bienes'][$lado]) && is_array($c['bienes'][$lado])) {
                    $idsBienes = array_merge($idsBienes, array_keys($c['bienes'][$lado]));
                }
                if (isset($c['coberturas'][$lado]) && is_array($c['coberturas'][$lado])) {
                    $idsCob = array_merge($idsCob, array_values($c['coberturas'][$lado]));
                }
            }
        }

        $nombresBienes = $idsBienes
            ? Bienes::whereIn('id', array_unique(array_map('intval', $idsBienes)))->get()
                ->mapWithKeys(fn ($b) => [(int) $b->id => $b->descripcion ?: ucfirst((string) $b->tipo)])->all()
            : [];

        $nombresCob = $idsCob
            ? Cobertura::whereIn('id', array_unique(array_map('intval', $idsCob)))->pluck('nombre', 'id')->all()
            : [];

        return $lista->map(function ($e) use ($nombresUsuario, $cambiosPorEndoso, $nombresBienes, $nombresCob) {
            $tipo = $e->tipo_endoso ?? null;

            return [
                'numero'       => $e->numero_endoso,
                'fecha'        => optional($e->created_at)->format('d/m/Y H:i'),
                'fecha_efecto' => $e->fecha_efecto ? Carbon::parse($e->fecha_efecto)->format('d/m/Y') : '—',
                'descripcion'  => $e->descripcion,
                'usuario'      => $nombresUsuario[$e->id_usuario] ?? '—',
                'tipo'         => $tipo,
                'tipo_texto'   => $tipo ? ($tipo . ' · ' . (self::TIPOS_ENDOSO[$tipo] ?? '')) : '—',
                'ajuste_prima' => (float) ($e->ajuste_prima ?? 0),
                'documento'    => $e->documento_nombre,
                'cambios'      => $this->cambiosLegibles($cambiosPorEndoso[$e->id] ?? [], $nombresBienes, $nombresCob),
            ];
        })->all();
    }

    /** Convierte { campo: {antes, despues} } en renglones de texto: [campo, antes, después]. */
    private function cambiosLegibles(array $cambios, array $nombresBienes, array $nombresCob): array
    {
        $out = [];

        foreach ($cambios as $campo => $par) {
            $antes = $par['antes'] ?? null;
            $despues = $par['despues'] ?? null;

            if ($campo === 'bienes') {
                $fmt = function ($mapa) use ($nombresBienes) {
                    if (!is_array($mapa) || !$mapa) {
                        return 'Ninguno';
                    }
                    $partes = [];
                    foreach ($mapa as $id => $valor) {
                        $partes[] = ($nombresBienes[(int) $id] ?? "Bien #{$id}") . ' (' . $this->dinero($valor) . ')';
                    }
                    return implode('; ', $partes);
                };
                $out[] = [self::ETIQUETAS_CAMBIO[$campo], $fmt($antes), $fmt($despues)];
                continue;
            }

            if ($campo === 'coberturas') {
                $fmt = function ($ids) use ($nombresCob) {
                    if (!is_array($ids) || !$ids) {
                        return 'Ninguna';
                    }
                    return implode(', ', array_map(fn ($id) => $nombresCob[(int) $id] ?? "Cobertura #{$id}", $ids));
                };
                $out[] = [self::ETIQUETAS_CAMBIO[$campo], $fmt($antes), $fmt($despues)];
                continue;
            }

            $out[] = [
                self::ETIQUETAS_CAMBIO[$campo] ?? ucfirst(str_replace('_', ' ', (string) $campo)),
                $this->valorCampo($campo, $antes),
                $this->valorCampo($campo, $despues),
            ];
        }

        return $out;
    }

    private function valorCampo(string $campo, $valor): string
    {
        if ($valor === null || $valor === '') {
            return '—';
        }
        if (in_array($campo, ['suma_asegurada', 'prima_neta', 'derecho_poliza', 'iva', 'prima_total'], true)) {
            return $this->dinero($valor);
        }
        if (in_array($campo, ['fecha_inicio', 'fecha_fin'], true)) {
            return Carbon::parse($valor)->format('d/m/Y');
        }
        if ($campo === 'forma_pago') {
            return ucfirst((string) $valor);
        }
        return is_scalar($valor) ? (string) $valor : json_encode($valor, JSON_UNESCAPED_UNICODE);
    }

    // ───────────────────────── Auxiliares ─────────────────────────

    private function dinero($v): string
    {
        return '$' . number_format((float) $v, 2);
    }

    /** Una póliza "activa" con la vigencia terminada se muestra como vencida (igual que el detalle). */
    private function estatus(Poliza $poliza): string
    {
        $e = $poliza->estatus;
        if ($e === 'activa' && Carbon::parse($poliza->fecha_fin)->endOfDay()->isPast()) {
            $e = 'vencida';
        }

        return self::ESTATUS[$e] ?? (string) $e;
    }

    private function nombreAseguradora(int $idAseg): string
    {
        try {
            if (class_exists(\App\Models\Aseguradora::class)) {
                $a = \App\Models\Aseguradora::find($idAseg);
                if ($a) {
                    return (string) ($a->nombre ?? $a->razon_social ?? $a->nombre_comercial ?? 'Aseguradora');
                }
            }
        } catch (\Throwable $e) {
            // si el modelo/columna se llama distinto, no se rompe el PDF
        }

        return 'Aseguradora';
    }

    private function nombreUsuario(): string
    {
        $user = auth()->user();
        if (!$user) {
            return '';
        }

        $emp = $user->empleado ?? null;
        $nombre = trim(($emp->nombre ?? '') . ' ' . ($emp->apellidos ?? ''));

        return $nombre !== '' ? $nombre : (string) ($user->name ?? $user->email ?? '');
    }
}