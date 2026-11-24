<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertStatus(200);
    }

    public function test_reset_link_sends_spanish_notification_and_hides_user_existence(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'name' => 'María Gómez',
            'email' => 'maria.reset.test@gippyme.test',
        ]);

        // Existing user
        $response = $this->post('/forgot-password', [
            'email' => $user->email,
        ]);

        $response->assertRedirect();
        $response->assertSessionHas('status', 'Si el correo está registrado en gipPyme, recibirás un enlace para restablecer tu contraseña en los próximos minutos.');

        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            $html = view($mail->view[0], $mail->viewData)->render();
            $text = view($mail->view[1], $mail->viewData)->render();

            return $mail->subject === 'Restablece tu contraseña — gipPyme'
                && $mail->view === ['emails.reset-password', 'emails.reset-password-text']
                && str_contains($html, 'Hola María Gómez,')
                && str_contains($html, 'SEGURIDAD DE CUENTA')
                && str_contains($html, '/reset-password/' . $notification->token)
                && str_contains($html, 'soporte@gippyme.com')
                && !str_contains($html, '<svg')
                && str_contains($text, 'Hola María Gómez,')
                && str_contains($text, '/reset-password/' . $notification->token);
        });

        // Non-existing user returns same generic message without exposing existence
        $nonExistingResponse = $this->post('/forgot-password', [
            'email' => 'no-existe@gippyme.test',
        ]);

        $nonExistingResponse->assertRedirect();
        $nonExistingResponse->assertSessionHas('status', 'Si el correo está registrado en gipPyme, recibirás un enlace para restablecer tu contraseña en los próximos minutos.');
    }

    public function test_password_can_be_reset_with_valid_token_and_fails_with_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'carlos.reset.test@gippyme.test',
            'password' => Hash::make('old-password-123'),
        ]);

        $token = Password::broker()->createToken($user);

        // View reset form
        $this->get('/reset-password/' . $token . '?email=' . urlencode($user->email))
            ->assertStatus(200);

        // Invalid token fails
        $invalidResponse = $this->post('/reset-password', [
            'token' => 'invalid-token-value',
            'email' => $user->email,
            'password' => 'nueva-clave-segura',
            'password_confirmation' => 'nueva-clave-segura',
        ]);

        $invalidResponse->assertSessionHasErrors('email');

        // Valid token succeeds
        $validResponse = $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'nueva-clave-segura',
            'password_confirmation' => 'nueva-clave-segura',
        ]);

        $validResponse->assertRedirect(route('login'));
        $validResponse->assertSessionHas('status', 'Tu contraseña ha sido actualizada correctamente. Ya puedes iniciar sesión.');

        $user->refresh();
        $this->assertTrue(Hash::check('nueva-clave-segura', $user->password));
    }
}
