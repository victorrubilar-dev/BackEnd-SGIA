@extends('pdf.layout')

@section('title', 'Informes de novedades')
@section('subtitle')
    {{ $product->name }}@if (! empty($unit)) · Unidad {{ $unit->code }}@endif
@endsection
@section('meta')
    @include('pdf.partials.code', ['code' => ! empty($unit) ? $unit->code : $product->barcode])
    <div style="margin-top: 3pt">{{ $reports->count() }} {{ $reports->count() === 1 ? 'informe' : 'informes' }}</div>
@endsection
@section('foot', 'Hoja de vida del equipo #'.$product->id)

@section('content')
    <h2>Hoja de vida del equipo</h2>
    @if ($reports->isEmpty())
        <p class="empty">Este equipo no tiene informes de novedades registrados.</p>
    @else
        <table class="list">
            <thead>
                <tr><th style="width: 18%">Código</th><th style="width: 12%">Fecha</th><th style="width: 15%">Reportado por</th><th>Título y descripción</th><th style="width: 9%">Severidad</th><th style="width: 12%">Estado</th></tr>
            </thead>
            <tbody>
                @foreach ($reports as $report)
                    <tr>
                        <td>@include('pdf.partials.code', ['code' => $report->code])</td>
                        <td class="nowrap">{{ $report->created_at?->format('d-m-Y') }}<span class="sub">{{ $report->created_at?->format('H:i') }}</span></td>
                        <td>{{ $report->reporter?->name ?? '—' }}</td>
                        <td>
                            @if ($report->title)<strong>{{ $report->title }}</strong>@endif
                            <span class="sub">{{ \Illuminate\Support\Str::limit($report->description, 220) }}</span>
                            @if ($report->attachment_original_name)<span class="sub">Adjunto: {{ $report->attachment_original_name }}</span>@endif
                        </td>
                        <td>@include('pdf.partials.status', ['value' => $report->severity, 'label' => $report->severityLabel()])</td>
                        <td>@include('pdf.partials.status', ['value' => $report->status])</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
