<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $titulo }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #222; }
        h1 { font-size: 16px; color: #114C3A; margin: 0 0 2px; }
        .sub { color: #666; margin-bottom: 10px; }
        /* table-layout fixed: todas las tablas (bloques) quedan con las mismas columnas y pesa menos */
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        thead { display: table-header-group; }
        th { background: #114C3A; color: #fff; text-align: left; padding: 5px; font-size: 9px; }
        td { padding: 4px 5px; border-bottom: 1px solid #ddd; word-wrap: break-word; }
        td.par { background: #f4f8f6; }
    </style>
</head>
<body>
    <h1>{{ $titulo }}</h1>
    <div class="sub">
        Generado el {{ $fecha }} · {{ count($filas) }} registro(s)
        @if($filtros) · {{ $filtros }} @endif
    </div>

    {{-- Tablas de 100 filas: dompdf gasta mucha menos memoria que con una sola tabla gigante --}}
    @foreach(array_chunk($filas, 100) as $bloque)
        <table>
            <thead>
                <tr>
                    @foreach($headers as $h)
                        <th>{{ $h }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach($bloque as $fila)
                    <tr>
                        @foreach($fila as $celda)
                            <td class="{{ $loop->parent->even ? 'par' : '' }}">{{ $celda }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endforeach
</body>
</html>