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
            ->view(
                ['emails.reset-password', 'emails.reset-password-text'],
                [
                    'userName' => $notifiable->name ?? 'Usuario',
                    'resetUrl' => $url,
                    'expireMinutes' => config('auth.passwords.' . config('auth.defaults.passwords') . '.expire', 60),
                ],
            );
    }
}
