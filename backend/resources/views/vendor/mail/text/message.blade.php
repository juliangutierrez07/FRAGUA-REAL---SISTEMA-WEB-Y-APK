{{-- Mejora (28/10/2026): la versión en texto plano del correo, con el nombre, la dirección y el WhatsApp del negocio. --}}
@php($negocio = app(\App\Services\NegocioService::class)->contacto())
<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="rtrim((string) config('fragua_real.frontend_url'), '/')">
            {{ $negocio['nombre'] }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            {{ $negocio['nombre'] }}@if ($negocio['direccion']) · {{ $negocio['direccion'] }}@endif @if ($negocio['telefono']) · WhatsApp {{ $negocio['telefono'] }}@endif
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
