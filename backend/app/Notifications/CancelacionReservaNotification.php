<?php

namespace App\Notifications;

use App\Models\Reserva;
use App\Notifications\Concerns\DescribeReserva;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M05 — RF-M05-025, HU-M05-011 (Fase 4, tarea 4): la reserva se canceló (por el cliente o por el
 * personal) y, si había depósito, se le devuelve completo (RN-M05-17).
 */
class CancelacionReservaNotification extends Notification
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
        $mensaje = $this->mensajeBase($r, 'Tu reserva fue cancelada')
            ->line('Tu reserva del '.$this->fechaTexto($r).' quedó cancelada.');

        if ($r->deposito_pagado) {
            $mensaje->line($r->deposito_metodo === 'efectivo'
                ? 'Tu depósito de '.$this->pesos($r->deposito_valor).' se devuelve en efectivo en el local.'
                : 'Tu depósito de '.$this->pesos($r->deposito_valor).' se devuelve completo por el mismo medio con que pagaste.');
        }

        $this->resumenReserva($mensaje, $r, $r->deposito_pagado ? $this->pesos($r->deposito_valor).' (se devuelve)' : null);

        return $this->alFinal($mensaje, 'Esperamos verte pronto en Fragua Real Burger.');
    }
}
