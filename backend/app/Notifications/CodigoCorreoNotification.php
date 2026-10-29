<?php

namespace App\Notifications;

use App\Notifications\Concerns\CorreoDeMarca;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

// Seguridad (28/10/2026, RF-M01-037): el código de 6 dígitos con el que el Cliente confirma que el correo de su
// cuenta es suyo. Lleva el código en texto plano (en la caché solo queda su hash, ver VerificacionCorreoService).
class CodigoCorreoNotification extends Notification
{
    use CorreoDeMarca;
    use Queueable;

    public function __construct(
        private readonly string $codigo,
        private readonly int $minutos,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        // 28/10/2026: el código en grande y un botón a «Mi perfil», donde se escribe.
        $mensaje = (new MailMessage)
            ->subject("Tu código es {$this->codigo} — Fragua Real Burger")
            ->greeting('Hola '.$notifiable->nombre.',')
            ->line('Para confirmar que este correo es tuyo, escribe este código en «Confirma tu correo», en tu perfil de Fragua Real Burger:');

        return $this->conCodigo($mensaje, $this->codigo, "Vence en {$this->minutos} minutos")
            ->action('Ir a mi perfil', rtrim((string) config('fragua_real.frontend_url'), '/').'/perfil')
            ->line('Si no fuiste tú quien creó la cuenta, ignora este correo: sin el código nadie puede confirmarlo.');
    }
}
