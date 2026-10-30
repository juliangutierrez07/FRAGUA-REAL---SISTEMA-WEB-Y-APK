{{-- Mejora (28/10/2026): encabezado con la marca que lleva al sitio (no a la API) y pie con la dirección y el
     WhatsApp del negocio, en español. --}}
@php($negocio = app(\App\Services\NegocioService::class)->contacto())
<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="rtrim((string) config('fragua_real.frontend_url'), '/')">
{{ $negocio['nombre'] }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer>
@if ($negocio['eslogan'])*{{ $negocio['eslogan'] }}*<br>@endif
**{{ $negocio['nombre'] }}**@if ($negocio['direccion']) · {{ $negocio['direccion'] }}@endif<br>
@if ($negocio['whatsapp_url'])
¿Dudas? Escríbenos por [WhatsApp al {{ $negocio['telefono'] }}]({{ $negocio['whatsapp_url'] }}) o visita [nuestro sitio]({{ rtrim((string) config('fragua_real.frontend_url'), '/') }}).
@else
Visita [nuestro sitio]({{ rtrim((string) config('fragua_real.frontend_url'), '/') }}).
@endif
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
