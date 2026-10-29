<?php

namespace App\Notifications;

use App\Models\Reserva;
use App\Notifications\Concerns\DescribeReserva;
use App\Support\Reservas\PoliticaReservas;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M05 — RF-M05-023, HU-M05-008/009 (Fase 4, tarea 4): la reserva quedó confirmada (depósito
 * pagado). Lleva el enlace para verla o cancelarla sin cuenta (RF-M05-018).
 */
class ConfirmacionReservaNotification extends Notification
{
    use DescribeReserva;

    public function __construct(public readonly Reserva $reserva)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $r = $this->reserva;
        $mesa = $r->mesa?->nombre;

        $mensaje = $this->mensajeBase($r, 'Tu reserva está confirmada')
            ->line('¡Listo! Tu reserva en Fragua Real Burger quedó confirmada para el '.$this->fechaTexto($r).($mesa ? " ({$mesa})." : '.'))
            ->line('Recibimos tu depósito de '.$this->pesos($r->deposito_valor).'. Se descuenta de tu cuenta el día de tu visita.');
        // Corrección (19/10/2026): la nota que dejó el cliente, para que vea que la recibimos.
        if (filled($r->descripcion)) {
            $mensaje->line('Tu nota para el restaurante: «'.$r->descripcion.'»');
        }
        $this->resumenReserva($mensaje, $r, $this->pesos($r->deposito_valor).' (pagado)');

        return $this->alFinal($this->conEnlace($mensaje, $r), 'Te esperamos hasta '.PoliticaReservas::toleranciaMinutos().' minutos después de la hora; si no llegas, la reserva se libera y el depósito no se devuelve. Si cancelas antes de la hora, te lo devolvemos completo.');
    }
}
