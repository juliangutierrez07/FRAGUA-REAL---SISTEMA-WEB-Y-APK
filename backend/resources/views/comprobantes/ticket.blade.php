{{-- Fase 5, tarea 1 (RF-M06-009): comprobante impreso de un pedido, ticket de 80 mm que se imprime desde el
     navegador. Diseño de tickets (29/10/2026): con la marca (comprobantes._recibo); el alto del papel lo mide
     App\Support\Documentos\AltoTicket. Sin marca de agua: en papel térmico el gris sale como puntos y ensucia los
     productos. --}}
@php
    // M12 (24/10/2026): formato de Impresoras (logo, letra, mensaje al pie y copias).
    $f = $f ?? \App\Services\ImpresionService::FORMATO;
    $copias = max(1, (int) $f['copias_comprobante']);
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Comprobante {{ $c['numero'] }} · {{ $c['negocio']['nombre'] ?? 'Fragua Real Burger' }}</title>
<style>@page { margin: 10px 9px; }</style>
@include('comprobantes._estilos', ['t' => $f['ticket_letra'] === 'grande' ? 10.2 : 8.6])
</head>
<body>
@for ($copia = 1; $copia <= $copias; $copia++)
<div @if ($copia < $copias) style="page-break-after: always;" @endif>
@if ($copias > 1)<div class="centro suave">Copia {{ $copia }} de {{ $copias }}</div>@endif
@include('comprobantes._recibo', ['logo' => $logo ?? null, 'pie' => $f['ticket_mensaje_pie'], 'reimpresion' => $reimpresion ?? null])
@if ($copia === 1)<div id="fin-ticket"></div>@endif
</div>
@endfor
</body>
</html>
