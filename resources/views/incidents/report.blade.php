<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe de novedades {{ $report->code }}</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11px; color: #222; margin: 0; }
        .header { background: #1f2937; color: #fff; padding: 14px 18px; }
        .header h1 { margin: 0; font-size: 17px; }
        .header p { margin: 4px 0 0; font-size: 10px; color: #cbd5e1; }
        .section { margin: 16px 18px 0; }
        .section h2 { font-size: 12px; text-transform: uppercase; color: #1f2937;
                      border-bottom: 1px solid #94a3b8; padding-bottom: 4px; margin: 0 0 8px; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data td { padding: 4px 8px; border: 1px solid #cbd5e1; vertical-align: top; }
        table.data td.label { background: #e2e8f0; width: 24%; font-weight: bold; }
        .box { display: inline-block; width: 12px; height: 12px; border: 1px solid #1f2937; text-align: center;
               line-height: 12px; font-weight: bold; margin-right: 6px; }
        .problem { margin-bottom: 8px; }
        .problem .desc { margin: 2px 0 0 20px; color: #334155; }
        .muted { color: #64748b; }
        .signature { margin-top: 10px; }
        .signature img { max-height: 90px; max-width: 260px; border-bottom: 1px solid #222; }
        .photo img { max-width: 360px; max-height: 260px; }
        .footer { margin: 18px; font-size: 9px; color: #64748b; border-top: 1px solid #cbd5e1; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Informe de novedades</h1>
        <p>{{ $report->code }} · Estado: {{ $report->status }}</p>
    </div>

    <div class="section">
        <h2>Datos generales</h2>
        <table class="data">
            <tr><td class="label">Nombre docente</td><td>{{ $report->teacher_name ?? $report->reporter?->name ?? '—' }}</td></tr>
            <tr><td class="label">Asignatura</td><td>{{ $report->subject ?? '—' }}</td></tr>
            <tr><td class="label">Fecha</td><td>{{ $report->report_date?->format('d-m-Y') ?? '—' }}</td></tr>
            <tr><td class="label">Laboratorio</td><td>{{ $report->location?->nombre ?? $report->location?->sala ?? '—' }}</td></tr>
            @if ($report->product)
                <tr>
                    <td class="label">Equipo</td>
                    <td>{{ $report->product->name }}
                        @if ($report->equipmentUnit) · Unidad {{ $report->equipmentUnit->code }}
                        @elseif ($report->product->barcode) · {{ $report->product->barcode }} @endif
                    </td>
                </tr>
            @endif
        </table>
    </div>

    <div class="section">
        <h2>Problema</h2>
        @php($marked = collect($report->problems ?? [])->keyBy('type'))
        @foreach (\App\Models\IncidentReport::PROBLEM_LABELS as $type => $label)
            <div class="problem">
                <span class="box">{{ $marked->has($type) ? 'X' : '' }}</span>{{ $label }}
                @if ($marked->has($type))
                    <div class="desc">{{ $marked[$type]['description'] }}</div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="section">
        <h2>Descripción o sugerencia</h2>
        <p>{{ $report->suggestion ?: '—' }}</p>
    </div>

    @if ($photo)
        <div class="section photo">
            <h2>Foto</h2>
            <img src="{{ $photo }}" alt="Foto del informe">
        </div>
    @endif

    <div class="section signature">
        <h2>Firma del profesor</h2>
        @if ($signature)
            <img src="{{ $signature }}" alt="Firma">
        @else
            <p class="muted">{{ $report->signature_path ? 'Firma registrada (no se puede incrustar: falta la extensión GD en el servidor).' : 'Sin firma.' }}</p>
        @endif
        <div>{{ $report->teacher_name ?? $report->reporter?->name }}
            @if ($report->signed_at)<span class="muted"> · firmado el {{ $report->signed_at->format('d-m-Y H:i') }}</span>@endif
        </div>
    </div>

    <div class="footer">SGIA · INACAP Sede Temuco · Generado el {{ now()->format('d-m-Y H:i') }}</div>
</body>
</html>
