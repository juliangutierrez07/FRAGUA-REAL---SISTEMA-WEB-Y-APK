<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// RF-M01-012/013: correo real de recuperación de contraseña — cierra el pendiente que
// README-BACKEND.md, "Qué falta a propósito", dejaba anotado ("AuthController::
// solicitarRecuperacion() genera y guarda el token pero no lo envía").
//
// Lleva el token EN TEXTO PLANO (nunca el hash que AuthController guarda en
// password_reset_tokens — ver solicitarRecuperacion()) dentro de un enlace a
// /restablecer-password?email=&token=, que RestablecerPasswordView.vue ya sabe precargar
// (su comentario original anticipaba exactamente este correo).
//
// Es una Notification (no un Mailable suelto) para poder encolarla sin más cambios el día
// que QUEUE_CONNECTION deje de ser 'sync' — Queueable ya la deja lista para eso.
class RecuperarPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly string $token,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('fragua_real.frontend_url'), '/').'/restablecer-password?'.http_build_query([
            'email' => $notifiable->email,
            'token' => $this->token,
        ]);

        return (new MailMessage)
            ->subject('Recupera tu contraseña — Fragua Real Burger')
            ->greeting('Hola '.$notifiable->nombre.',')
            ->line('Recibimos una solicitud para cambiar la contraseña de tu cuenta en Fragua Real Burger. Toca el botón para elegir una nueva.')
            ->action('Elegir nueva contraseña', $url)
            ->line('El enlace sirve durante una hora. Si no fuiste tú quien lo pidió, ignora este correo: tu contraseña actual sigue funcionando.');
    }
}
