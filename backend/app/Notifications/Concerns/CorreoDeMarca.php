<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Mejora (28/10/2026): piezas de la plantilla de correo de la marca (resources/views/vendor/notifications/
 * email.blade.php): una tarjeta de resumen (Pedido #12 · Total $52.000) y el código de 6 dígitos en grande.
 */
trait CorreoDeMarca
{
    /** @param  array<string, string>  $filas  etiqueta → valor, en el orden en que se muestran */
    protected function conResumen(MailMessage $mensaje, array $filas): MailMessage
    {
        $mensaje->viewData['resumen'] = array_filter($filas, fn ($valor) => $valor !== null && $valor !== '');

        return $mensaje;
    }

    protected function conCodigo(MailMessage $mensaje, string $codigo, ?string $nota = null): MailMessage
    {
        $mensaje->viewData['codigo'] = $codigo;
        $mensaje->viewData['codigoNota'] = $nota;

        return $mensaje;
    }

    /**
     * Un párrafo que va después de la tarjeta y del botón. Con ->line() quedaría antes de la tarjeta cuando el
     * correo no lleva botón (Laravel pone las líneas antes del botón hasta que se agrega uno).
     */
    protected function alFinal(MailMessage $mensaje, string $linea): MailMessage
    {
        $mensaje->viewData['alFinal'][] = $linea;

        return $mensaje;
    }

    protected function pesos(float|string|null $valor): string
    {
        return '$'.number_format((float) $valor, 0, ',', '.');
    }
}
