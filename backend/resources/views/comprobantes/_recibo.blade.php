{{-- Diseño de tickets (29/10/2026): el comprobante con la marca, basado en el diseño que compartió el negocio
     (ticket-pos-fragua-real.html). Lo usan el ticket de 80 mm (comprobantes.ticket) y el PDF digital que se
     descarga o llega por correo (comprobantes.pdf). Solo tablas y bordes: dompdf no entiende flex ni grid.
     Variables: $c (copia guardada), $comprobante, $reimpresion, $logo, $pie (mensaje al pie de Impresoras). --}}
@php
    $pesos = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
    $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->setTimezone('America/Bogota')->format('d/m/Y g:i a') : '';
    $n = $c['negocio'] ?? [];
    $p = $c['pedido'] ?? [];
    $anulado = $comprobante->anulado_at !== null;
    $reimpresion = $reimpresion ?? null;
    $nombre = $n['nombre'] ?? 'Fragua Real Burger';
    // «Fragua Real Burger» se escribe en dos pisos: FRAGUA REAL grande y BURGER espaciado debajo.
    $marca = preg_match('/^(.*\S)\s+(burger)$/iu', $nombre, $m) ? [$m[1], $m[2]] : [$nombre, null];
    $articulos = collect($c['lineas'] ?? [])->sum('cantidad');
@endphp
@if ($logo)
    <div class="centro"><img src="{{ $logo }}" alt="" class="logo"></div>
@endif
<div class="centro">
    <div class="marca">{{ $marca[0] }}</div>
    @if ($marca[1])<div class="marca-sub">{{ $marca[1] }}</div>@endif
    {{-- 29/10/2026: el eslogan oficial del negocio. --}}
    @if (filled(config('fragua_real.negocio.eslogan')))<div class="eslogan">{{ config('fragua_real.negocio.eslogan') }}</div>@endif
    @if (! empty($n['nit']))<div class="nit">NIT {{ $n['nit'] }}</div>@endif
    {{-- 29/10/2026: el municipio debajo de la dirección, como lo pidió el negocio. --}}
    <div class="suave">{{ $n['direccion'] ?? '' }}@if (filled(config('fragua_real.negocio.ciudad')))<br>{{ config('fragua_real.negocio.ciudad') }}@endif @if (! empty($n['telefono']))<br>Tel. / WhatsApp {{ $n['telefono'] }}@endif</div>
</div>

<hr>
<div class="centro">
    <div class="etiqueta">COMPROBANTE DE PAGO</div>
    <div class="numero"><span>{{ $c['numero'] }}</span></div>
    <div>{{ $fecha($c['fecha']) }}</div>
    @if ($anulado)<div class="aviso">*** ANULADO ***</div>@endif
    @if ($reimpresion)
        <div class="aviso-borde">*** {{ ! empty($reimpresion['consulta']) ? 'COPIA DE CONSULTA · SOPORTE' : 'REIMPRESIÓN · COPIA '.$reimpresion['vez'] }} ***</div>
        <div class="suave">{{ ! empty($reimpresion['consulta']) ? 'Consultada' : 'Impresa' }} el {{ $fecha($reimpresion['fecha']) }}</div>
    @endif
</div>
<hr>

<table class="datos">
    <tr><td class="suave">Pedido</td><td class="derecha"><strong>#{{ $p['id'] }}</strong> <span class="canal">{{ $p['canal'] }}</span></td></tr>
    @if (! empty($p['mesa']))<tr><td class="suave">Mesa</td><td class="derecha">{{ $p['mesa'] }}</td></tr>@endif
    @if (! empty($p['creado']))<tr><td class="suave">Hecho</td><td class="derecha">{{ $fecha($p['creado']) }}</td></tr>@endif
    @if (! empty($p['cliente']))<tr><td class="suave">Cliente</td><td class="derecha">{{ $p['cliente'] }}</td></tr>@endif
    @if (! empty($p['telefono']))<tr><td class="suave">Teléfono</td><td class="derecha">{{ $p['telefono'] }}</td></tr>@endif
    @if (! empty($p['direccion']))<tr><td class="suave">Entrega</td><td class="derecha">{{ $p['direccion'] }}</td></tr>@endif
</table>
<hr>

<table class="productos">
    <thead><tr><th class="cant">CANT</th><th>PRODUCTO</th><th class="derecha">VALOR</th></tr></thead>
    <tbody>
    @foreach ($c['lineas'] as $l)
        <tr>
            <td class="cant">{{ $l['cantidad'] }}</td>
            <td>{{ $l['nombre'] }}@if (! empty($l['extras']))<div class="suave">+ {{ implode(', ', $l['extras']) }}</div>@endif</td>
            <td class="derecha valor">{{ $pesos($l['valor']) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<hr>

<table class="sumas">
    <tr><td>Artículos</td><td class="derecha">{{ $articulos }}</td></tr>
    <tr><td>Subtotal</td><td class="derecha">{{ $pesos($c['subtotal']) }}</td></tr>
    @foreach ($c['descuentos'] as $d)
        <tr><td>{{ $d['texto'] }}</td><td class="derecha">-{{ $pesos($d['valor']) }}</td></tr>
    @endforeach
    @if ($c['valor_domicilio'] > 0)<tr><td>Domicilio</td><td class="derecha">{{ $pesos($c['valor_domicilio']) }}</td></tr>@endif
</table>
<table class="total">
    <tr><td class="total-etiqueta">TOTAL</td><td class="derecha total-valor">{{ $pesos($c['total']) }}</td></tr>
</table>
<hr class="doble">

<div class="seccion">PAGOS</div>
<table class="pagos">
@foreach ($c['pagos'] as $pg)
    <tr><td>{{ $pg['texto'] }}</td><td class="derecha"><strong>{{ $pesos($pg['valor']) }}</strong></td></tr>
    @if (! empty($pg['fecha']))<tr><td colspan="2" class="suave pequeno">{{ $fecha($pg['fecha']) }}</td></tr>@endif
@endforeach
</table>
<hr>

@if ($anulado)
    <div class="aviso-borde">Anulado el {{ $fecha($comprobante->anulado_at) }}: {{ $comprobante->anulado_motivo }}</div>
@endif
<div class="centro">
    <div class="gracias">{{ filled($pie ?? null) ? $pie : '¡Gracias por tu compra!' }}</div>
    <div class="suave pequeno">Este comprobante de pago no es una factura electrónica.</div>
    <div class="estrellas">* * * * *</div>
</div>
