@extends('pdf.layout')

@section('title', 'Informe de novedades')
@section('subtitle')
    {{ $report->subject ?? 'Sin asignatura' }}@if ($report->location) · {{ $report->location->nombre ?? $report->location->sala }}@endif
@endsection
@section('meta')
    @include('pdf.partials.code', ['code' => $report->code])
    <div style="margin-top: 4pt">@include('pdf.partials.status', ['value' => $report->status])</div>
@endsection

@section('styles')
    .check { width: 100%; border-collapse: collapse; }
    .check td { padding: 3.5pt 0; vertical-align: top; border-bottom: 1px solid #EFEDE8; }
    .check td.box { width: 16pt; }
    .box span { display: inline-block; width: 9pt; height: 9pt; border: 1px solid #CBC7BE; border-radius: 2pt; text-align: center; font-size: 7pt; line-height: 9pt; color: #FFFFFF; }
    .box span.on { background: #6B1729; border-color: #6B1729; }
    .check .desc { display: block; margin-top: 1pt; color: #5F5B53; font-size: 8pt; }
    .photo img { max-width: 300pt; max-height: 210pt; border: 1px solid #E2DFD8; border-radius: 4pt; }
    .signature img { max-height: 70pt; max-width: 220pt; }
    .sign-line { width: 220pt; border-top: 1px solid #1B1A17; padding-top: 3pt; }
@endsection

@section('content')
    <h2>Datos generales</h2>
    <table class="kv">
        <tr>
            <td class="k">Reportado por</td><td class="v">{{ $report->teacher_name ?? $report->reporter?->name ?? '—' }}</td>
            <td class="k">Fecha</td><td class="v">{{ $report->report_date?->format('d-m-Y') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Asignatura o actividad</td><td class="v">{{ $report->subject ?? '—' }}</td>
            <td class="k">Laboratorio o pañol</td><td class="v">{{ $report->location?->nombre ?? $report->location?->sala ?? '—' }}</td>
        </tr>
        @if ($report->product)
            <tr>
                <td class="k">Equipo</td><td class="v">{{ $report->product->name }}</td>
                <td class="k">{{ $report->equipmentUnit ? 'Unidad' : 'Código' }}</td>
                <td class="v">@include('pdf.partials.code', ['code' => $report->equipmentUnit?->code ?? $report->product->barcode])</td>
            </tr>
        @endif
    </table>

    <h2>Problema</h2>
    @php($marked = collect($report->problems ?? [])->keyBy('type'))
    <table class="check">
        @foreach (\App\Models\IncidentReport::PROBLEM_LABELS as $type => $label)
            <tr>
                <td class="box"><span class="{{ $marked->has($type) ? 'on' : '' }}">{{ $marked->has($type) ? '✓' : '' }}</span></td>
                <td>
                    <span class="{{ $marked->has($type) ? '' : 'muted' }}">{{ $label }}</span>
                    @if ($marked->has($type) && filled($marked[$type]['description'] ?? null))
                        <span class="desc">{{ $marked[$type]['description'] }}</span>
                    @endif
                </td>
            </tr>
        @endforeach
    </table>

    <h2>Descripción o sugerencia</h2>
    <p class="{{ $report->suggestion ? '' : 'muted' }}">{{ $report->suggestion ?: 'Sin descripción.' }}</p>

    @if ($photo)
        <h2>Foto</h2>
        <div class="photo"><img src="{{ $photo }}" alt="Foto del informe"></div>
    @endif

    <h2>Firma</h2>
    <div class="signature">
        @if ($signature)
            <img src="{{ $signature }}" alt="Firma">
        @else
            <p class="muted">{{ $report->signature_path ? 'Firma registrada (no se puede incrustar: falta la extensión GD en el servidor).' : 'Sin firma.' }}</p>
        @endif
        <div class="sign-line">
            {{ $report->teacher_name ?? $report->reporter?->name }}
            @if ($report->signed_at)<span class="muted small"> · firmado el {{ $report->signed_at->format('d-m-Y H:i') }}</span>@endif
        </div>
    </div>
@endsection
