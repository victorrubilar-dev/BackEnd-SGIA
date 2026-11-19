<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { margin: 0; font-family: DejaVu Sans, sans-serif; text-align: center; }
    .label { width: 50mm; height: 26mm; padding-top: 3mm; overflow: hidden; }
    {{-- Página 30x50 con la etiqueta girada 90°: Firefox fuerza la orientación según el tamaño de página. --}}
    .portrait { position: absolute; top: 0; left: 30mm; transform-origin: 0 0; transform: rotate(90deg); }
    img { width: 44mm; height: 17mm; }
    .code { font-size: 11pt; font-weight: bold; margin-top: 1.5mm; }
</style>
</head>
<body>
    <div class="label{{ ($portrait ?? false) ? ' portrait' : '' }}">
        <img src="{{ $barcodeUri }}" alt="{{ $code }}">
        <div class="code">{{ $code }}</div>
    </div>
</body>
</html>
