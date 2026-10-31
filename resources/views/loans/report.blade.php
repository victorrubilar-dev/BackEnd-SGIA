<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Historial de préstamos</title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 10px; color: #222; margin: 0; }
        .header { background: #1f2937; color: #fff; padding: 14px 18px; }
        .header h1 { margin: 0; font-size: 16px; }
        .header p { margin: 4px 0 0; font-size: 10px; color: #cbd5e1; }
        table { width: 100%; border-collapse: collapse; margin: 14px 0; }
        th { background: #e2e8f0; text-align: left; padding: 4px 6px; border: 1px solid #cbd5e1; }
        td { padding: 4px 6px; border: 1px solid #cbd5e1; vertical-align: top; }
        .late { color: #dc2626; font-weight: bold; }
        .empty { color: #64748b; font-style: italic; padding: 10px 18px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Historial de préstamos</h1>
        <p>{{ $loans->count() }} préstamo(s)
            @if ($from || $to) · {{ $from ?? '…' }} a {{ $to ?? '…' }} @endif
            · Generado {{ now()->format('d-m-Y H:i') }}</p>
    </div>

    @if ($loans->isEmpty())
        <p class="empty">No hay préstamos para el filtro indicado.</p>
    @else
        <table>
            <thead>
                <tr>
                    <th>Código</th><th>Estado</th><th>Solicitante</th><th>Sala</th>
                    <th>Fecha</th><th>Vence</th><th>Devuelto</th><th>Ítems</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($loans as $loan)
                    <tr>
                        <td>{{ $loan->code }}</td>
                        <td>{{ $loan->status }}</td>
                        <td>{{ $loan->borrower_name ?? $loan->requester?->name }}</td>
                        <td>{{ $loan->room }}</td>
                        <td>{{ $loan->loan_date?->format('d-m-Y') }}</td>
                        <td class="{{ $loan->isOverdue() ? 'late' : '' }}">{{ ($loan->due_date ?? $loan->loan_date)?->format('d-m-Y') }}</td>
                        <td>{{ $loan->returned_at?->format('d-m-Y H:i') ?? '—' }}</td>
                        <td>{{ $loan->items->map(fn ($i) => $i->name.' x'.$i->quantity.($i->relationLoaded('units') && $i->units->isNotEmpty() ? ' ['.$i->units->pluck('code')->implode(', ').']' : ''))->implode(', ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</body>
</html>
