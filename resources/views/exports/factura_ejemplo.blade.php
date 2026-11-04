<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="utf-8">
  <title>Factura de ejemplo EJ-{{ $folio }}</title>
  <style>
    @page { margin: 22px 26px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8.5px; color: #222; }
    .marca { position: fixed; top: 340px; left: -40px; width: 130%; text-align: center;
             font-size: 56px; font-weight: bold; color: #ecd9d9; transform: rotate(-32deg); letter-spacing: 2px; }
    .aviso { background: #b91c1c; color: #fff; text-align: center; font-weight: bold; font-size: 11px; padding: 6px; }
    table { width: 100%; border-collapse: collapse; }
    td, th { padding: 3px 5px; vertical-align: top; }
    .barra { background: #7f0000; color: #fff; font-weight: bold; font-size: 11px; padding: 6px 8px; }
    .tit { background: #d9d9d9; text-align: center; font-weight: bold; border: 1px solid #bbb; }
    .caja { border: 1px solid #bbb; }
    .lbl { font-weight: bold; }
    .grid th { background: #e6e6e6; font-size: 8px; text-align: left; border: 1px solid #bbb; }
    .grid td { border: 1px solid #ddd; }
    .r { text-align: right; }
    .sello { border: 1px solid #bbb; padding: 5px; font-size: 7px; color: #777; }
    .qr { width: 96px; height: 96px; border: 2px dashed #999; text-align: center; font-size: 8px; color: #777; padding-top: 38px; }
    .tot td { padding: 4px 8px; border-bottom: 1px solid #ddd; }
    .pie { margin-top: 10px; font-size: 8.5px; font-weight: bold; color: #b91c1c; text-align: center; }
  </style>
</head>
<body>
  <div class="marca">EJEMPLO / SIN VALIDEZ FISCAL</div>

  <div class="aviso">EJEMPLO / SIN VALIDEZ FISCAL — Plantilla visual de demostración. No es un CFDI.</div>

  <table style="margin-top:6px;">
    <tr>
      <td class="barra" width="70%">CFDI VERSIÓN 4.0 — FORMATO DE EJEMPLO</td>
      <td class="r" style="font-weight:bold;">SERIE Y FOLIO: EJ-{{ $folio }}</td>
    </tr>
  </table>

  <table style="margin-top:8px;">
    <tr>
      <td width="30%">
        <div style="border:1px solid #bbb; height:62px; text-align:center; padding-top:20px; font-size:15px; font-weight:bold; color:#0F4C3A;">
          BalamCore
        </div>
      </td>
      <td class="caja">
        <table>
          <tr><td class="lbl">NO. DE SERIE DEL CERTIFICADO DEL EMISOR</td><td class="lbl">FECHA DE EMISIÓN</td></tr>
          <tr><td>00000000000000000000</td><td>{{ $emitido }}</td></tr>
          <tr><td class="lbl">NO. DE SERIE DEL CERTIFICADO DEL SAT</td><td class="lbl">FECHA DE CERTIFICACIÓN</td></tr>
          <tr><td>00000000000000000000</td><td>— (ejemplo)</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <p class="r" style="margin:6px 0;"><span class="lbl">FOLIO FISCAL:</span> 00000000-0000-0000-0000-000000000000</p>

  <table>
    <tr>
      <td width="50%" style="padding-right:5px;">
        <div class="tit">EMISOR</div>
        <table class="caja">
          <tr><td class="lbl" width="30%">NOMBRE:</td><td>BALAMCORE SISTEMAS (EJEMPLO)</td></tr>
          <tr><td class="lbl">RFC:</td><td>EJE000000XX0</td></tr>
          <tr><td class="lbl">RÉGIMEN FISCAL:</td><td>601 - General de Ley Personas Morales</td></tr>
        </table>
      </td>
      <td width="50%" style="padding-left:5px;">
        <div class="tit">RECEPTOR</div>
        <table class="caja">
          <tr><td class="lbl" width="32%">NOMBRE:</td><td>{{ $aseg->razon_social ?: $aseg->nombre }}</td></tr>
          <tr><td class="lbl">RFC:</td><td>{{ $aseg->rfc }}</td></tr>
          <tr><td class="lbl">USO CFDI:</td><td>G03 - Gastos en general</td></tr>
          <tr><td class="lbl">DOMICILIO FISCAL:</td><td>{{ $aseg->codigo_postal }}</td></tr>
          <tr><td class="lbl">RÉGIMEN FISCAL:</td><td>601 (ejemplo)</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div class="tit" style="margin-top:8px;">DATOS GENERALES</div>
  <table class="caja">
    <tr>
      <td width="50%"><span class="lbl">LUGAR EXPEDICIÓN:</span> 00000 (ejemplo)</td>
      <td><span class="lbl">TIPO COMPROBANTE:</span> I - Ingreso</td>
    </tr>
    <tr>
      <td><span class="lbl">MÉTODO DE PAGO:</span> PUE - Pago en una sola exhibición</td>
      <td><span class="lbl">EXPORTACIÓN:</span> 01 - No aplica</td>
    </tr>
    <tr>
      <td><span class="lbl">FORMA DE PAGO:</span> 04 - Tarjeta de crédito</td>
      <td><span class="lbl">MONEDA:</span> MXN - Peso Mexicano</td>
    </tr>
  </table>

  <table class="grid" style="margin-top:8px;">
    <tr>
      <th>CLAVE</th><th>UNIDAD</th><th>CANT</th><th>NO. ID</th><th>DESCRIPCIÓN</th>
      <th class="r">VALOR UNITARIO</th><th class="r">DESC</th><th class="r">IMPORTE</th>
    </tr>
    <tr>
      <td>00000000 (ejemplo)</td>
      <td>E48</td>
      <td>1.00</td>
      <td>{{ $s->id }}</td>
      <td>Suscripción BalamCore — Plan {{ $s->plan }} ({{ $s->periodo ?: 'mensual' }}), del {{ $inicio }} al {{ $fin }}</td>
      <td class="r">${{ number_format($subtotal, 2) }}</td>
      <td class="r">$0.00</td>
      <td class="r">${{ number_format($subtotal, 2) }}</td>
    </tr>
    <tr>
      <td colspan="4" style="font-size:7.5px;">OBJETO IMPUESTO: 02 Sí objeto de impuesto</td>
      <td colspan="4" style="font-size:7.5px;">IMPUESTOS TRASLADADOS: 002 - IVA Tasa 16.00 %  ${{ number_format($iva, 2) }}</td>
    </tr>
  </table>

  <table style="margin-top:8px;">
    <tr>
      <td width="55%">
        <p style="margin:0 0 4px;"><span class="lbl">OBSERVACIONES:</span> Documento de demostración generado por BalamCore. IVA 16% desglosado de ejemplo.</p>
        <p style="margin:0;"><span class="lbl">SON:</span> (importe con letra — campo de ejemplo)</p>
      </td>
      <td>
        <table class="tot caja">
          <tr><td class="lbl">SUBTOTAL</td><td class="r">${{ number_format($subtotal, 2) }}</td></tr>
          <tr><td>002 - IVA Tasa 16.00 %</td><td class="r">${{ number_format($iva, 2) }}</td></tr>
          <tr><td class="lbl">IMPUESTOS TRASLADADOS</td><td class="r">${{ number_format($iva, 2) }}</td></tr>
          <tr><td class="lbl" style="font-size:11px;">TOTAL</td><td class="r lbl" style="font-size:11px;">${{ number_format($total, 2) }}</td></tr>
        </table>
      </td>
    </tr>
  </table>

  <div class="sello" style="margin-top:10px;">
    <strong>CADENA ORIGINAL DEL COMPLEMENTO DE CERTIFICACIÓN DIGITAL DEL SAT (EJEMPLO)</strong><br>
    ||1.1|00000000-0000-0000-0000-000000000000|EJEMPLO-SIN-VALIDEZ-FISCAL||
  </div>

  <table style="margin-top:8px;">
    <tr>
      <td width="110"><div class="qr">QR DE EJEMPLO</div></td>
      <td>
        <div class="sello"><strong>SELLO DIGITAL DEL CFDI (EJEMPLO)</strong><br>
          {{ str_repeat('EJEMPLO-SIN-VALIDEZ-FISCAL/', 6) }}</div>
        <div class="sello" style="margin-top:6px;"><strong>SELLO DEL SAT (EJEMPLO)</strong><br>
          {{ str_repeat('EJEMPLO-SIN-VALIDEZ-FISCAL/', 6) }}</div>
      </td>
    </tr>
  </table>

  <p class="pie">EJEMPLO / SIN VALIDEZ FISCAL — ESTE DOCUMENTO NO ES UN CFDI Y NO SE PUEDE USAR ANTE EL SAT</p>
</body>
</html>