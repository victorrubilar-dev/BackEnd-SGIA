@extends('pdf.layout')

@php
    $overdue = $loans->filter(fn ($loan) => $loan->isOverdue())->count();
    $byStatus = $loans->countBy('status');
    $range = ($from || $to)
        ? 'Del '.($from ? \Illuminate\Support\Carbon::parse($from)->format('d-m-Y') : '…').' al '.($to ? \Illuminate\Support\Carbon::parse($to)->format('d-m-Y') : '…')
        : 'Todas las fechas';
@endphp

@section('title', 'Historial de préstamos')
@section('subtitle', $range)
@section('meta')
    {{ $loans->count() }} {{ $loans->count() === 1 ? 'préstamo' : 'préstamos' }}
@endsection

@section('styles')
    .summary { width: 100%; border-collapse: collapse; margin: 10pt 0 2pt; border: 1px solid #E2DFD8; }
    .summary td { width: 20%; padding: 6pt 9pt; border-right: 1px solid #E2DFD8; }
    .summary .n { display: block; font-size: 14pt; line-height: 17pt; font-weight: 500; }
    .summary .l { color: #5F5B53; font-size: 8pt; }
    .late { color: #A3202E; font-weight: 500; }
    table.list td { font-size: 8pt; padding: 3pt 5pt; }
    table.list th { padding: 3pt 5pt; }
@endsection

@section('content')
    <table class="summary">
        <tr>
            <td><span class="n">{{ $loans->count() }}</span><span class="l">Préstamos</span></td>
            <td><span class="n">{{ $byStatus->get('procesado', 0) }}</span><span class="l">Procesados</span></td>
            <td><span class="n">{{ $byStatus->get('pendiente', 0) + $byStatus->get('en_proceso', 0) }}</span><span class="l">Pendientes o en proceso</span></td>
            <td><span class="n">{{ $byStatus->get('rechazado', 0) }}</span><span class="l">Rechazados</span></td>
            <td style="border-right: 0"><span class="n {{ $overdue ? 'late' : '' }}">{{ $overdue }}</span><span class="l">Atrasados</span></td>
        </tr>
    </table>

    <h2>Detalle</h2>
    @if ($loans->isEmpty())
        <p class="empty">No hay préstamos para el filtro indicado.</p>
    @else
        <table class="list">
            <thead>
                <tr>
                    <th style="width: 11%">Código</th>
                    <th style="width: 8%">Estado</th>
                    <th style="width: 15%">Solicitante y carrera</th>
                    <th style="width: 15%">Asignatura y sala</th>
                    <th style="width: 6%">Fecha</th>
                    <th style="width: 6%">Vence</th>
                    <th style="width: 6%">Devuelto</th>
                    <th>Ítems</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($loans as $loan)
                    <tr>
                        <td>@include('pdf.partials.code', ['code' => $loan->code])<span class="sub">{{ \App\Support\PdfLabels::label($loan->type) }}</span></td>
                        <td>@include('pdf.partials.status', ['value' => $loan->status])</td>
                        <td>{{ $loan->borrower_name ?? $loan->requester?->name ?? '—' }}<span class="sub">{{ $loan->career?->name ?? 'Sin carrera' }}</span></td>
                        <td>{{ $loan->subject ?? '—' }}<span class="sub">{{ $loan->room ?? 'Sin sala' }}</span></td>
                        <td class="nowrap">{{ $loan->loan_date?->format('d-m-y') }}</td>
                        <td class="nowrap {{ $loan->isOverdue() ? 'late' : '' }}">{{ ($loan->due_date ?? $loan->loan_date)?->format('d-m-y') }}</td>
                        <td class="nowrap">{{ $loan->returned_at?->format('d-m-y') ?? '—' }}@if ($loan->returned_at)<span class="sub">{{ $loan->returned_at->format('H:i') }}</span>@endif</td>
                        <td class="small">{{ $loan->items->map(fn ($i) => $i->name.' ×'.$i->quantity.($i->relationLoaded('units') && $i->units->isNotEmpty() ? ' ('.$i->units->pluck('code')->implode(', ').')' : ''))->implode(' · ') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endsection
