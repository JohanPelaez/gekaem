@extends('emails.layouts.gippyme')

@section('title', 'Restablece tu contraseña — gipPyme')

@section('preheader', 'Usa este enlace seguro para crear una nueva contraseña en gipPyme. Expira en ' . ($expireMinutes ?? 60) . ' minutos.')

@section('content')
    <!-- Píldora superior de seguridad -->
    <div style="margin: 0 0 18px 0;">
        <span style="display: inline-block; background-color: #EEF2FF; color: #4F46E5; border-radius: 999px; font-size: 11px; font-weight: 700; letter-spacing: 0.05em; padding: 4px 12px; line-height: 1.4;">
            🔐 SEGURIDAD DE CUENTA
        </span>
    </div>

    <!-- Título principal -->
    <h1 style="margin: 0 0 14px 0; font-size: 22px; font-weight: 700; line-height: 1.3; color: #0F172A;">
        Hola {{ $userName }},
    </h1>

    <!-- Párrafo introductorio -->
    <p style="margin: 0 0 26px 0; font-size: 15px; line-height: 1.65; color: #334155;">
        Recibimos una solicitud para restablecer la contraseña de tu cuenta en <strong>gipPyme</strong>. Haz clic en el siguiente botón para elegir una nueva clave de acceso:
    </p>

    <!-- Botón CTA Principal (Tabla centrada compatible con Outlook/Gmail) -->
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; margin: 0 0 28px 0; border-collapse: collapse;">
        <tr>
            <td align="center" style="padding: 4px 0;">
                <table role="presentation" cellspacing="0" cellpadding="0" border="0" align="center" style="margin: 0 auto; border-collapse: separate;">
                    <tr>
                        <td align="center" bgcolor="#4F46E5" style="background-color: #4F46E5; border-radius: 10px;">
                            <a href="{{ $resetUrl }}" target="_blank" rel="noopener noreferrer" style="display: inline-block; background-color: #4F46E5; color: #FFFFFF; font-family: Inter, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 15px; font-weight: 600; line-height: 1.2; text-decoration: none; padding: 14px 28px; border-radius: 10px;">
                                Restablecer Contraseña
                            </a>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>

    <!-- Caja de Aviso de Caducidad (Callout) -->
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; margin: 0 0 26px 0; background-color: #F8FAFC; border: 1px solid #E2E8F0; border-left: 4px solid #06B6D4; border-radius: 8px; border-collapse: separate;">
        <tr>
            <td style="padding: 14px 16px; font-size: 13px; line-height: 1.6; color: #475569;">
                <p style="margin: 0 0 6px 0; font-weight: 600; color: #0F172A;">
                    ⏱️ Este enlace expirará en {{ $expireMinutes ?? 60 }} minutos por motivos de seguridad.
                </p>
                <p style="margin: 0; color: #475569;">
                    Si no solicitaste este cambio, puedes ignorar este correo de forma segura; tu contraseña actual permanecerá intacta.
                </p>
            </td>
        </tr>
    </table>

    <!-- Firma -->
    <p style="margin: 0 0 24px 0; font-size: 14px; line-height: 1.6; color: #334155;">
        Saludos,<br>
        <strong style="color: #0F172A;">El equipo de gipPyme</strong>
    </p>

    <!-- Bloque de Enlace Alternativo 100% en Español -->
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="width: 100%; border-top: 1px solid #E2E8F0; border-collapse: collapse;">
        <tr>
            <td style="padding-top: 18px; font-size: 12px; line-height: 1.6; color: #64748B;">
                <p style="margin: 0 0 8px 0; color: #64748B;">
                    Si tienes problemas al hacer clic en el botón "Restablecer Contraseña", copia y pega el siguiente enlace en tu navegador web:
                </p>
                <p style="margin: 0; word-break: break-all; overflow-wrap: anywhere;">
                    <a href="{{ $resetUrl }}" target="_blank" rel="noopener noreferrer" style="color: #4F46E5; text-decoration: underline; word-break: break-all; overflow-wrap: anywhere;">{{ $resetUrl }}</a>
                </p>
            </td>
        </tr>
    </table>
@endsection
