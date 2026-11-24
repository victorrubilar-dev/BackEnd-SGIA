{{-- Estado legible con su tono (App\Support\PdfLabels). Uso: @include('pdf.partials.status', ['value' => $x]) --}}
<span class="badge tone-{{ $tone ?? \App\Support\PdfLabels::tone($value) }}">{{ $label ?? \App\Support\PdfLabels::label($value) }}</span>
