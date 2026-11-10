@php
    $m  = fn ($v) => '$' . number_format((float) $v, 2);
    $f  = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->format('d/m/Y') : '—';
    $v  = fn ($x) => ($x === null || $x === '') ? '—' : $x;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Póliza {{ $poliza['numero'] }}</title>
<style>
    @page { margin: 90px 38px 60px 38px; }
    * { font-family: 'DejaVu Sans', sans-serif; }
    body { font-size: 10px; color: #1f2937; margin: 0; }

    .header { position: fixed; top: -70px; left: 0; right: 0; height: 60px; border-bottom: 3px solid #0f4a35; }
    .header td { vertical-align: bottom; }
    .marca { font-size: 18px; font-weight: bold; color: #0f4a35; }
    .marca-sub { font-size: 9px; color: #6b7280; }
    .titulo-doc { text-align: right; font-size: 13px; font-weight: bold; color: #0f4a35; }
    .titulo-doc small { display: block; font-size: 9px; font-weight: normal; color: #6b7280; }

    .footer { position: fixed; bottom: -40px; left: 0; right: 0; height: 30px; border-top: 1px solid #d1d5db;
              font-size: 8px; color: #6b7280; padding-top: 5px; }
    .pagenum:before { content: counter(page); }

    table { width: 100%; border-collapse: collapse; }
    h2 { font-size: 11px; color: #ffffff; background: #0f4a35; padding: 5px 8px; margin: 16px 0 6px 0; }
    h3 { font-size: 10px; color: #0f4a35; margin: 10px 0 4px 0; }

    .datos td { padding: 4px 6px; border-bottom: 1px dashed #d1d5db; vertical-align: top; }
    .datos td.k { width: 22%; color: #6b7280; }
    .datos td.v { width: 28%; font-weight: bold; }

    .grid th { background: #e8f1ec; color: #0f4a35; text-align: left; padding: 5px 6px; font-size: 9px; border: 1px solid #c7d9cf; }
    .grid td { padding: 5px 6px; border: 1px solid #e5e7eb; vertical-align: top; }
    .grid tr.alt td { background: #f9fafb; }
    .der { text-align: right; }
    .centro { text-align: center; }

    .calculo td { padding: 5px 8px; border-bottom: 1px dashed #d1d5db; }
    .calculo td.der { font-weight: bold; }
    .calculo tr.total td { border-top: 2px solid #0f4a35; border-bottom: none; font-size: 12px; font-weight: bold; color: #0f4a35; padding-top: 8px; }

    .aviso { background: #fff4d6; border: 1px solid #e5c76b; color: #7a5600; padding: 6px 8px; margin-top: 6px; }
    .vacio { color: #6b7280; font-style: italic; padding: 6px 2px; }

    .endoso { border: 1px solid #c7d9cf; margin-bottom: 10px; page-break-inside: avoid; }
    .endoso .cab { background: #e8f1ec; padding: 5px 8px; font-weight: bold; color: #0f4a35; }
    .endoso .cuerpo { padding: 6px 8px; }
    .pos { color: #166534; font-weight: bold; }
    .neg { color: #b91c1c; font-weight: bold; }

    .badge { display: inline-block; padding: 1px 7px; border-radius: 8px; background: #e8f1ec; color: #0f4a35; font-weight: bold; }
    .pequeno { font-size: 8.5px; color: #6b7280; }
</style>
</head>
<body>

<div class="header">
    <table>
        <tr>
            <td>
                <div class="marca">{{ $aseguradora }}</div>
                <div class="marca-sub">Póliza de seguro · Documento completo</div>
            </td>
            <td class="titulo-doc">
                Póliza {{ $poliza['numero'] }}
                <small>Estatus: {{ $poliza['estatus'] }} · Ramo: {{ $poliza['ramo'] }}</small>
            </td>
        </tr>
    </table>
</div>

<div class="footer">
    <table>
        <tr>
            <td>Generado el {{ $generado_en }}@if($generado_por) por {{ $generado_por }}@endif</td>
            <td style="text-align:right;">Póliza {{ $poliza['numero'] }} · Página <span class="pagenum"></span></td>
        </tr>
    </table>
</div>

{{-- ───────────── DATOS DE LA PÓLIZA ───────────── --}}
<h2>DATOS DE LA PÓLIZA</h2>
<table class="datos">
    <tr>
        <td class="k">N° de póliza</td><td class="v">{{ $poliza['numero'] }}</td>
        <td class="k">Ramo</td><td class="v">{{ $poliza['ramo'] }}</td>
    </tr>
    <tr>
        <td class="k">Estatus</td><td class="v">{{ $poliza['estatus'] }}</td>
        <td class="k">Fecha de emisión</td><td class="v">{{ $f($poliza['fecha_emision']) }}</td>
    </tr>
    <tr>
        <td class="k">Inicio de vigencia</td><td class="v">{{ $f($poliza['fecha_inicio']) }}</td>
        <td class="k">Fin de vigencia</td><td class="v">{{ $f($poliza['fecha_fin']) }}</td>
    </tr>
    <tr>
        <td class="k">Suma asegurada</td><td class="v">{{ $m($poliza['suma_asegurada']) }}</td>
        <td class="k">Endosos</td><td class="v">{{ $poliza['tiene_endoso'] ? 'Sí (' . count($endosos) . ')' : 'No' }}</td>
    </tr>
    <tr>
        <td class="k">Observaciones</td>
        <td class="v" colspan="3" style="font-weight: normal;">{{ $v($poliza['observaciones']) }}</td>
    </tr>
</table>

{{-- ───────────── CLIENTE ───────────── --}}
<h2>CLIENTE (ASEGURADO)</h2>
<table class="datos">
    <tr>
        <td class="k">Nombre</td><td class="v">{{ $cliente['nombre'] }}</td>
        <td class="k">RFC</td><td class="v">{{ $v($cliente['rfc']) }}</td>
    </tr>
    <tr>
        <td class="k">CURP</td><td class="v">{{ $v($cliente['curp']) }}</td>
        <td class="k">Teléfono</td><td class="v">{{ $v($cliente['telefono']) }}</td>
    </tr>
    <tr>
        <td class="k">Correo</td><td class="v">{{ $v($cliente['correo']) }}</td>
        <td class="k">Domicilio</td><td class="v">{{ $v($cliente['domicilio']) }}</td>
    </tr>
</table>

{{-- ───────────── CÁLCULO DE LA PRIMA ───────────── --}}
<h2>CÁLCULO DE LA PRIMA</h2>
<table class="calculo">
    <tr><td>Prima neta</td><td class="der">{{ $m($calculo['prima_neta']) }}</td></tr>
    <tr><td>Derecho de póliza</td><td class="der">{{ $m($calculo['derecho_poliza']) }}</td></tr>
    <tr><td>Subtotal (prima neta + derecho)</td><td class="der">{{ $m($calculo['subtotal']) }}</td></tr>
    <tr>
        <td>{{ $calculo['iva_exento'] ? 'IVA (exento en este ramo)' : 'IVA (' . rtrim(rtrim(number_format($calculo['tasa_iva'], 2), '0'), '.') . '%)' }}</td>
        <td class="der">{{ $m($calculo['iva']) }}</td>
    </tr>
    <tr class="total"><td>PRIMA TOTAL</td><td class="der">{{ $m($calculo['prima_total']) }}</td></tr>
</table>

<h3>Plan de pagos</h3>
<table class="datos">
    <tr>
        <td class="k">Forma de pago</td><td class="v">{{ $poliza['forma_pago'] }}</td>
        <td class="k">Número de pagos</td><td class="v">{{ $pagos['numero'] }}</td>
    </tr>
    <tr>
        <td class="k">Monto por pago</td><td class="v">{{ $m($pagos['pago_periodico']) }}</td>
        <td class="k">Día de pago</td><td class="v">{{ $v($pagos['dia_pago']) }}</td>
    </tr>
    <tr>
        <td class="k">Primer pago</td><td class="v">{{ $f($pagos['primer_pago']) }}</td>
        <td class="k">Siguiente pago</td><td class="v">{{ $f($pagos['siguiente_pago']) }}</td>
    </tr>
    <tr>
        <td class="k">Último pago</td><td class="v">{{ $f($pagos['ultimo_pago']) }}</td>
        <td class="k"></td><td class="v"></td>
    </tr>
</table>
<div class="pequeno" style="margin-top:3px;">El pago periódico es la prima total entre el número de pagos (sin recargo por pago fraccionado).</div>

{{-- ───────────── BIENES ASEGURADOS ───────────── --}}
<h2>BIENES ASEGURADOS</h2>
@if (count($bienes))
    <table class="grid">
        <thead>
            <tr>
                <th style="width:6%;">#</th>
                <th style="width:14%;">Tipo</th>
                <th>Descripción</th>
                <th style="width:16%;">Marca / Modelo</th>
                <th style="width:18%;" class="der">Valor asegurado</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($bienes as $i => $b)
                <tr class="{{ $i % 2 ? 'alt' : '' }}">
                    <td class="centro">{{ $i + 1 }}</td>
                    <td>{{ $b['tipo'] }}</td>
                    <td>{{ $b['descripcion'] }}</td>
                    <td>{{ trim(($b['marca'] ?? '') . ' ' . ($b['modelo'] ?? '')) ?: '—' }}</td>
                    <td class="der">{{ $m($b['valor']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td colspan="4" class="der" style="font-weight:bold;">Valor total de los bienes</td>
                <td class="der" style="font-weight:bold;">{{ $m($valor_bienes) }}</td>
            </tr>
        </tbody>
    </table>
    @if ($infraseguro !== null)
        <div class="aviso">
            <strong>Infraseguro:</strong> la suma asegurada ({{ $m($poliza['suma_asegurada']) }}) es menor al valor de los bienes
            ({{ $m($valor_bienes) }}). En un siniestro se indemnizaría de forma proporcional ({{ rtrim(rtrim(number_format($infraseguro, 2), '0'), '.') }}%),
            salvo en coberturas a primer riesgo.
        </div>
    @endif
@else
    <div class="vacio">Esta póliza no tiene bienes asignados (por ejemplo, los ramos de vida y gastos médicos aseguran personas).</div>
@endif

{{-- ───────────── COBERTURAS ───────────── --}}
<h2>COBERTURAS CONTRATADAS</h2>
@if (count($coberturas))
    <table class="grid">
        <thead>
            <tr>
                <th style="width:6%;">#</th>
                <th style="width:30%;">Cobertura</th>
                <th>Condiciones</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($coberturas as $i => $c)
                <tr class="{{ $i % 2 ? 'alt' : '' }}">
                    <td class="centro">{{ $i + 1 }}</td>
                    <td>
                        <strong>{{ $c['nombre'] }}</strong>
                        @if ($c['primer_riesgo'])
                            <div><span class="badge">Primer riesgo</span></div>
                        @endif
                        @if ($c['descripcion'])
                            <div class="pequeno">{{ $c['descripcion'] }}</div>
                        @endif
                    </td>
                    <td>
                        @foreach ($c['lineas'] as $etiqueta => $valor)
                            <div><span class="pequeno">{{ $etiqueta }}:</span> {{ $valor }}</div>
                        @endforeach
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <div class="vacio">Sin coberturas asignadas.</div>
@endif

{{-- ───────────── ENDOSOS ───────────── --}}
<h2>ENDOSOS</h2>
@if (count($endosos))
    @foreach ($endosos as $e)
        <div class="endoso">
            <div class="cab">
                <table>
                    <tr>
                        <td>Endoso {{ $e['numero'] }}</td>
                        <td style="text-align:right;">Efecto: {{ $e['fecha_efecto'] }}</td>
                    </tr>
                </table>
            </div>
            <div class="cuerpo">
                <table class="datos">
                    <tr>
                        <td class="k">Descripción</td><td class="v" colspan="3" style="font-weight:normal;">{{ $v($e['descripcion']) }}</td>
                    </tr>
                    <tr>
                        <td class="k">Tipo</td><td class="v">{{ $e['tipo_texto'] }}</td>
                        <td class="k">Ajuste de prima (a prorrata)</td>
                        <td class="v">
                            @if ($e['ajuste_prima'] > 0)
                                <span class="pos">+{{ $m($e['ajuste_prima']) }} (se cobra)</span>
                            @elseif ($e['ajuste_prima'] < 0)
                                <span class="neg">-{{ $m(abs($e['ajuste_prima'])) }} (se devuelve)</span>
                            @else
                                {{ $m(0) }}
                            @endif
                        </td>
                    </tr>
                    <tr>
                        <td class="k">Registrado</td><td class="v">{{ $v($e['fecha']) }}</td>
                        <td class="k">Por</td><td class="v">{{ $e['usuario'] }}</td>
                    </tr>
                    @if ($e['documento'])
                        <tr><td class="k">Documento</td><td class="v" colspan="3" style="font-weight:normal;">{{ $e['documento'] }}</td></tr>
                    @endif
                </table>

                @if (count($e['cambios']))
                    <h3>Cambios al contrato</h3>
                    <table class="grid">
                        <thead>
                            <tr><th style="width:22%;">Concepto</th><th>Antes</th><th>Después</th></tr>
                        </thead>
                        <tbody>
                            @foreach ($e['cambios'] as $cambio)
                                <tr>
                                    <td><strong>{{ $cambio[0] }}</strong></td>
                                    <td>{{ $cambio[1] }}</td>
                                    <td>{{ $cambio[2] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>
    @endforeach

    <table class="calculo">
        <tr class="total">
            <td>AJUSTE NETO POR ENDOSOS</td>
            <td class="der">{{ $ajuste_neto >= 0 ? '+' : '-' }}{{ $m(abs($ajuste_neto)) }}</td>
        </tr>
    </table>
@else
    <div class="vacio">Esta póliza no tiene endosos.</div>
@endif

{{-- ───────────── HISTORIAL ───────────── --}}
<h2>HISTORIAL DE MOVIMIENTOS</h2>
@if (count($historial))
    <table class="grid">
        <thead>
            <tr>
                <th style="width:16%;">Fecha</th>
                <th style="width:14%;">Acción</th>
                <th>Detalle</th>
                <th style="width:20%;">Usuario</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($historial as $i => $h)
                <tr class="{{ $i % 2 ? 'alt' : '' }}">
                    <td>{{ \Illuminate\Support\Carbon::parse($h['fecha'])->format('d/m/Y H:i') }}</td>
                    <td><span class="badge">{{ ucfirst($h['accion']) }}</span></td>
                    <td>{{ $h['resumen'] }}</td>
                    <td>{{ $h['usuario'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@else
    <div class="vacio">Sin movimientos registrados.</div>
@endif

</body>
</html>