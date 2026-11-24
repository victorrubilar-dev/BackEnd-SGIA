{{-- Código en Geist Mono sobre etiqueta crema; «—» si no hay. Uso: @include('pdf.partials.code', ['code' => $x]) --}}
@if (filled($code ?? null))<span class="code">{{ $code }}</span>@else<span class="muted">—</span>@endif
