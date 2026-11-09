<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Evidencias fotográficas {{ $numero }}</title>
<style>
    @page { margin: 92pt 45pt 50pt 45pt; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8.5pt; color: #333; margin: 0; }

    /* Barra superior jade + filete dorado */
    .barra { position: fixed; top: -92pt; left: -45pt; right: -45pt; height: 6pt; background: #1B6B45; }
    .barra-oro { position: fixed; top: -86pt; left: -45pt; right: -45pt; height: 1.5pt; background: #C9A24D; }

    /* Encabezado fijo en cada hoja */
    .cab { position: fixed; top: -78pt; left: 0; right: 0; height: 60pt; }
    .cab > table { width: 100%; border-collapse: collapse; }
    .cab td { vertical-align: middle; padding: 0; }
    .aseg-nombre { font-size: 12pt; font-weight: bold; color: #1B6B45; }
    .tit { font-size: 7pt; letter-spacing: 1.2pt; text-transform: uppercase; color: #C9A24D; font-weight: bold; margin-top: 2pt; }

    /* Datos en la esquina derecha, 3 por fila */
    table.datos { width: 100%; border-collapse: collapse; }
    table.datos td { padding: 2pt 0 2pt 12pt; vertical-align: top; text-align: right; }
    .dl { font-size: 6.5pt; text-transform: uppercase; letter-spacing: .6pt; color: #888; }
    .dv { font-size: 8.5pt; font-weight: bold; color: #222; }

    /* Línea separadora independiente del contenido */
    .cab-linea { position: fixed; top: -12pt; left: 0; right: 0; height: 0; border-top: 1.2pt solid #1B6B45; }

    /* Pie fijo */
    .pie { position: fixed; bottom: -38pt; left: 0; right: 0; height: 26pt; border-top: .5pt solid #ccc; color: #888; font-size: 7.5pt; }
    .pie table { width: 100%; border-collapse: collapse; }
    .pie td { padding-top: 5pt; vertical-align: middle; }
    .pn:before { content: "Pág. " counter(page); }

    /* Marca de agua */
    .marca { position: fixed; top: 180pt; left: 96pt; width: 330pt; opacity: .07; }

    /* Fotos */
    .pagina { page-break-after: always; }
    .pagina.ultima { page-break-after: auto; }
    table.grid { width: 100%; border-collapse: collapse; table-layout: fixed; }
    td.img { text-align: center; vertical-align: middle; padding: 0 4pt; }
    td.cap { text-align: center; padding: 3pt 4pt 0; font-size: 8pt; color: #555; white-space: nowrap; overflow: hidden; }
    img.foto { border: .5pt solid #ddd; }
</style>
</head>
<body>

@if($marca)
    <img class="marca" src="{{ $marca['uri'] }}">
@endif

<div class="barra"></div>
<div class="barra-oro"></div>

<div class="cab">
    <table>
        <tr>
            <td style="width: 30%;">
                <table style="border-collapse: collapse;">
                    <tr>
                        @if($logo)
                            <td style="padding-right: 8pt;">
                                <img src="{{ $logo['uri'] }}" style="width: {{ $logo['w'] }}pt; height: {{ $logo['h'] }}pt;">
                            </td>
                        @endif
                        <td>
                            @if($aseguradora)
                                <div class="aseg-nombre">{{ $aseguradora }}</div>
                            @endif
                            <div class="tit">Reporte fotográfico</div>
                        </td>
                    </tr>
                </table>
            </td>
            <td style="width: 70%;">
                <table class="datos">
                    @foreach(array_chunk($datos, 3, true) as $fila)
                        <tr>
                            @foreach($fila as $label => $valor)
                                <td>
                                    <div class="dl">{{ $label }}</div>
                                    <div class="dv">{{ $valor }}</div>
                                </td>
                            @endforeach
                            @for($c = count($fila); $c < 3; $c++)<td></td>@endfor
                        </tr>
                    @endforeach
                </table>
            </td>
        </tr>
    </table>
</div>
<div class="cab-linea"></div>

<div class="pie">
    <table>
        <tr>
            <td>
                @if($marca)
                    <img src="{{ $marca['uri'] }}" style="width: 14pt; vertical-align: middle; margin-right: 4pt;">
                @endif
                BalamCore · Reporte fotográfico · Siniestro {{ $numero }}
            </td>
            <td style="text-align: right;"><span class="pn"></span></td>
        </tr>
    </table>
</div>

@foreach($paginas as $pagina)
    <div class="pagina {{ $loop->last ? 'ultima' : '' }}">
        <table class="grid">
            @foreach(array_chunk($pagina, $lay['cols']) as $fila)
                @php $sep = $loop->last ? 0 : ($lay['gap'] ?? 6); @endphp
                <tr>
                    @foreach($fila as $f)
                        <td class="img" style="height: {{ $lay['h'] + 4 }}pt;">
                            <img class="foto" src="{{ $f['uri'] }}" style="width: {{ $f['w'] }}pt; height: {{ $f['h'] }}pt;">
                        </td>
                    @endforeach
                    @for($c = count($fila); $c < $lay['cols']; $c++)<td class="img"></td>@endfor
                </tr>
                <tr>
                    @foreach($fila as $f)
                        <td class="cap" style="padding-bottom: {{ $sep }}pt;">{{ $f['pie'] }}</td>
                    @endforeach
                    @for($c = count($fila); $c < $lay['cols']; $c++)<td class="cap" style="padding-bottom: {{ $sep }}pt;"></td>@endfor
                </tr>
            @endforeach
        </table>
    </div>
@endforeach

</body>
</html>