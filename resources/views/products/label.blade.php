{{-- Etiqueta 50×30 mm para impresora térmica (docs/pdf-estilo-trazo.md): sin layout; mismo tamaño y orientación. --}}
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @font-face { font-family: 'Geist Mono'; font-weight: 400; src: url('{{ resource_path('fonts/GeistMono-Regular.ttf') }}') format('truetype'); }
    @font-face { font-family: 'Geist'; font-weight: 500; src: url('{{ resource_path('fonts/Geist-Medium.ttf') }}') format('truetype'); }
    @page { margin: 0; }
    body { margin: 0; text-align: center; color: #000; }
    .label { width: 50mm; height: 27mm; padding-top: 2.5mm; overflow: hidden; }
    {{-- Página 30x50 con la etiqueta girada 90°: Firefox fuerza la orientación según el tamaño de página. --}}
    .portrait { position: absolute; top: 0; left: 30mm; transform-origin: 0 0; transform: rotate(90deg); }
    img { width: 44mm; height: 16mm; }
    .code { font-family: 'Geist Mono', 'DejaVu Sans Mono', monospace; font-size: 10.5pt; line-height: 12pt; margin-top: 1.2mm; letter-spacing: 0.3pt; }
    .brand { font-family: 'Geist', 'DejaVu Sans', sans-serif; font-weight: 500; font-size: 5.5pt; line-height: 6.5pt; color: #000; letter-spacing: 0.4pt; }
</style>
</head>
<body>
    <div class="label{{ ($portrait ?? false) ? ' portrait' : '' }}">
        <img src="{{ $barcodeUri }}" alt="{{ $code }}">
        <div class="code">{{ $code }}</div>
        <div class="brand">SGIA · INACAP Temuco</div>
    </div>
</body>
</html>
