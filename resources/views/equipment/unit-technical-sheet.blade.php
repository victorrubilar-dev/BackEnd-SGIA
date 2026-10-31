<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Hoja de vida — {{ $unit->code }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #222; margin: 0; }
        .header { background: #1f2937; color: #fff; padding: 14px 18px; }
        .header h1 { margin: 0; font-size: 17px; }
        .header p { margin: 4px 0 0; font-size: 10px; color: #cbd5e1; }
        .section { margin: 16px 18px 0; }
        .section h2 { font-size: 12px; text-transform: uppercase; color: #1f2937;
                      border-bottom: 1px solid #94a3b8; padding-bottom: 4px; margin: 0 0 8px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data td { padding: 5px 8px; border: 1px solid #cbd5e1; vertical-align: top; }
        table.data td.label { width: 32%; background: #f1f5f9; font-weight: bold; color: #334155; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { background: #e2e8f0; text-align: left; padding: 5px 8px; border: 1px solid #cbd5e1; font-size: 10px; }
        table.list td { padding: 5px 8px; border: 1px solid #cbd5e1; font-size: 10px; vertical-align: top; }
        .muted { color: #64748b; }
        .footer { margin: 18px; font-size: 9px; color: #64748b; border-top: 1px solid #cbd5e1; padding-top: 6px; }
        .empty { color: #64748b; font-style: italic; padding: 6px 0; }
    </style>
</head>
<body>
    @php
        $product = $unit->product;
        $cajon = $unit->cajon;
        $location = $cajon?->location;
    @endphp
    <div class="header">
        <h1>Hoja de vida de la unidad {{ $unit->code }}</h1>
        <p>SGIA — Sistema de Gestión de Inventario de Aula · {{ config('app.name') }}</p>
    </div>

    <div class="section">
        <h2>Producto</h2>
        <table class="data">
            <tr><td class="label">Nombre</td><td>{{ $product->name }}</td></tr>
            <tr><td class="label">Tipo</td><td>{{ $product->type }}</td></tr>
            <tr><td class="label">Categoría</td><td>{{ $product->category?->name ?? '—' }}</td></tr>
            <tr><td class="label">Prioridad de mantención</td><td>{{ $product->maintenance_priority }}</td></tr>
            <tr><td class="label">Requiere calibración</td><td>{{ $product->requires_calibration ? 'Sí' : 'No' }}</td></tr>
            <tr><td class="label">Frecuencia de mantención</td><td>{{ $product->maintenance_frequency_months ? $product->maintenance_frequency_months.' mes(es)' : '—' }}</td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Unidad</h2>
        <table class="data">
            <tr><td class="label">Código</td><td>{{ $unit->code }}</td></tr>
            <tr><td class="label">Número de serie</td><td>{{ $unit->serial_number ?? '—' }}</td></tr>
            <tr><td class="label">Estado</td><td>{{ str_replace('_', ' ', $unit->status) }}</td></tr>
            <tr><td class="label">Pañol</td><td>{{ $location?->storeroom?->name ?? '—' }}</td></tr>
            <tr><td class="label">Sala</td><td>{{ $location?->nombre ?? '—' }}@if ($location?->building) · Edificio {{ $location->building }}@endif @if ($location?->floor !== null) · Piso {{ $location->floor }}@endif</td></tr>
            <tr><td class="label">Estante (cajón)</td><td>{{ $cajon?->codigo ?? '—' }}</td></tr>
            <tr><td class="label">Última mantención</td><td>{{ $unit->last_maintenance_at?->format('d-m-Y') ?? '—' }}</td></tr>
            <tr><td class="label">Próxima mantención</td><td>{{ $unit->next_maintenance_at?->format('d-m-Y') ?? '—' }}</td></tr>
        </table>
    </div>

    <div class="section">
        <h2>Historial de préstamos ({{ $unit->loanItems->count() }})</h2>
        @if ($unit->loanItems->isEmpty())
            <p class="empty">La unidad no registra préstamos.</p>
        @else
            <table class="list">
                <thead><tr><th>Préstamo</th><th>Solicitante</th><th>Fecha</th><th>Devuelto</th><th>Condición</th></tr></thead>
                <tbody>
                    @foreach ($unit->loanItems as $item)
                        <tr>
                            <td>{{ $item->loan->code }}</td>
                            <td>{{ $item->loan->borrower_name ?? '—' }}</td>
                            <td>{{ $item->loan->loan_date?->format('d-m-Y') }}</td>
                            <td>{{ $item->pivot->returned_at ? \Illuminate\Support\Carbon::parse($item->pivot->returned_at)->format('d-m-Y') : '—' }}</td>
                            <td>{{ $item->pivot->return_condition ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="section">
        <h2>Mantenciones ({{ $unit->maintenanceRecords->count() }})</h2>
        @if ($unit->maintenanceRecords->isEmpty())
            <p class="empty">La unidad no registra mantenciones.</p>
        @else
            <table class="list">
                <thead><tr><th>Tipo</th><th>Modalidad</th><th>Responsable</th><th>Inicio</th><th>Término</th><th>Estado</th><th>Costo</th></tr></thead>
                <tbody>
                    @foreach ($unit->maintenanceRecords as $record)
                        <tr>
                            <td>{{ $record->maintenance_type }}</td>
                            <td>{{ str_replace('_', ' ', $record->execution_mode) }}</td>
                            <td>{{ $record->responsible?->name ?? '—' }}</td>
                            <td>{{ $record->started_at?->format('d-m-Y') }}</td>
                            <td>{{ $record->finished_at?->format('d-m-Y') ?? '—' }}</td>
                            <td>{{ str_replace('_', ' ', $record->status) }}</td>
                            <td>{{ number_format((float) $record->repair_cost, 0, ',', '.') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="section">
        <h2>Informes de novedades ({{ $unit->incidentReports->count() }})</h2>
        @if ($unit->incidentReports->isEmpty())
            <p class="empty">La unidad no tiene informes de novedades.</p>
        @else
            <table class="list">
                <thead><tr><th>Código</th><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Estado</th></tr></thead>
                <tbody>
                    @foreach ($unit->incidentReports as $report)
                        <tr>
                            <td>{{ $report->code }}</td>
                            <td>{{ $report->created_at?->format('d-m-Y') }}</td>
                            <td>{{ $report->request_type }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($report->title ?? $report->description, 120) }}</td>
                            <td>{{ str_replace('_', ' ', $report->status) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="footer">
        Documento generado el {{ now()->format('d-m-Y H:i') }} por SGIA · Unidad #{{ $unit->id }} · Uso interno de carreras técnicas.
    </div>
</body>
</html>
