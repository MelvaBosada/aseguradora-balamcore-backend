<!DOCTYPE html>
<html lang="es">
<body style="margin:0;padding:24px;background:#f3f6f4;font-family:Arial,Helvetica,sans-serif;color:#1d2b25;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #dfe7e2;">
    <div style="background:#0F4C3A;padding:20px 24px;color:#fff;font-size:18px;font-weight:700;">BalamCore</div>
    <div style="padding:24px;">
      <p style="margin:0 0 12px;">Hola, {{ $aseguradora }}:</p>

      @if($dias < 0)
        <p style="margin:0 0 12px;">Tu plan <strong>{{ $plan }}</strong> venció el <strong>{{ $vence }}</strong> y tu acceso está pausado.</p>
      @elseif($dias === 0)
        <p style="margin:0 0 12px;">Tu plan <strong>{{ $plan }}</strong> vence <strong>hoy</strong>.</p>
      @else
        <p style="margin:0 0 12px;">Tu plan <strong>{{ $plan }}</strong> vence el <strong>{{ $vence }}</strong> (en {{ $dias }} {{ $dias === 1 ? 'día' : 'días' }}).</p>
      @endif

      @if($cambio)
        <p style="margin:0 0 12px;">{{ $cambio }}</p>
      @endif

      @if($auto)
        <p style="margin:0 0 20px;color:#52615a;font-size:14px;">
          Lo renovaremos automáticamente (<strong>{{ $plan_sig }}</strong> por <strong>${{ number_format($monto, 2) }}</strong>) en tu tarjeta {{ $tarjeta }}. Si prefieres no renovar, desactiva la renovación automática o cancela tu suscripción antes de esa fecha.
        </p>
      @else
        <p style="margin:0 0 20px;color:#52615a;font-size:14px;">
          Tu plan no se renueva automáticamente y no haremos ningún cargo sin que tú lo confirmes. Para no perder ni un día, renuévalo por adelantado o activa la renovación automática.
        </p>
      @endif

      <a href="{{ $url }}" style="display:inline-block;background:#0F4C3A;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:700;">Ver mi plan</a>
    </div>
  </div>
</body>
</html>