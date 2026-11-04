<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Comprobante {{ $folio }}</title>
  <style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1d2b25; }
    .top { background: #0F4C3A; color: #fff; padding: 18px 22px; }
    .top h1 { margin: 0; font-size: 20px; }
    .top p { margin: 3px 0 0; font-size: 10px; color: #cfe6dc; }
    .gold { height: 4px; background: #D4AF37; }
    .box { margin: 18px 0; }
    h3 { margin: 0 0 6px; font-size: 11px; text-transform: uppercase; letter-spacing: .06em; color: #0F4C3A; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 7px 8px; text-align: left; border-bottom: 1px solid #e3e9e5; }
    th { background: #f1f6f3; font-size: 10px; text-transform: uppercase; color: #52615a; }
    .r { text-align: right; }
    .total td { font-size: 14px; font-weight: bold; border-bottom: none; color: #0F4C3A; }
    .nota { margin-top: 26px; font-size: 9.5px; color: #64748b; line-height: 1.5; }
  </style>
</head>
<body>
  <div class="top">
    <h1>BalamCore</h1>
    <p>Comprobante de pago · Folio {{ $folio }} · Emitido el {{ $emitido }}</p>
  </div>
  <div class="gold"></div>

  <div class="box">
    <h3>Cliente</h3>
    <table>
      <tr><td width="28%"><strong>Agencia</strong></td><td>{{ $aseg->nombre }}</td></tr>
      <tr><td><strong>Razón social</strong></td><td>{{ $aseg->razon_social }}</td></tr>
      <tr><td><strong>RFC</strong></td><td>{{ $aseg->rfc }}</td></tr>
      <tr><td><strong>Correo</strong></td><td>{{ $aseg->correo }}</td></tr>
    </table>
  </div>

  <div class="box">
    <h3>Detalle</h3>
    <table>
      <tr><th>Concepto</th><th>Vigencia</th><th class="r">Importe</th></tr>
      <tr>
        <td>Plan {{ $s->plan }} ({{ $s->periodo ?: 'mensual' }})</td>
        <td>{{ $inicio }} al {{ $fin }}</td>
        <td class="r">${{ number_format($lista, 2) }}</td>
      </tr>
      @if($credito > 0)
      <tr>
        <td colspan="2">Crédito por el tiempo no usado de tu plan anterior</td>
        <td class="r">- ${{ number_format($credito, 2) }}</td>
      </tr>
      @endif
      <tr class="total">
        <td colspan="2">Total pagado</td>
        <td class="r">${{ number_format($cobrado, 2) }} MXN</td>
      </tr>
      @if(($s->reembolsado_monto ?? 0) > 0)
      <tr>
        <td colspan="2">Reembolsado</td>
        <td class="r">- ${{ number_format($s->reembolsado_monto, 2) }}</td>
      </tr>
      @endif
    </table>
  </div>

  <div class="box">
    <h3>Operación</h3>
    <table>
      <tr><td width="28%"><strong>Método</strong></td><td>Tarjeta (procesado por Stripe)</td></tr>
      <tr><td><strong>Referencia</strong></td><td>{{ $referencia }}</td></tr>
    </table>
  </div>

  <p class="nota">Este documento es un comprobante de pago y no sustituye una factura fiscal (CFDI).</p>
</body>
</html>