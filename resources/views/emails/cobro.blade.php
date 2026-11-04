<!DOCTYPE html>
<html lang="es">
<body style="margin:0;padding:24px;background:#f3f6f4;font-family:Arial,Helvetica,sans-serif;color:#1d2b25;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:14px;overflow:hidden;border:1px solid #dfe7e2;">
    <div style="background:#0F4C3A;padding:20px 24px;color:#fff;font-size:18px;font-weight:700;">BalamCore</div>
    <div style="padding:24px;">
      <p style="margin:0 0 12px;">Hola, {{ $aseguradora }}:</p>

      @if($tipo === 'exitoso')
        <p style="margin:0 0 12px;">Renovamos tu plan <strong>{{ $plan }}</strong> ({{ $periodo }}) y cobramos <strong>${{ number_format($monto, 2) }}</strong>@if($tarjeta) a tu tarjeta {{ $tarjeta }}@endif.</p>
        @if($inicia && $vence)
          <p style="margin:0 0 12px;color:#52615a;font-size:14px;">Tu nuevo periodo va del {{ $inicia }} al {{ $vence }}.</p>
        @endif
        <p style="margin:0 0 20px;color:#52615a;font-size:14px;">Puedes descargar tu comprobante en Mi plan. Si no quieres que se renueve sola, desactiva la renovación automática ahí mismo.</p>
      @else
        <p style="margin:0 0 12px;">No pudimos cobrar la renovación de tu plan <strong>{{ $plan }}</strong> ({{ $periodo }}) por <strong>${{ number_format($monto, 2) }}</strong>@if($tarjeta) en tu tarjeta {{ $tarjeta }}@endif.</p>
        <p style="margin:0 0 12px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;border-radius:10px;padding:10px 12px;font-size:14px;">{{ $motivo }}</p>
        <p style="margin:0 0 12px;color:#52615a;font-size:14px;">Intento {{ $intento }} de {{ $maximo }}.
          @if($ultimo)
            Este fue el último intento automático: si no se paga antes del {{ $vence }}, tu acceso se pausará.
          @else
            Volveremos a intentarlo automáticamente. Para evitar que se pause tu acceso el {{ $vence }}, actualiza tu tarjeta o paga a mano.
          @endif
        </p>
      @endif

      <a href="{{ $url }}" style="display:inline-block;background:#0F4C3A;color:#fff;text-decoration:none;padding:12px 22px;border-radius:10px;font-weight:700;">Ir a Mi plan</a>
    </div>
  </div>
</body>
</html>