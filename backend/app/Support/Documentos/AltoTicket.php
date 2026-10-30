<?php

namespace App\Support\Documentos;

use Barryvdh\DomPDF\PDF;

/**
 * Diseño de tickets (29/10/2026): el alto del papel de un ticket (comprobante o comanda) se mide en vez de
 * calcularse por renglones. dompdf no ajusta el alto solo; antes se estimaba contando líneas, y con el diseño
 * nuevo (títulos grandes, cajas, notas) esa cuenta quedaba corta o larga. Se dibuja una vez en un papel muy alto,
 * se mira dónde quedó la marca `#fin-ticket` (al final de la primera copia) y se dibuja de nuevo con ese alto.
 */
final class AltoTicket
{
    private const ALTO_DE_PRUEBA = 4000; // puntos (~1,4 m de papel)
    private const MARGEN_FINAL = 24; // el margen de abajo de la hoja (hasta 22 px) y un respiro

    /** @param callable(float): PDF $crear arma el PDF con el alto de papel que recibe */
    public static function ajustado(callable $crear, float $minimo = 160): PDF
    {
        $fin = null;
        $dompdf = $crear(self::ALTO_DE_PRUEBA)->getDomPDF();
        // Después de dibujar, el árbol ya no deja leer posiciones: se toman mientras dompdf dibuja cada parte.
        $dompdf->setCallbacks([[
            'event' => 'end_frame',
            'f' => function ($frame) use (&$fin) {
                $nodo = $frame->get_node();
                if ($fin === null && $nodo instanceof \DOMElement && $nodo->getAttribute('id') === 'fin-ticket') {
                    $fin = (float) $frame->get_position('y') + $frame->get_margin_height();
                }
            },
        ]]);
        $dompdf->render();

        $alto = $fin === null ? self::ALTO_DE_PRUEBA : $fin + self::MARGEN_FINAL;

        return $crear(min(self::ALTO_DE_PRUEBA, max($minimo, $alto)));
    }
}
