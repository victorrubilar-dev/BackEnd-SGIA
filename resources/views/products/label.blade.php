<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    body { margin: 0; padding: 3mm 3mm 0; font-family: DejaVu Sans, sans-serif; text-align: center; }
    img { width: 44mm; height: 17mm; }
    .code { font-size: 11pt; font-weight: bold; margin-top: 1.5mm; }
</style>
</head>
<body>
    <img src="{{ $barcodeUri }}" alt="{{ $code }}">
    <div class="code">{{ $code }}</div>
</body>
</html>
