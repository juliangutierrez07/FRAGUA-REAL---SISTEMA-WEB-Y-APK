<?php

namespace App\Notifications;

use App\Models\Pedido;
use App\Notifications\Concerns\CorreoDeMarca;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M07 — RF-M07-R-012, RN-M07-R-03, HU-M07-012 (Fase 4, tarea 5): el pedido para recoger ya está
 * listo. Dice cuánto falta por pagar (al recoger, en efectivo o tarjeta) y lleva el enlace de
 * seguimiento del pedido. Correo en V1; el SMS queda para después (decisión D3).
 */
class ListoParaRecogerNotification extends Notification
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
        $nombre = $p->cliente?->nombre ?? $p->cliente_invitado_nombre;
        $saldo = max(0, (float) $p->total - $p->totalPagado());

        $mensaje = (new MailMessage)
            ->subject("Tu pedido #{$p->id} está listo para recoger — Fragua Real Burger")
            ->greeting($nombre ? "¡Hola {$nombre}, tu pedido está listo!" : '¡Tu pedido está listo!')
            ->line("Tu pedido #{$p->id} ya está listo. Te esperamos en el local para que lo recojas.");
        $this->conResumen($mensaje, [
            'Pedido' => "#{$p->id}",
            'Dónde' => app(\App\Services\NegocioService::class)->contacto()['direccion'],
            'Pago' => $saldo > 0 ? 'Pagas '.$this->pesos($saldo).' al recoger' : 'Ya está pagado',
        ]);
        if ($p->token_publico) {
            $mensaje->action('Ver mi pedido', $p->enlaceSeguimiento());
        }

        return $this->alFinal($mensaje, $saldo > 0
            ? 'Puedes pagar en efectivo o con tarjeta. Solo di tu nombre o el número del pedido.'
            : 'Solo di tu nombre o el número del pedido.');
    }
}
