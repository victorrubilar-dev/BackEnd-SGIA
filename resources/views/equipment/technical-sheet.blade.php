<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Ficha técnica — {{ $product->name }}</title>
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
        <h1>Ficha Técnica de Equipo</h1>
        <p>SGIA — Sistema de Gestión de Inventario de Aula · {{ config('app.name') }}</p>
    </div>

    <div class="section">
        <h2>Datos del equipo</h2>
        <table class="data">
            <tr>
                <td class="label">Código de barras</td>
                <td>{{ $product->barcode ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">Nombre</td>
                <td>{{ $product->name }}</td>
            </tr>
            <tr>
                <td class="label">Descripción</td>
                <td>{{ $product->description ?? 'Sin descripción registrada.' }}</td>
            </tr>
            <tr>
                <td class="label">Área / carrera</td>
                <td>{{ $product->area ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">Stock actual / mínimo</td>
                <td>{{ $product->quantity }} / {{ $product->stock_minimo }}</td>
            </tr>
            <tr>
                <td class="label">Estado</td>
                <td>{{ $product->is_active ? 'Activo' : 'Inactivo' }}</td>
            </tr>
            <tr>
                <td class="label">Registrado el</td>
                <td>{{ $product->created_at?->format('d-m-Y H:i') ?? '—' }}</td>
            </tr>
            <tr>
                <td class="label">Última actualización</td>
                <td>{{ $product->updated_at?->format('d-m-Y H:i') ?? '—' }}</td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Proveedor</h2>
        @if ($product->supplier)
            <table class="data">
                <tr>
                    <td class="label">Proveedor</td>
                    <td>{{ $product->supplier->name }}</td>
                </tr>
                <tr>
                    <td class="label">Contacto</td>
                    <td>
                        {{ $product->supplier->contact_name ?? '—' }}
                        @if ($product->supplier->email) · {{ $product->supplier->email }} @endif
                        @if ($product->supplier->phone) · {{ $product->supplier->phone }} @endif
                    </td>
                </tr>
                <tr>
                    <td class="label">Categoría</td>
                    <td>{{ $product->supplier->category ?? '—' }}</td>
                </tr>
            </table>
        @else
            <p class="muted">Sin proveedor asociado.</p>
        @endif
    </div>

    <div class="section">
        <h2>Ubicación física</h2>
        <table class="data">
            <tr>
                <td class="label">Sala / ubicación</td>
                <td>
                    @if ($product->cajon?->location)
                        {{ $product->cajon->location->sala ?? $product->cajon->location->nombre }}
                        ({{ $product->cajon->location->descripcion ?? 'sin descripción' }})
                    @elseif ($product->location)
                        {{ $product->location->sala ?? $product->location->nombre }}
                        ({{ $product->location->cajon ?? $product->location->descripcion ?? 'sin descripción' }})
                    @else
                        Sin ubicación asignada
                    @endif
                </td>
            </tr>
            <tr>
                <td class="label">Cajón</td>
                <td>
                    @if ($product->cajon)
                        {{ $product->cajon->codigo }}
                        @if ($product->cajon->descripcion) · {{ $product->cajon->descripcion }} @endif
                    @elseif ($product->location?->cajon)
                        {{ $product->location->cajon }}
                    @else
                        Sin cajón asignado
                    @endif
                </td>
            </tr>
        </table>
    </div>

    <div class="section">
        <h2>Historial de novedades ({{ $reportsTotal }} en total · últimos {{ $reports->count() }})</h2>
        @if ($reports->isEmpty())
            <p class="empty">Este equipo no tiene informes de novedades registrados.</p>
        @else
            <table class="list">
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Fecha</th>
                        <th>Título / descripción</th>
                        <th>Severidad</th>
                        <th>Estado</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($reports as $report)
                        <tr>
                            <td>{{ $report->code }}</td>
                            <td>{{ $report->created_at?->format('d-m-Y') }}</td>
                            <td>
                                @if ($report->title)<strong>{{ $report->title }}</strong><br>@endif
                                {{ \Illuminate\Support\Str::limit($report->description, 140) }}
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
        Equipo #{{ $product->id }} · Uso interno de carreras técnicas.
    </div>
</body>
</html>
