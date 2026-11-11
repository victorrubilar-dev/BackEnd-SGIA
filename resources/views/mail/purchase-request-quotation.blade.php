<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Solicitud de cotización {{ $purchaseRequest->code }}</title>
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color: #1f2937; line-height: 1.6;">
    <h2 style="color: #1d4ed8;">Solicitud de cotización {{ $purchaseRequest->code }}</h2>

    <p>Estimado/a {{ $supplier->contact_name ?: $supplier->name }},</p>

    <p>
        Desde <strong>SGIA — Sistema de Gestión de Inventario (INACAP Sede Temuco)</strong>
        le solicitamos formalmente una cotización para los siguientes productos:
    </p>

    <table border="1" cellpadding="8" cellspacing="0" style="border-collapse: collapse; width: 100%; max-width: 640px;">
        <thead style="background-color: #eff6ff;">
            <tr>
                <th align="left">Producto</th>
                <th align="center">Cantidad</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>{{ $item->product?->name }}</td>
                    <td align="center">{{ $item->quantity }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @if ($purchaseRequest->notes)
        <p><strong>Observaciones:</strong> {{ $purchaseRequest->notes }}</p>
    @endif

    <p>Por favor responda este correo con su cotización (precio unitario, total y plazo de entrega), indicando el código <strong>{{ $purchaseRequest->code }}</strong>.</p>

    <p>Saludos cordiales,<br>
    <strong>{{ $purchaseRequest->creator?->name ?: 'Equipo SGIA' }}</strong></p>
</body>
</html>
