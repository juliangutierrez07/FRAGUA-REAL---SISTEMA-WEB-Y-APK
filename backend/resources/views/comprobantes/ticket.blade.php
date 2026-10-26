{{-- Fase 5, tarea 1 (RF-M06-009): comprobante impreso de un pedido local, ticket de 80 mm que se
     imprime desde el navegador (la impresora POS directa llega en la Fase 6). --}}
@php
    // Pesos y hora de Colombia; $c es la copia guardada en comprobantes.detalle.
    $pesos = fn ($v) => '$'.number_format((float) $v, 0, ',', '.');
    $fecha = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->setTimezone('America/Bogota')->format('d/m/Y g:i a') : '';
    $n = $c['negocio'] ?? [];
    $p = $c['pedido'] ?? [];
    $anulado = $comprobante->anulado_at !== null;
    // Reimpresión del personal (desde la segunda vez): ['vez' => n, 'fecha' => ...] o null.
    $reimpresion = $reimpresion ?? null;
    // M12 (24/10/2026): formato de Impresoras (logo, letra, mensaje al pie y copias).
    $f = $f ?? \App\Services\ImpresionService::FORMATO;
    $g = $f['ticket_letra'] === 'grande';
    $copias = max(1, (int) $f['copias_comprobante']);
    $logo = $logo ?? null;
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Comprobante {{ $c['numero'] }}</title>
<style>
    @page { margin: 10px 9px; }
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: {{ $g ? '10.2px' : '8.6px' }}; color: #000; line-height: 1.32; }
    .centro { text-align: center; }
    .derecha { text-align: right; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; padding: 0; }
    hr { border: 0; border-top: 1px dashed #000; margin: 5px 0; }
    .grande { font-size: {{ $g ? '13px' : '11px' }}; font-weight: bold; }
</style>
</head>
<body>
@for ($copia = 1; $copia <= $copias; $copia++)
<div @if ($copia < $copias) style="page-break-after: always;" @endif>
@if ($copias > 1)<div class="centro">Copia {{ $copia }} de {{ $copias }}</div>@endif
<div class="centro">
    @if ($logo)<img src="{{ $logo }}" alt="" style="max-height: 48px; max-width: 120px; margin-bottom: 3px;"><br>@endif
    <div class="grande">{{ $n['nombre'] ?? 'Fragua Real Burger' }}</div>
    @if (! empty($n['nit']))<div>NIT {{ $n['nit'] }}</div>@endif
    <div>{{ $n['direccion'] ?? '' }}</div>
    @if (! empty($n['telefono']))<div>Tel. {{ $n['telefono'] }}</div>@endif
</div>
<hr>
<div class="centro"><strong>COMPROBANTE DE PAGO {{ $c['numero'] }}</strong></div>
@if ($anulado)<div class="centro grande">*** ANULADO ***</div>@endif
@if ($reimpresion)
    <div class="centro"><strong>*** {{ ! empty($reimpresion['consulta']) ? 'COPIA DE CONSULTA · SOPORTE' : 'REIMPRESIÓN · COPIA '.$reimpresion['vez'] }} ***</strong></div>
    <div class="centro">{{ ! empty($reimpresion['consulta']) ? 'Consultada' : 'Impresa' }} el {{ $fecha($reimpresion['fecha']) }}</div>
@endif
<div>{{ $fecha($c['fecha']) }}</div>
<div>Pedido #{{ $p['id'] }} · {{ $p['canal'] }}@if (! empty($p['mesa'])) · {{ $p['mesa'] }}@endif</div>
@if (! empty($p['cliente']))<div>Cliente: {{ $p['cliente'] }}</div>@endif
<hr>
<table>
@foreach ($c['lineas'] as $l)
    <tr><td>{{ $l['cantidad'] }} {{ $l['nombre'] }}</td><td class="derecha" style="width: 32%;">{{ $pesos($l['valor']) }}</td></tr>
    @if (! empty($l['extras']))<tr><td colspan="2">  + {{ implode(', ', $l['extras']) }}</td></tr>@endif
@endforeach
</table>
<hr>
<table>
    <tr><td>Subtotal</td><td class="derecha">{{ $pesos($c['subtotal']) }}</td></tr>
    @foreach ($c['descuentos'] as $d)
        <tr><td>{{ $d['texto'] }}</td><td class="derecha">-{{ $pesos($d['valor']) }}</td></tr>
    @endforeach
    @if ($c['valor_domicilio'] > 0)<tr><td>Domicilio</td><td class="derecha">{{ $pesos($c['valor_domicilio']) }}</td></tr>@endif
    <tr class="grande"><td>TOTAL</td><td class="derecha">{{ $pesos($c['total']) }}</td></tr>
</table>
<hr>
<table>
@foreach ($c['pagos'] as $pg)
    <tr><td>{{ $pg['texto'] }}</td><td class="derecha">{{ $pesos($pg['valor']) }}</td></tr>
@endforeach
</table>
<hr>
@if ($anulado)<div>Anulado: {{ $comprobante->anulado_motivo }}</div>@endif
@if (filled($f['ticket_mensaje_pie']))<div class="centro">{{ $f['ticket_mensaje_pie'] }}</div>@endif
<div class="centro">No es factura electrónica.</div>
</div>
@endfor
</body>
</html>
