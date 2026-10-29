@props(['url'])
{{-- Mejora (28/10/2026): franja oscura con el monograma «FR» y el nombre, como el encabezado del sitio. --}}
<tr>
<td class="header">
<table class="header-inner" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="header-cell" align="center">
<a href="{{ $url }}" target="_blank" rel="noopener">
<table align="center" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td><span class="monograma">FR</span></td>
<td class="marca-texto"><span class="marca-nombre">{{ trim(preg_replace('/\s*burger\s*$/i', '', (string) $slot)) ?: 'Fragua Real' }}</span><br><span class="marca-sub">BURGER</span></td>
</tr>
</table>
</a>
</td>
</tr>
</table>
</td>
</tr>
