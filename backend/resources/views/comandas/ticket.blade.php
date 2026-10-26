{{-- Fase 6, tarea 2 (RN-PED-06/09, D5): comanda de cocina, ticket de 80 mm que el Mesero imprime
     desde el navegador. $c es la copia guardada en comandas.contenido; no lleva precios. --}}
@php
    $hora = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->setTimezone('America/Bogota')->format('d/m/Y g:i a') : '';
    $titulos = ['inicial' => 'COMANDA', 'actualizacion' => 'CAMBIOS AL PEDIDO', 'cancelacion' => 'PEDIDO CANCELADO'];
    $acciones = ['agregar' => 'AGREGAR', 'quitar' => 'QUITAR', 'cambiar' => 'CAMBIAR'];
    $reimpresion = $reimpresion ?? null;
    // M12 (24/10/2026): formato de Impresoras.
    $f = $f ?? \App\Services\ImpresionService::FORMATO;
    $g = (bool) $f['comanda_letra_grande'];
    $copias = max(1, (int) $f['copias_comanda']);
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Comanda {{ $c['pedido'] }}-{{ $comanda->numero }}</title>
<style>
    @page { margin: 10px 9px; }
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: {{ $g ? '12.5px' : '10px' }}; color: #000; line-height: 1.35; }
    .centro { text-align: center; }
    .grande { font-size: {{ $g ? '16px' : '13px' }}; font-weight: bold; }
    .linea { font-size: {{ $g ? '14.5px' : '11.5px' }}; font-weight: bold; margin-top: 3px; }
    .detalle { padding-left: 14px; }
    hr { border: 0; border-top: 1px dashed #000; margin: 5px 0; }
    .preparado { text-decoration: line-through; }
</style>
</head>
<body>
@for ($copia = 1; $copia <= $copias; $copia++)
<div @if ($copia < $copias) style="page-break-after: always;" @endif>
@if ($copias > 1)<div class="centro">Copia {{ $copia }} de {{ $copias }}</div>@endif
<div class="centro grande">{{ $titulos[$comanda->tipo] ?? 'COMANDA' }}</div>
@if ($comanda->tipo === 'actualizacion')
    <div class="centro">Hoja {{ $comanda->numero }} del pedido: solo lo que cambió</div>
@endif
@if ($reimpresion)
    <div class="centro"><strong>*** REIMPRESIÓN · COPIA {{ $reimpresion['vez'] }} ***</strong></div>
    <div class="centro">Impresa el {{ $hora($reimpresion['fecha']) }}</div>
@endif
<hr>
<div class="grande">Pedido #{{ $c['pedido'] }} · {{ $c['canal'] }}</div>
@if (! empty($c['mesa']))<div class="grande">{{ $c['mesa'] }}@if (! empty($c['cuenta'])) · Cuenta {{ $c['cuenta'] }}@endif</div>@endif
@if (! empty($c['cliente']))<div>Cliente: {{ $c['cliente'] }}</div>@endif
@if ($f['comanda_mostrar_hora'])<div>{{ $hora($c['generada_at'] ?? $comanda->created_at) }}</div>@endif
@if ($f['comanda_mostrar_mesero'] && ! empty($c['atiende']))<div>Atiende: {{ $c['atiende'] }}</div>@endif
<hr>
@foreach ($c['lineas'] ?? [] as $l)
    @php
        $texto = (! empty($l['accion']) ? '['.($acciones[$l['accion']] ?? strtoupper($l['accion'])).'] ' : '').$l['cantidad'].' × '.$l['nombre'];
        if (($l['accion'] ?? null) === 'cambiar' && ! empty($l['cantidad_antes']) && $l['cantidad_antes'] !== $l['cantidad']) {
            $texto .= ' (antes '.$l['cantidad_antes'].')';
        }
    @endphp
    <div class="linea{{ ($comanda->tipo === 'inicial' && ! empty($l['preparado'])) ? ' preparado' : '' }}">{{ $texto }}</div>
    @foreach ($l['componentes'] ?? [] as $comp)<div class="detalle">- {{ $comp }}</div>@endforeach
    @if (! empty($l['extras']))<div class="detalle">+ {{ implode(', ', $l['extras']) }}</div>@endif
    @if (! empty($l['observaciones']))<div class="detalle">Nota: {{ $l['observaciones'] }}</div>@endif
@endforeach
@if (! empty($c['motivo']))
    <hr>
    <div>Motivo: {{ $c['motivo'] }}</div>
@endif
<hr>
<div class="centro">Comanda {{ $c['pedido'] }}-{{ $comanda->numero }}</div>
</div>
@endfor
</body>
</html>
