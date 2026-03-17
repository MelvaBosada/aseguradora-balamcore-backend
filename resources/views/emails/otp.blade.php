<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Código de verificación</title>
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
                Bienvenido a BalamCore
              </p>
              <p style="margin:0 0 28px;color:#6b7280;font-size:14px;line-height:1.6;">
                Recibimos una solicitud para verificar tu correo electrónico.
                Usa el siguiente código para continuar con tu registro:
              </p>

              <!-- Código OTP -->
              <table width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td align="center" style="padding:24px 0;">
                    <div style="display:inline-block;background:#f0faf5;border:2px dashed #1a6b46;border-radius:12px;padding:20px 48px;">
                      <span style="font-size:42px;font-weight:800;letter-spacing:14px;color:#1a6b46;font-family:'Courier New',monospace;">
                        {{ $codigo }}
                      </span>
                    </div>
                  </td>
                </tr>
              </table>

              <!-- Aviso de expiración -->
              <table width="100%" cellpadding="0" cellspacing="0" style="margin:8px 0 28px;">
                <tr>
                  <td style="background:#fffbeb;border-left:4px solid #f59e0b;border-radius:0 8px 8px 0;padding:12px 16px;">
                    <p style="margin:0;color:#92400e;font-size:13px;">
                      ⏱ Este código expira en <strong>10 minutos</strong>.
                      Si no solicitaste esto, ignora este correo.
                    </p>
                  </td>
                </tr>
              </table>

              <p style="margin:0;color:#6b7280;font-size:13px;line-height:1.6;">
                Si tienes algún problema, contáctanos a
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