<?php

namespace App\Notifications\Concerns;

use App\Models\Reserva;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Fase 4, tarea 4: partes comunes de los correos de reserva (fecha en español y hora de
 * Colombia, valor del depósito y el enlace para ver o cancelar la reserva sin cuenta).
 */
trait DescribeReserva
{
    // 28/10/2026: la tarjeta de resumen y pesos() vienen de la plantilla de la marca.
    use CorreoDeMarca;

    protected function fechaTexto(Reserva $reserva): string
    {
        return $reserva->fecha_hora->copy()->setTimezone('America/Bogota')->locale('es')
            ->isoFormat('dddd D [de] MMMM [a las] h:mm a');
    }

    protected function horaCorta(Reserva $reserva): string
    {
        return $reserva->fecha_hora->copy()->setTimezone('America/Bogota')->format('g:i a');
    }

    protected function mensajeBase(Reserva $reserva, string $asunto): MailMessage
    {
        return (new MailMessage)
            ->subject($asunto.' — Fragua Real Burger')
            ->greeting('Hola '.$reserva->cliente_nombre.',');
    }

    /** Mejora (28/10/2026): la reserva de un vistazo (fecha, hora, mesa y depósito) en una tarjeta. */
    protected function resumenReserva(MailMessage $mensaje, Reserva $reserva, ?string $deposito = null): MailMessage
    {
        $fecha = $reserva->fecha_hora->copy()->setTimezone('America/Bogota')->locale('es');

        return $this->conResumen($mensaje, [
            'Reserva' => '#'.$reserva->id,
            'Fecha' => ucfirst($fecha->isoFormat('dddd D [de] MMMM')),
            'Hora' => $fecha->format('g:i a'),
            'Mesa' => $reserva->mesa?->nombre,
            'Depósito' => $deposito,
        ]);
    }

    /** Botón al enlace público de la reserva (ver o cancelar sin cuenta). */
    protected function conEnlace(MailMessage $mensaje, Reserva $reserva, string $texto = 'Ver o cancelar mi reserva'): MailMessage
    {
        return $reserva->token_publico
            ? $mensaje->action($texto, $reserva->enlacePublico())
            : $mensaje;
    }
}
