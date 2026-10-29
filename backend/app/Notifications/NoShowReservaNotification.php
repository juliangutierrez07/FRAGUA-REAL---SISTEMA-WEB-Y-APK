<?php

namespace App\Notifications;

use App\Models\Reserva;
use App\Notifications\Concerns\DescribeReserva;
use App\Support\Reservas\PoliticaReservas;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M05 — RF-M05-025 (Fase 4, tarea 4): la reserva se liberó por no-show (el cliente no llegó dentro
 * de la tolerancia); el depósito se retiene (RN-M05-18).
 */
class NoShowReservaNotification extends Notification
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
        $mensaje = $this->mensajeBase($r, 'Tu reserva se liberó')
            ->line('No te vimos llegar a tu reserva del '.$this->fechaTexto($r).', así que la mesa se liberó '
                .PoliticaReservas::toleranciaMinutos().' minutos después de la hora.');

        if ($r->deposito_pagado) {
            $mensaje->line('Como indican las condiciones de la reserva, el depósito de '.$this->pesos($r->deposito_valor).' no se devuelve.');
        }

        $this->resumenReserva($mensaje, $r, $r->deposito_pagado ? $this->pesos($r->deposito_valor).' (no se devuelve)' : null);

        return $this->alFinal($mensaje, 'Si crees que es un error, comunícate con el restaurante.');
    }
}
