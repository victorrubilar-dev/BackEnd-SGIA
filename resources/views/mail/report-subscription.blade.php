@php($plain = fn ($text) => \App\Mail\ReportSubscriptionMail::plain((string) $text))
<x-mail::layout>
<x-slot:header>
<x-mail::header :url="config('sgia.frontend_url')">
SGIA
</x-mail::header>
</x-slot:header>

# {{ $plain($title) }}

Hola {{ $plain($recipient) }}, este es tu reporte {{ $frequencyText }}.

**Período:** {{ $periodText }}

@if ($filters !== [])
**Filtros**

@foreach ($filters as $label => $value)
- {{ $plain($label) }}: {{ $plain($value) }}
@endforeach
@endif

@if ($empty)
**Sin movimientos en el período.** No se adjunta ningún archivo.
@else
<x-mail::table>
| Hoja | Filas |
| :--- | ----: |
@foreach ($sheets as $sheet)
| {{ $plain($sheet['name']) }} | {{ number_format($sheet['rows'], 0, ',', '.') }}{{ $sheet['truncated'] ? '+' : '' }} |
@endforeach
</x-mail::table>

@if ($delivery === 'attachment')
El Excel va adjunto a este correo.
@elseif ($personal)
Este reporte tiene datos personales, así que no se adjunta: descárgalo en SGIA con tu sesión iniciada.
@else
El archivo es demasiado grande para adjuntarlo: descárgalo en SGIA.
@endif
@endif

<x-mail::button :url="$url">
Abrir en SGIA
</x-mail::button>

Para pausar o quitar este envío, entra a «Mis suscripciones» en la página de reportes.

<x-slot:footer>
<x-mail::footer>
© {{ date('Y') }} SGIA · INACAP Sede Temuco
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
