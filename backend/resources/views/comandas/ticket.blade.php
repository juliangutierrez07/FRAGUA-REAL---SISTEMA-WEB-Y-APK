{{-- Fase 6, tarea 2 (RN-PED-06/09, D5): comanda de cocina, ticket de 80 mm que el Mesero imprime
     desde el navegador. $c es la copia guardada en comandas.contenido; no lleva precios.
     Diseño de tickets (29/10/2026): pensado para leerse de lejos en la cocina. El número del pedido en una caja
     negra, la mesa o el cliente en grande, la cantidad en un recuadro, las notas enmarcadas y los cambios con su
     etiqueta (AGREGAR, QUITAR, CAMBIAR). El alto del papel lo mide App\Support\Documentos\AltoTicket. --}}
@php
    $hora = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->setTimezone('America/Bogota')->format('g:i a') : '';
    $dia = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->setTimezone('America/Bogota')->format('d/m/Y') : '';
    $completa = fn ($v) => $v ? \Illuminate\Support\Carbon::parse($v)->setTimezone('America/Bogota')->format('d/m/Y g:i a') : '';
    $titulos = ['inicial' => 'COMANDA', 'actualizacion' => 'CAMBIOS AL PEDIDO', 'cancelacion' => 'PEDIDO CANCELADO'];
    $acciones = ['agregar' => 'AGREGAR', 'quitar' => 'QUITAR', 'cambiar' => 'CAMBIAR'];
    $reimpresion = $reimpresion ?? null;
    // M12 (24/10/2026): formato de Impresoras.
    $f = $f ?? \App\Services\ImpresionService::FORMATO;
    $t = $f['comanda_letra_grande'] ? 12.5 : 10;
    $copias = max(1, (int) $f['copias_comanda']);
    $titulo = $titulos[$comanda->tipo] ?? 'COMANDA';
    $generada = $c['generada_at'] ?? $comanda->created_at;
    $lugar = ! empty($c['mesa'])
        ? $c['mesa'].(! empty($c['cuenta']) ? ' · Cuenta '.$c['cuenta'] : '')
        : ($c['cliente'] ?? '');
    $productos = collect($c['lineas'] ?? [])->sum('cantidad');
@endphp
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Comanda {{ $c['pedido'] }}-{{ $comanda->numero }}</title>
<style>
    @page { margin: 10px 9px; }
    body { font-family: 'DejaVu Sans Mono', monospace; font-size: {{ $t }}px; color: #000; line-height: 1.35; }
    .centro { text-align: center; }
    .derecha { text-align: right; }
    .suave { color: #444; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; padding: 0; }
    hr { border: 0; border-top: 1px dashed #000; margin: {{ round($t * 0.6, 1) }}px 0; }
    hr.doble { border-top: 3px double #000; }
    .titulo { font-family: 'DejaVu Sans', sans-serif; font-weight: bold; line-height: 1.05; letter-spacing: 0.5px; font-size: {{ round($t * (mb_strlen($titulo) > 10 ? 1.75 : 2.6), 1) }}px; }
    .aviso { border: 1.5px solid #000; padding: 2px 4px; font-weight: bold; text-align: center; margin-top: 4px; }
    .numero { background: #000; color: #fff; text-align: center; width: 42%; padding: 4px 4px 5px; vertical-align: middle; }
    .numero small { display: block; font-size: {{ round($t * 0.75, 1) }}px; letter-spacing: 2px; }
    .numero b { display: block; font-family: 'DejaVu Sans', sans-serif; font-size: {{ round($t * 2.4, 1) }}px; line-height: 1; }
    .donde { padding-left: 8px; vertical-align: middle; }
    .canal { border: 1.5px solid #000; font-weight: bold; letter-spacing: 1px; padding: 0 4px; text-transform: uppercase; font-size: {{ round($t * 0.9, 1) }}px; }
    .lugar { font-family: 'DejaVu Sans', sans-serif; font-weight: bold; font-size: {{ round($t * 1.55, 1) }}px; line-height: 1.1; margin-top: 3px; }
    .hora { font-weight: bold; font-size: {{ round($t * 1.3, 1) }}px; }
    .item { margin-top: {{ round($t * 0.7, 1) }}px; }
    .cant { width: {{ round($t * 3) }}px; }
    .cant span { border: 2px solid #000; font-weight: bold; font-size: {{ round($t * 1.4, 1) }}px; padding: 0 5px; }
    .nombre { font-weight: bold; font-size: {{ round($t * 1.3, 1) }}px; text-transform: uppercase; line-height: 1.2; }
    .preparado .nombre, .quitar .nombre { text-decoration: line-through; }
    .detalle { font-size: {{ $t }}px; margin-top: 1px; }
    .accion { background: #000; color: #fff; font-weight: bold; letter-spacing: 1px; padding: 0 4px; font-size: {{ round($t * 0.85, 1) }}px; }
    .quitar .accion { background: #fff; color: #000; border: 1.5px solid #000; }
    .nota { border: 2px solid #000; padding: 2px 5px; margin-top: 3px; font-weight: bold; }
    .nota span { background: #000; color: #fff; padding: 0 3px; letter-spacing: 1px; }
    .total td { font-weight: bold; letter-spacing: 1px; }
</style>
</head>
<body>
@for ($copia = 1; $copia <= $copias; $copia++)
<div @if ($copia < $copias) style="page-break-after: always;" @endif>
@if ($copias > 1)<div class="centro suave">Copia {{ $copia }} de {{ $copias }}</div>@endif
<div class="centro titulo">{{ $titulo }}</div>
@if ($comanda->tipo === 'actualizacion')
    <div class="centro suave">Hoja {{ $comanda->numero }} del pedido: solo lo que cambió</div>
@endif
@if ($reimpresion)
    <div class="aviso">*** REIMPRESIÓN · COPIA {{ $reimpresion['vez'] }} ***</div>
    <div class="centro suave">Impresa el {{ $completa($reimpresion['fecha']) }}</div>
@endif
<hr>
<table>
    <tr>
        <td class="numero"><small>PEDIDO</small><b>#{{ $c['pedido'] }}</b></td>
        <td class="donde">
            <span class="canal">{{ ! empty($c['mesa']) ? 'Mesa' : $c['canal'] }}</span>
            @if ($lugar !== '')<div class="lugar">{{ $lugar }}</div>@endif
        </td>
    </tr>
</table>
@if (($f['comanda_mostrar_mesero'] && ! empty($c['atiende'])) || $f['comanda_mostrar_hora'])
    <table style="margin-top: 5px;">
        <tr>
            <td>@if ($f['comanda_mostrar_mesero'] && ! empty($c['atiende']))Atiende: {{ $c['atiende'] }}@endif</td>
            <td class="derecha hora">@if ($f['comanda_mostrar_hora']){{ $hora($generada) }}@endif</td>
        </tr>
    </table>
@endif
<hr class="doble">
@foreach ($c['lineas'] ?? [] as $l)
    @php
        $accion = $l['accion'] ?? null;
        $clase = $accion === 'quitar' ? 'quitar' : (($comanda->tipo === 'inicial' && ! empty($l['preparado'])) ? 'preparado' : '');
    @endphp
    <table class="item {{ $clase }}">
        <tr>
            <td class="cant"><span>{{ $l['cantidad'] }}</span></td>
            <td>
                @if ($accion)<span class="accion">{{ $acciones[$accion] ?? strtoupper($accion) }}</span><br>@endif
                <div class="nombre">{{ $l['nombre'] }}</div>
                @foreach ($l['componentes'] ?? [] as $comp)<div class="detalle">· {{ $comp }}</div>@endforeach
                @if (! empty($l['extras']))<div class="detalle">+ {{ implode(', ', $l['extras']) }}</div>@endif
                @if ($accion === 'cambiar' && ! empty($l['cantidad_antes']) && $l['cantidad_antes'] !== $l['cantidad'])
                    <div class="detalle">Antes: {{ $l['cantidad_antes'] }}</div>
                @endif
                @if (! empty($l['observaciones']))<div class="nota"><span>NOTA</span> {{ $l['observaciones'] }}</div>@endif
            </td>
        </tr>
    </table>
@endforeach
@if (! empty($c['motivo']))
    <hr>
    <div class="nota"><span>MOTIVO</span> {{ $c['motivo'] }}</div>
@endif
@if ($comanda->tipo === 'inicial')
    <hr>
    <table class="total"><tr><td>PRODUCTOS</td><td class="derecha">{{ $productos }}</td></tr></table>
@endif
<hr>
<div class="centro suave">Comanda {{ $c['pedido'] }}-{{ $comanda->numero }} · {{ $dia($generada) }}</div>
@if ($copia === 1)<div id="fin-ticket"></div>@endif
</div>
@endfor
</body>
</html>
