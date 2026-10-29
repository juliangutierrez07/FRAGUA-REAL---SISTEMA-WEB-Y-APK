@props(['filas' => []])
{{-- Mejora (28/10/2026): «Pedido #12 · Total $52.000» en una tarjeta crema (estilos en themes/fragua.css). --}}
<table class="resumen" width="100%" cellpadding="0" cellspacing="0" role="presentation">
@foreach ($filas as $etiqueta => $valor)
<tr><td class="resumen-etiqueta">{{ $etiqueta }}</td><td class="resumen-valor">{{ $valor }}</td></tr>
@endforeach
</table>
