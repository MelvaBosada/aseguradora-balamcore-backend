<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Aviso de vencimiento de póliza</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:'Segoe UI',Arial,sans-serif;">

  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f9;padding:40px 0;">
    <tr>
      <td align="center">
        <table width="560" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">

          <!-- Header verde -->
          <tr>
            <td style="background:linear-gradient(135deg,#1a6b46,#2d9e6b);padding:36px 40px;text-align:center;">
              <h1 style="margin:0;color:#ffffff;font-size:24px;font-weight:700;letter-spacing:1px;">
                BalamCore
              </h1>
              <p style="margin:6px 0 0;color:#a8e6c8;font-size:13px;">Sistema de Gestión de Aseguradoras</p>
            </td>
          </tr>

          <!-- Cuerpo -->
          <tr>
            <td style="padding:40px 40px 32px;">
              <p style="margin:0 0 8px;color:#374151;font-size:16px;font-weight:600;">
                Estimado/a {{ $nombre }} {{ $apellidos }},
              </p>
              <p style="margin:0 0 28px;color:#6b7280;font-size:14px;line-height:1.6;">
                Le informamos que su póliza está próxima a vencer. A continuación encontrará los detalles:
              </p>

              <!-- Datos de la póliza -->
              <table width="100%" cellpadding="0" cellspacing="0" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:12px;overflow:hidden;margin-bottom:24px;">
                <tr style="border-bottom:1px solid #e5e7eb;">
                  <td style="padding:12px 20px;color:#6b7280;font-size:13px;width:45%;">N° Póliza</td>
                  <td style="padding:12px 20px;color:#1f2d2a;font-size:13px;font-weight:700;">{{ $nPoliza }}</td>
                </tr>
                <tr style="border-bottom:1px solid #e5e7eb;">
                  <td style="padding:12px 20px;color:#6b7280;font-size:13px;">Tipo</td>
                  <td style="padding:12px 20px;color:#1f2d2a;font-size:13px;">{{ $tipo }}</td>
                </tr>
                <tr style="border-bottom:1px solid #e5e7eb;">
                  <td style="padding:12px 20px;color:#6b7280;font-size:13px;">Fecha de vencimiento</td>
                  <td style="padding:12px 20px;color:#dc2626;font-size:13px;font-weight:700;">{{ $vence }}</td>
                </tr>
                <tr style="border-bottom:1px solid #e5e7eb;">
                  <td style="padding:12px 20px;color:#6b7280;font-size:13px;">Días restantes</td>
                  <td style="padding:12px 20px;color:#dc2626;font-size:13px;font-weight:700;">{{ $diasRestantes }} día(s)</td>
                </tr>
                <tr>
                  <td style="padding:12px 20px;color:#6b7280;font-size:13px;">Asesor asignado</td>
                  <td style="padding:12px 20px;color:#1f2d2a;font-size:13px;">{{ $asesor }}</td>
                </tr>
              </table>

              <!-- Aviso -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 28px;">
                <tr>
                  <td style="background:#fffbeb;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:12px 16px;">
                    <p style="margin:0;color:#92400e;font-size:13px;">
                      ⚠️ Para evitar la cancelación de su póliza, contáctese con su asesor a la brevedad posible.
                    </p>
                  </td>
                </tr>
              </table>

              <p style="margin:0;color:#6b7280;font-size:13px;line-height:1.6;">
                Si tiene alguna duda, contáctenos a
                <a href="mailto:balamcoresystem@gmail.com" style="color:#1a6b46;text-decoration:none;font-weight:600;">
                  balamcoresystem@gmail.com
                </a>
              </p>
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:20px 40px;text-align:center;">
              <p style="margin:0;color:#9ca3af;font-size:12px;">
                © {{ date('Y') }} BalamCore · Todos los derechos reservados
              </p>
              <p style="margin:4px 0 0;color:#d1d5db;font-size:11px;">
                Este correo fue enviado automáticamente, por favor no respondas.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>

</body>
</html>
