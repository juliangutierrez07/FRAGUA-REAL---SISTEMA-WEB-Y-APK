{{-- M12 — Impresoras (24/10/2026): ticket de prueba de 80 mm. Sirve para ver que la impresora
     corta en su sitio, que el ancho es el correcto (la regla debe salir completa), que las tildes y
     la ñ se ven, que el negro es parejo y que el logo sale. No queda en ningún pedido. --}}
@php
    $fecha = \Illuminate\Support\Carbon::parse($fecha)->setTimezone('America/Bogota');
    $grande = $grande ?? false;
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Impresión de prueba</title>
<style>
    @page { margin: 10px 9px; }
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: {{ $grande ? '10.2px' : '8.6px' }}; color: #000; line-height: 1.35; }
    .centro { text-align: center; }
    .grande { font-size: {{ $grande ? '14px' : '12px' }}; font-weight: bold; }
    hr { border: 0; border-top: 1px dashed #000; margin: 5px 0; }
    table { width: 100%; border-collapse: collapse; }
    .regla td { border-left: 1px solid #000; height: 8px; font-size: 6px; vertical-align: bottom; }
    .regla td:last-child { border-right: 1px solid #000; }
    .bloques td { height: 10px; }
</style>
</head>
<body>
<div class="centro">
    @if (! empty($logo))<img src="{{ $logo }}" alt="" style="max-height: 48px; max-width: 120px; margin-bottom: 3px;"><br>@endif
    <div class="grande">IMPRESIÓN DE PRUEBA</div>
    <div>{{ $negocio['nombre'] ?? 'Fragua Real Burger' }}</div>
</div>
<hr>
<div>Equipo: {{ $equipo ?? 'sin registrar' }}</div>
<div>Pidió: {{ $usuario }}</div>
<div>{{ $fecha->format('d/m/Y g:i:s a') }}</div>
<hr>
<div>Tildes y eñes: áéíóú ÁÉÍÓÚ ñÑ ü ¿? ¡!</div>
<div>Pesos: $1.234.567 · 50 % · #123</div>
<div>Ancho de 80 mm (la regla debe salir completa):</div>
<table class="regla"><tr>@for ($i = 0; $i < 10; $i++)<td>{{ $i }}</td>@endfor</tr></table>
<div>Negro parejo (sin rayas ni partes claras):</div>
<table class="bloques"><tr>@for ($i = 0; $i < 10; $i++)<td style="background: {{ $i % 2 ? '#fff' : '#000' }};"></td>@endfor</tr></table>
<table class="bloques"><tr>@for ($i = 0; $i < 10; $i++)<td style="background: {{ $i % 2 ? '#000' : '#fff' }};"></td>@endfor</tr></table>
<hr>
<div class="centro">Si todo se ve bien, en el sistema</div>
<div class="centro">responde «Salió bien».</div>
<div class="centro">--- fin de la prueba ---</div>
</body>
</html>
