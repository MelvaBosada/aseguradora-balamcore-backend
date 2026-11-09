<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:24px;background:#f4f6f5;font-family:Arial,Helvetica,sans-serif;color:#1f2d27;">
  <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:10px;overflow:hidden;border:1px solid #e3e8e5;">
    <div style="background:#1B6B45;color:#ffffff;padding:18px 24px;">
      <div style="font-size:13px;opacity:.85;">{{ $aseguradora }}</div>
      <div style="font-size:19px;font-weight:bold;margin-top:2px;">{{ $titulo }}</div>
    </div>

    <div style="padding:22px 24px;font-size:14px;line-height:1.55;">
      <p>Hola, {{ $saludo }}:</p>

      @foreach ($parrafos as $p)
        <p>{{ $p }}</p>
      @endforeach

      @if (!empty($documentos))
        <table style="width:100%;border-collapse:collapse;margin:8px 0 14px;">
          @foreach ($documentos as $d)
            <tr>
              <td style="padding:9px 12px;border:1px solid #e3e8e5;background:#fafbfa;">
                <strong>{{ $d['nombre'] }}</strong>
                @if (!empty($d['detalle']))
                  <br><span style="color:#B3281B;font-size:13px;">{{ $d['detalle'] }}</span>
                @endif
                @if (!empty($d['limite']))
                  <br><span style="color:#667770;font-size:12px;">Fecha límite: {{ $d['limite'] }}@if (!empty($d['vencido'])) (vencida)@endif</span>
                @endif
              </td>
            </tr>
          @endforeach
        </table>
      @endif

      @foreach ($cierre as $c)
        <p>{{ $c }}</p>
      @endforeach

      <p style="margin-top:22px;">Atentamente,<br><strong>{{ $aseguradora }}</strong></p>
    </div>

    <div style="padding:12px 24px;background:#f4f6f5;font-size:11px;color:#7a8680;">
      Siniestro {{ $numero }} · Mensaje automático enviado desde BalamCore.
    </div>
  </div>
</body>
</html>
