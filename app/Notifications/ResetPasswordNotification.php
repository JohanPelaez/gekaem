<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $token,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Build the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $url = route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ], true);

        return (new MailMessage)
            ->subject('Restablece tu contraseña — gipPyme')
            ->greeting('Hola ' . ($notifiable->name ?? '') . ',')
            ->line('Recibimos una solicitud para restablecer la contraseña de tu cuenta en gipPyme.')
            ->action('Restablecer Contraseña', $url)
            ->line('Este enlace expirará en 60 minutos por motivos de seguridad.')
            ->line('Si no realizaste esta solicitud, puedes ignorar este correo de forma segura; tu contraseña actual no sufrirá cambios.')
            ->salutation('Saludos, el equipo de gipPyme');
    }
}
