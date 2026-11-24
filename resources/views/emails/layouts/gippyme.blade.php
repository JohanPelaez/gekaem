<!DOCTYPE html>
<html lang="es" xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="x-apple-disable-message-reformatting">
    <title>@yield('title', 'gipPyme — Gestor Inteligente de Pedidos')</title>
</head>
<body style="margin: 0; padding: 0; width: 100%; background-color: #F1F5F9; font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; color: #334155; -webkit-font-smoothing: antialiased; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;">
    @hasSection('preheader')
        <div style="display: none; max-height: 0; overflow: hidden; mso-hide: all; font-size: 1px; line-height: 1px; color: #F1F5F9; opacity: 0;">
            @yield('preheader')&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;&#847;&zwnj;&nbsp;
        </div>
    @endif

    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; background-color: #F1F5F9; margin: 0; padding: 0; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 32px 16px;">
                <!-- Contenedor Principal -->
                <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width: 580px; width: 100%; background-color: #FFFFFF; border-radius: 16px; border: 1px solid #E2E8F0; overflow: hidden; box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.08), 0 4px 10px -6px rgba(15, 23, 42, 0.04); border-collapse: separate;">
                    <!-- Franja superior de acento de marca -->
                    <tr>
                        <td style="height: 4px; line-height: 4px; font-size: 4px; background-color: #4F46E5; background-image: linear-gradient(90deg, #4F46E5 0%, #06B6D4 100%); padding: 0;">
                            &nbsp;
                        </td>
                    </tr>

                    <!-- Cabecera de Marca gipPyme (100% tipográfica, sin SVG) -->
                    <tr>
                        <td align="center" style="background-color: #0F172A; padding: 28px 32px 24px 32px; text-align: center;">
                            <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto; border-collapse: collapse;">
                                <tr>
                                    <td align="center" style="font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;">
                                        <span style="display: inline-block; font-size: 28px; font-weight: 800; letter-spacing: -0.04em; line-height: 1.1; color: #FFFFFF;">gip</span><span style="display: inline-block; font-size: 28px; font-weight: 800; letter-spacing: -0.04em; line-height: 1.1; color: #06B6D4;">Pyme</span>
                                    </td>
                                </tr>
                                <tr>
                                    <td align="center" style="padding-top: 6px; font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 10px; font-weight: 700; letter-spacing: 0.16em; text-transform: uppercase; color: #06B6D4; line-height: 1.4;">
                                        GESTOR INTELIGENTE DE PEDIDOS
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>

                    <!-- Cuerpo del Correo -->
                    <tr>
                        <td style="padding: 36px 32px; font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; line-height: 1.65; color: #334155;">
                            @yield('content')
                        </td>
                    </tr>

                    <!-- Pie de Página (100% en español) -->
                    <tr>
                        <td style="padding: 0 32px 28px 32px;">
                            <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; border-top: 1px solid #E2E8F0; border-collapse: collapse;">
                                <tr>
                                    <td align="center" style="padding-top: 20px; font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 12px; line-height: 1.6; color: #64748B; text-align: center;">
                                        <p style="margin: 0 0 6px 0; color: #64748B;">
                                            &copy; {{ date('Y') }} gipPyme — Gestor Inteligente de Pedidos. Todos los derechos reservados.
                                        </p>
                                        <p style="margin: 0; color: #64748B;">
                                            ¿Necesitas ayuda? Escríbenos a
                                            <a href="mailto:soporte@gippyme.com" style="color: #4F46E5; text-decoration: none; font-weight: 600;">soporte@gippyme.com</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
