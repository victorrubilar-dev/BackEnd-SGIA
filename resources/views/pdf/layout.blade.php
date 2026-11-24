{{--
    Layout común de los PDFs (docs/pdf-estilo-trazo.md): estilo «Trazo» de la web en papel.
    Geist y Geist Mono (resources/fonts, OFL), tinta grafito, líneas de 1 px, burdeo solo como acento.
    Secciones: title, subtitle (opcional), meta (opcional, a la derecha), content. El número de página lo dibuja
    EquipmentPdfService después de renderizar.
--}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>@yield('title') · SGIA</title>
    <style>
        @font-face { font-family: 'Geist'; font-weight: 400; font-style: normal; src: url('{{ resource_path('fonts/Geist-Regular.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Geist'; font-weight: 500; font-style: normal; src: url('{{ resource_path('fonts/Geist-Medium.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Geist'; font-weight: 700; font-style: normal; src: url('{{ resource_path('fonts/Geist-Medium.ttf') }}') format('truetype'); }
        @font-face { font-family: 'Geist Mono'; font-weight: 400; font-style: normal; src: url('{{ resource_path('fonts/GeistMono-Regular.ttf') }}') format('truetype'); }

        @page { margin: 16mm 15mm 18mm 15mm; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Geist', 'DejaVu Sans', sans-serif; font-size: 9pt; line-height: 11pt; color: #1B1A17; }
        strong, b { font-weight: 500; }

        /* Cabecera del documento (solo la primera página) */
        .doc-head { width: 100%; border-collapse: collapse; border-bottom: 1.5pt solid #6B1729; margin-bottom: 4pt; }
        .doc-head td { vertical-align: bottom; padding: 0 0 9pt; }
        .brand { font-size: 8pt; color: #5F5B53; }
        .brand .mark { color: #6B1729; font-weight: 500; }
        h1 { margin: 6pt 0 3pt; font-size: 16pt; line-height: 19pt; font-weight: 500; letter-spacing: -0.2pt; }
        .subtitle { font-size: 9pt; color: #5F5B53; }
        .doc-meta { text-align: right; font-size: 8pt; color: #5F5B53; white-space: nowrap; }

        /* Secciones */
        h2 { margin: 15pt 0 6pt; padding-bottom: 4pt; border-bottom: 1px solid #E2DFD8; font-size: 10.5pt; font-weight: 500; page-break-after: avoid; }
        h2 .count { color: #5F5B53; font-weight: 400; }
        p { margin: 0 0 4pt; }
        .muted { color: #5F5B53; }
        .empty { color: #5F5B53; padding: 4pt 0; }
        .small { font-size: 8pt; }
        .nowrap { white-space: nowrap; }
        .num { text-align: right; white-space: nowrap; }

        /* Ficha de datos: pares rótulo / valor en dos columnas */
        table.kv { width: 100%; border-collapse: collapse; }
        table.kv td { padding: 3pt 0; vertical-align: top; border-bottom: 1px solid #EFEDE8; }
        table.kv td.k { width: 19%; padding-right: 8pt; color: #5F5B53; font-size: 8pt; line-height: 11pt; }
        table.kv td.v { width: 31%; padding-right: 14pt; }
        table.kv.single td.k { width: 24%; }
        table.kv.single td.v { width: 76%; }

        /* Tablas de listado */
        table.list { width: 100%; border-collapse: collapse; border: 1px solid #E2DFD8; }
        table.list th { background: #EFEDE8; color: #5F5B53; font-size: 7.5pt; font-weight: 500; text-align: left; padding: 3.5pt 6pt; border-bottom: 1px solid #E2DFD8; }
        table.list td { padding: 3.5pt 6pt; font-size: 8.5pt; vertical-align: top; border-bottom: 1px solid #EFEDE8; }
        table.list td .sub { display: block; margin-top: 1pt; color: #5F5B53; font-size: 7.5pt; line-height: 9.5pt; }

        /* Código (Geist Mono en etiqueta crema, como CODE_CHIP_CLASS) y estados */
        .code { font-family: 'Geist Mono', 'DejaVu Sans Mono', monospace; font-size: 7.5pt; color: #6B1729; background: #ECE6DA; padding: 1pt 3.5pt; border-radius: 2pt; white-space: nowrap; }
        .badge { font-size: 7.5pt; font-weight: 500; padding: 1pt 5pt; border-radius: 3pt; white-space: nowrap; }
        .tone-ok { color: #1E6B47; background: #E3EFE6; }
        .tone-info { color: #2F5C8A; background: #E3ECF5; }
        .tone-warn { color: #8A5300; background: #F8EBCF; }
        .tone-danger { color: #A3202E; background: #F7E1DF; }
        .tone-neutral { color: #4A463F; background: #EFEDE8; }

        /* Pie en todas las páginas (el número de página lo agrega el servicio a la derecha) */
        .doc-foot { position: fixed; bottom: -11mm; left: 0; right: 0; height: 8mm; border-top: 1px solid #E2DFD8; padding: 3pt 62pt 0 0; font-size: 7pt; line-height: 9pt; color: #5F5B53; }

        @yield('styles')
    </style>
</head>
<body>
    <div class="doc-foot">SGIA — Sistema de Gestión de Inventario y Activos · INACAP Sede Temuco · Generado el {{ now()->format('d-m-Y H:i') }}@hasSection('foot') · @yield('foot')@endif</div>

    <table class="doc-head">
        <tr>
            <td>
                <div class="brand"><span class="mark">SGIA</span> · INACAP Sede Temuco</div>
                <h1>@yield('title')</h1>
                @hasSection('subtitle')<div class="subtitle">@yield('subtitle')</div>@endif
            </td>
            @hasSection('meta')<td class="doc-meta">@yield('meta')</td>@endif
        </tr>
    </table>

    @yield('content')
</body>
</html>
