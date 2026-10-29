@props(['filas' => []])
@foreach ($filas as $etiqueta => $valor)
{{ $etiqueta }}: {{ $valor }}
@endforeach
