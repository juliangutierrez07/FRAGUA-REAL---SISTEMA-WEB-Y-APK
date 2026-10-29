@props(['codigo', 'nota' => null])
{{-- Mejora (28/10/2026): el código de 6 dígitos, grande y fácil de copiar. --}}
<table class="codigo" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr><td class="codigo-cell">
<span class="codigo-numero">{{ $codigo }}</span><br>
@if ($nota)
<span class="codigo-nota">{{ $nota }}</span>
@endif
</td></tr>
</table>
