{{-- Fase 5, tarea 1 (RF-M06-010): comprobante digital (PDF) de domicilio y recogida, el que se descarga y llega
     por correo. Diseño de tickets (29/10/2026): el mismo diseño del ticket impreso (comprobantes._recibo), en una
     tira de 100 mm que se lee bien en el celular, con el logo del negocio de marca de agua (en pantalla no ensucia
     como en el papel térmico). El alto lo mide App\Support\Documentos\AltoTicket. --}}
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Comprobante {{ $c['numero'] }} · {{ $c['negocio']['nombre'] ?? 'Fragua Real Burger' }}</title>
<style>
    @page { margin: 22px 20px; }
    .marca-agua { position: fixed; top: 32%; left: 15%; width: 70%; opacity: 0.07; }
    .sello { position: fixed; top: 40%; left: 0; right: 0; text-align: center; font-family: 'DejaVu Sans', sans-serif; font-size: 44px; font-weight: bold; color: #c0392b; opacity: 0.2; }
</style>
@include('comprobantes._estilos', ['t' => 9.6])
</head>
<body>
@if (! empty($logo))<img src="{{ $logo }}" alt="" class="marca-agua">@endif
@if ($comprobante->anulado_at)<div class="sello">ANULADO</div>@endif
@include('comprobantes._recibo', ['logo' => $logo ?? null, 'pie' => null, 'reimpresion' => $reimpresion ?? null])
<div id="fin-ticket"></div>
</body>
</html>
