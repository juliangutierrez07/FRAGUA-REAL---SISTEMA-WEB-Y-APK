<?php

namespace App\Notifications;

use App\Models\Reserva;
use App\Notifications\Concerns\DescribeReserva;
use App\Support\Reservas\PoliticaReservas;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * M05 — RF-M05-024, RN-M05-12/13 (Fase 4, tarea 3, 13/10/2026): recordatorio por correo antes
 * de la hora de la reserva (decisión D3: correo en V1; el SMS queda para después). Desde la
 * tarea 4 lo envía el trabajo EnviarAvisoReserva desde la cola, que registra si salió o falló
 * (RF-M05-027).
 */
class RecordatorioReservaNotification extends Notification
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
        $hora = $this->reserva->fecha_hora->copy()->setTimezone('America/Bogota');
        $mesa = $this->reserva->mesa?->nombre;
        $tolerancia = PoliticaReservas::toleranciaMinutos();

        $mensaje = $this->mensajeBase($this->reserva, 'Te esperamos a las '.$hora->format('g:i a'))
            ->line('Te recordamos tu reserva en Fragua Real Burger para el '.$this->fechaTexto($this->reserva).($mesa ? " ({$mesa})." : '.'))
            ->line("Te guardamos la mesa hasta {$tolerancia} minutos después de esa hora. Si no alcanzas a llegar, la reserva se libera y el depósito no se devuelve.");
        $this->conResumen($mensaje, [
            'Reserva' => '#'.$this->reserva->id,
            'Hora' => $hora->format('g:i a'),
            'Mesa' => $mesa,
            'Te guardamos la mesa hasta' => $hora->copy()->addMinutes($tolerancia)->format('g:i a'),
        ]);

        return $this->alFinal($this->conEnlace($mensaje, $this->reserva), 'Si necesitas cancelar, hazlo antes de la hora y te devolvemos el depósito completo.');
    }
}
