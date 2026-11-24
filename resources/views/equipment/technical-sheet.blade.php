@extends('pdf.layout')

@section('title', 'Ficha técnica del equipo')
@section('subtitle', $product->name)
@section('meta')
    @include('pdf.partials.code', ['code' => $product->barcode])
@endsection
@section('foot', 'Equipo #'.$product->id.' · Uso interno de las carreras técnicas')

@php
    $location = $product->cajon?->location ?? $product->location;
    $place = $location
        ? ($location->sala ?? $location->nombre).(($location->descripcion ?? null) ? ' · '.$location->descripcion : '')
        : 'Sin ubicación asignada';
    $cajon = $product->cajon?->codigo ?? $product->location?->cajon;
@endphp

@section('content')
    <h2>Datos del equipo</h2>
    <table class="kv">
        <tr>
            <td class="k">Nombre</td><td class="v">{{ $product->name }}</td>
            <td class="k">Código de barras</td><td class="v">@include('pdf.partials.code', ['code' => $product->barcode])</td>
        </tr>
        <tr>
            <td class="k">Área o carrera</td><td class="v">{{ $product->area ?? '—' }}</td>
            <td class="k">Estado</td><td class="v">@include('pdf.partials.status', ['label' => $product->is_active ? 'Activo' : 'Inactivo', 'tone' => $product->is_active ? 'ok' : 'neutral'])</td>
        </tr>
        <tr>
            <td class="k">Stock actual / mínimo</td><td class="v">{{ $product->quantity }} / {{ $product->stock_minimo }}</td>
            <td class="k">Registrado</td><td class="v">{{ $product->created_at?->format('d-m-Y H:i') ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Descripción</td><td class="v" colspan="3">{{ $product->description ?? 'Sin descripción registrada.' }}</td>
        </tr>
    </table>

    <h2>Proveedor y ubicación</h2>
    <table class="kv">
        <tr>
            <td class="k">Proveedor</td><td class="v">{{ $product->supplier?->name ?? 'Sin proveedor asociado' }}</td>
            <td class="k">Sala</td><td class="v">{{ $place }}</td>
        </tr>
        <tr>
            <td class="k">Contacto</td>
            <td class="v">
                @if ($product->supplier)
                    {{ collect([$product->supplier->contact_name, $product->supplier->email, $product->supplier->phone])->filter()->implode(' · ') ?: '—' }}
                @else
                    —
                @endif
            </td>
            <td class="k">Cajón</td>
            <td class="v">
                @if ($product->cajon)
                    @include('pdf.partials.code', ['code' => $product->cajon->barcode ?? $product->cajon->codigo])
                    @if ($product->cajon->descripcion)<span class="muted"> {{ $product->cajon->descripcion }}</span>@endif
                @else
                    {{ $cajon ?? 'Sin cajón asignado' }}
                @endif
            </td>
        </tr>
        <tr>
            <td class="k">Categoría</td><td class="v">{{ $product->supplier?->category ?? '—' }}</td>
            <td class="k">Actualizado</td><td class="v">{{ $product->updated_at?->format('d-m-Y H:i') ?? '—' }}</td>
        </tr>
    </table>

    <h2>Unidades <span class="count">· {{ $unitsTotal }}</span></h2>
    @if ($units->isEmpty())
        <p class="empty">Este producto no tiene unidades registradas.</p>
    @else
        <table class="list">
            <thead>
                <tr><th style="width: 19%">Código</th><th style="width: 19%">Serie</th><th style="width: 15%">Estado</th><th>Ubicación</th><th style="width: 15%">Próx. mantención</th></tr>
            </thead>
            <tbody>
                @foreach ($units as $unit)
                    <tr>
                        <td>@include('pdf.partials.code', ['code' => $unit->code])</td>
                        <td class="nowrap">{{ $unit->serial_number ?? '—' }}</td>
                        <td>@include('pdf.partials.status', ['value' => $unit->status])</td>
                        <td>{{ $unit->cajon?->location?->nombre ?? '—' }}@if ($unit->cajon)<span class="sub">Cajón {{ $unit->cajon->codigo }}</span>@endif</td>
                        <td class="nowrap">{{ $unit->next_maintenance_at?->format('d-m-Y') ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ $ownReportsOnly ? 'Mis informes de novedades' : 'Historial de novedades' }} <span class="count">· {{ $reportsTotal }} en total, últimos {{ $reports->count() }}</span></h2>
    @if ($reports->isEmpty())
        <p class="empty">{{ $ownReportsOnly ? 'No has reportado novedades en este equipo.' : 'Este equipo no tiene informes de novedades registrados.' }}</p>
    @else
        <table class="list">
            <thead>
                <tr><th style="width: 19%">Código</th><th style="width: 11%">Fecha</th><th>Título y descripción</th><th style="width: 10%">Severidad</th><th style="width: 13%">Estado</th></tr>
            </thead>
            <tbody>
                @foreach ($reports as $report)
                    <tr>
                        <td>@include('pdf.partials.code', ['code' => $report->code])</td>
                        <td class="nowrap">{{ $report->created_at?->format('d-m-Y') }}</td>
                        <td>
                            @if ($report->title)<strong>{{ $report->title }}</strong>@endif
                            <span class="sub">{{ \Illuminate\Support\Str::limit($report->description, 160) }}</span>
                        </td>
                        <td>@include('pdf.partials.status', ['value' => $report->severity, 'label' => $report->severityLabel()])</td>
                        <td>@include('pdf.partials.status', ['value' => $report->status])</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
