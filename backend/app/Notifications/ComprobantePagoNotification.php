<?php

namespace App\Notifications;

use App\Models\Pedido;
use App\Notifications\Concerns\CorreoDeMarca;
use App\Services\ComprobanteService;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use RuntimeException;

/**
 * M06 — RF-M06-010 (Fase 5, tarea 1): el comprobante digital de un domicilio o una recogida, en
 * PDF adjunto, cuando el pago queda completo. Lleva también el enlace de seguimiento del pedido,
 * desde donde se puede volver a descargar.
 */
class ComprobantePagoNotification extends Notification
{
    use CorreoDeMarca;

    public function __construct(public readonly Pedido $pedido)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $p = $this->pedido;
        // El vigente; si se anuló mientras el correo esperaba en la cola, el último (sale marcado ANULADO).
        $comprobante = $p->comprobante()->first() ?? $p->comprobantes()->latest('id')->first();
        if (! $comprobante) {
            throw new RuntimeException("El pedido #{$p->id} no tiene comprobante.");
        }
        $servicio = app(ComprobanteService::class);
        $nombre = $p->cliente?->nombre ?? $p->cliente_invitado_nombre;

        $mensaje = (new MailMessage)
            ->subject("Tu comprobante de pago {$comprobante->numero} — Fragua Real Burger")
            ->greeting($nombre ? "Hola {$nombre}," : 'Hola,')
            ->line("Recibimos el pago de tu pedido #{$p->id}. Te enviamos el comprobante en PDF, adjunto a este correo.")
            ->attachData($servicio->contenido($comprobante), $servicio->nombreArchivo($comprobante), ['mime' => 'application/pdf']);
        $this->conResumen($mensaje, [
            'Comprobante' => $comprobante->numero,
            'Pedido' => "#{$p->id}",
            'Total pagado' => $this->pesos($p->total),
        ]);
        if ($p->token_publico) {
            $mensaje->action('Ver mi pedido', $p->enlaceSeguimiento());
        }

        return $this->alFinal($mensaje, '¡Gracias por pedir en Fragua Real Burger!');
    }
}
