@extends('pdf.layout')

@php
    $product = $unit->product;
    $cajon = $unit->cajon;
    $location = $cajon?->location;
@endphp

@section('title', 'Hoja de vida de la unidad')
@section('subtitle', $product->name)
@section('meta')
    @include('pdf.partials.code', ['code' => $unit->code])
@endsection
@section('foot', 'Unidad #'.$unit->id.' · Uso interno de las carreras técnicas')

@section('content')
    <h2>Unidad</h2>
    <table class="kv">
        <tr>
            <td class="k">Código</td><td class="v">@include('pdf.partials.code', ['code' => $unit->code])</td>
            <td class="k">Estado</td><td class="v">@include('pdf.partials.status', ['value' => $unit->status])</td>
        </tr>
        <tr>
            <td class="k">Número de serie</td><td class="v">{{ $unit->serial_number ?? '—' }}</td>
            <td class="k">Pañol</td><td class="v">{{ $location?->storeroom?->name ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Sala</td>
            <td class="v">{{ $location?->nombre ?? '—' }}@if ($location?->building)<span class="muted"> · Edificio {{ $location->building }}</span>@endif @if ($location?->floor !== null)<span class="muted"> · Piso {{ $location->floor }}</span>@endif</td>
            <td class="k">Cajón</td><td class="v">@include('pdf.partials.code', ['code' => $cajon?->barcode ?? $cajon?->codigo])</td>
        </tr>
        <tr>
            <td class="k">Última mantención</td><td class="v">{{ $unit->last_maintenance_at?->format('d-m-Y') ?? '—' }}</td>
            <td class="k">Próxima mantención</td><td class="v">{{ $unit->next_maintenance_at?->format('d-m-Y') ?? '—' }}</td>
        </tr>
    </table>

    <h2>Producto</h2>
    <table class="kv">
        <tr>
            <td class="k">Nombre</td><td class="v">{{ $product->name }}</td>
            <td class="k">Tipo</td><td class="v">{{ \App\Support\PdfLabels::label($product->type) }}</td>
        </tr>
        <tr>
            <td class="k">Categoría</td><td class="v">{{ $product->category?->name ?? '—' }}</td>
            <td class="k">Prioridad de mantención</td><td class="v">{{ $product->maintenance_priority ?? '—' }}</td>
        </tr>
        <tr>
            <td class="k">Requiere calibración</td><td class="v">{{ $product->requires_calibration ? 'Sí' : 'No' }}</td>
            <td class="k">Frecuencia de mantención</td><td class="v">{{ $product->maintenance_frequency_months ? $product->maintenance_frequency_months.' '.($product->maintenance_frequency_months == 1 ? 'mes' : 'meses') : '—' }}</td>
        </tr>
    </table>

    <h2>Historial de préstamos <span class="count">· {{ $unit->loanItems->count() }}</span></h2>
    @if ($unit->loanItems->isEmpty())
        <p class="empty">La unidad no registra préstamos.</p>
    @else
        <table class="list">
            <thead><tr><th style="width: 22%">Préstamo</th><th>Solicitante</th><th style="width: 14%">Fecha</th><th style="width: 14%">Devuelto</th><th style="width: 14%">Condición</th></tr></thead>
            <tbody>
                @foreach ($unit->loanItems as $item)
                    <tr>
                        <td>@include('pdf.partials.code', ['code' => $item->loan->code])</td>
                        <td>{{ $item->loan->borrower_name ?? '—' }}</td>
                        <td class="nowrap">{{ $item->loan->loan_date?->format('d-m-Y') }}</td>
                        <td class="nowrap">{{ $item->pivot->returned_at ? \Illuminate\Support\Carbon::parse($item->pivot->returned_at)->format('d-m-Y') : '—' }}</td>
                        <td>@if ($item->pivot->return_condition)@include('pdf.partials.status', ['value' => $item->pivot->return_condition])@else<span class="muted">—</span>@endif</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Mantenciones <span class="count">· {{ $unit->maintenanceRecords->count() }}</span></h2>
    @if ($unit->maintenanceRecords->isEmpty())
        <p class="empty">La unidad no registra mantenciones.</p>
    @else
        <table class="list">
            <thead><tr><th style="width: 13%">Tipo</th><th style="width: 15%">Modalidad</th><th>Responsable</th><th style="width: 11%">Inicio</th><th style="width: 11%">Término</th><th style="width: 15%">Estado</th><th style="width: 11%" class="num">Costo</th></tr></thead>
            <tbody>
                @foreach ($unit->maintenanceRecords as $record)
                    <tr>
                        <td>{{ \App\Support\PdfLabels::label($record->maintenance_type) }}</td>
                        <td>{{ \App\Support\PdfLabels::label($record->execution_mode) }}</td>
                        <td>{{ $record->responsible?->name ?? '—' }}</td>
                        <td class="nowrap">{{ $record->started_at?->format('d-m-Y') }}</td>
                        <td class="nowrap">{{ $record->finished_at?->format('d-m-Y') ?? '—' }}</td>
                        <td>@include('pdf.partials.status', ['value' => $record->status])@if ($record->result)<span class="sub">{{ \App\Support\PdfLabels::label($record->result) }}</span>@endif</td>
                        <td class="num">${{ number_format((float) $record->repair_cost, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>Documentos vigentes <span class="count">· {{ $unit->maintenanceDocuments->count() }}</span></h2>
    @if ($unit->maintenanceDocuments->isEmpty())
        <p class="empty">La unidad no tiene documentos de mantenimiento vigentes.</p>
    @else
        <table class="list">
            <thead><tr><th style="width: 26%">Tipo</th><th>Título</th><th style="width: 15%">Vigente hasta</th><th style="width: 13%">Subido</th></tr></thead>
            <tbody>
                @foreach ($unit->maintenanceDocuments as $document)
                    <tr>
                        <td>{{ \App\Support\PdfLabels::label($document->document_type) }}</td>
                        <td>{{ $document->title }}</td>
                        <td class="nowrap">{{ $document->valid_until?->format('d-m-Y') ?? 'Sin vencimiento' }}</td>
                        <td class="nowrap">{{ $document->created_at?->format('d-m-Y') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>{{ $ownReportsOnly ? 'Mis informes de novedades' : 'Informes de novedades' }} <span class="count">· {{ $unit->incidentReports->count() }}</span></h2>
    @if ($unit->incidentReports->isEmpty())
        <p class="empty">{{ $ownReportsOnly ? 'No has reportado novedades en esta unidad.' : 'La unidad no tiene informes de novedades.' }}</p>
    @else
        <table class="list">
            <thead><tr><th style="width: 20%">Código</th><th style="width: 11%">Fecha</th><th style="width: 13%">Tipo</th><th>Descripción</th><th style="width: 14%">Estado</th></tr></thead>
            <tbody>
                @foreach ($unit->incidentReports as $report)
                    <tr>
                        <td>@include('pdf.partials.code', ['code' => $report->code])</td>
                        <td class="nowrap">{{ $report->created_at?->format('d-m-Y') }}</td>
                        <td>{{ \App\Support\PdfLabels::label($report->request_type) }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($report->title ?? $report->description, 120) }}</td>
                        <td>@include('pdf.partials.status', ['value' => $report->status])</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
