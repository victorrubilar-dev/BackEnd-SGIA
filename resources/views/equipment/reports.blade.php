<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informes de novedades — {{ $product->name }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #222; margin: 0; }
        .header { background: #1f2937; color: #fff; padding: 14px 18px; }
        .header h1 { margin: 0; font-size: 17px; }
        .header p { margin: 4px 0 0; font-size: 10px; color: #cbd5e1; }
        .section { margin: 16px 18px 0; }
        .section h2 { font-size: 12px; text-transform: uppercase; color: #1f2937;
                      border-bottom: 1px solid #94a3b8; padding-bottom: 4px; margin: 0 0 8px; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { background: #e2e8f0; text-align: left; padding: 5px 8px; border: 1px solid #cbd5e1; font-size: 10px; }
        table.list td { padding: 5px 8px; border: 1px solid #cbd5e1; font-size: 10px; vertical-align: top; }
        .badge { display: inline-block; padding: 1px 6px; border-radius: 3px; font-size: 9px; font-weight: bold; color: #fff; }
        .sev-leve { background: #16a34a; }
        .sev-media { background: #d97706; }
        .sev-critica { background: #dc2626; }
        .muted { color: #64748b; }
        .footer { margin: 18px; font-size: 9px; color: #64748b; border-top: 1px solid #cbd5e1; padding-top: 6px; }
        .empty { color: #64748b; font-style: italic; padding: 6px 0; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Informes de novedades</h1>
        <p>Equipo: {{ $product->name }} · {{ $product->barcode ?? 'sin código' }} ·
           {{ $reports->count() }} informe(s)</p>
    </div>

    <div class="section">
        <h2>Hoja de vida del equipo</h2>
        @if ($reports->isEmpty())
            <p class="empty">Este equipo no tiene informes de novedades registrados.</p>
        @else
            <table class="list">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Fecha</th>
                        <th>Reportado por</th>
                        <th>Título / descripción</th>
                        <th>Severidad</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reports as $report)
                        <tr>
                            <td>{{ $report->code }}</td>
                            <td>{{ $report->created_at?->format('d-m-Y H:i') }}</td>
                            <td>{{ $report->reporter?->name ?? '—' }}</td>
                            <td>
                                @if ($report->title)<strong>{{ $report->title }}</strong><br>@endif
                                {{ \Illuminate\Support\Str::limit($report->description, 220) }}
                                @if ($report->attachment_original_name)
                                    <br><span class="muted">Adjunto: {{ $report->attachment_original_name }}</span>
                                @endif
                            </td>
                            <td>
                                <span class="badge sev-{{ $report->severity }}">
                                    {{ $report->severityLabel() }}
                                </span>
                            </td>
                            <td>{{ str_replace('_', ' ', $report->status) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="footer">
        Documento generado el {{ now()->format('d-m-Y H:i') }} por SGIA ·
        Historial de hoja de vida del equipo #{{ $product->id }}.
    </div>
</body>
</html>
