<x-mail::message>
{{-- Mejora (28/10/2026): la plantilla de todos los correos al cliente, en español y con la marca. Además de lo de
     Laravel (saludo, líneas, botón) acepta `codigo` (el código de 6 dígitos, grande), `resumen` (filas
     etiqueta → valor en una tarjeta) y `alFinal` (párrafos después de la tarjeta y del botón), que cada aviso pone
     con App\Notifications\Concerns\CorreoDeMarca. --}}
{{-- Greeting --}}
@if (! empty($greeting))
# {{ $greeting }}
@else
# Hola,
@endif

{{-- Intro Lines --}}
@foreach ($introLines as $line)
{{ $line }}

@endforeach

{{-- Código --}}
@if (! empty($codigo))
<x-mail::codigo :codigo="$codigo" :nota="$codigoNota ?? null" />

@endif
{{-- Resumen --}}
@if (! empty($resumen))
<x-mail::resumen :filas="$resumen" />

@endif
{{-- Action Button --}}
@isset($actionText)
<?php
    $color = match ($level) {
        'success', 'error' => $level,
        default => 'primary',
    };
?>
<x-mail::button :url="$actionUrl" :color="$color">
{{ $actionText }}
</x-mail::button>
@endisset

{{-- Outro Lines --}}
@foreach ($outroLines as $line)
{{ $line }}

@endforeach
@foreach ($alFinal ?? [] as $line)
{{ $line }}

@endforeach

{{-- Salutation --}}
@if (! empty($salutation))
{{ $salutation }}
@else
Con gusto,<br>
**El equipo de {{ app(\App\Services\NegocioService::class)->contacto()['nombre'] }}**
@endif

{{-- Subcopy --}}
@isset($actionText)
<x-slot:subcopy>
¿El botón «{{ $actionText }}» no abre? Copia y pega este enlace en tu navegador:
<span class="break-all">[{{ $displayableActionUrl }}]({{ $actionUrl }})</span>
</x-slot:subcopy>
@endisset
</x-mail::message>
