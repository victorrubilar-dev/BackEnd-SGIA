# PDFs con el estilo de la web («Trazo»)

**Estado: implementado (noviembre 2026).** Rama `feat/pdf-estilo-trazo`. Solo backend (las vistas Blade de `resources/views` y `EquipmentPdfService`). Sistema de diseño de referencia: `FrondEnd-SGIA/.claude/skills/sgia-design/SKILL.md`.

## Problema

Los seis PDFs que genera el sistema (ficha técnica, hoja de vida de la unidad, informes de un equipo o unidad, informe de novedades, historial de préstamos y etiqueta) no se parecen a la web:

| Hoy | Problema |
|---|---|
| Cabecera azul marino a todo el ancho, rótulos en MAYÚSCULAS, Helvetica. | Otra identidad: la web es papel cálido, grafito, burdeo como único acento, Geist y tipo oración. |
| Subtítulo «SGIA — Sistema de Gestión de Inventario de Aula · Laravel». | Nombre equivocado del sistema y un detalle técnico que no le importa al lector. |
| Estados crudos: `en_revision`, `procesado`, `disponible`, `presencial`. | Se leen como datos internos. |
| Historial de préstamos en A4 vertical con 10 columnas: códigos y fechas se cortan en 2–3 líneas. | Difícil de leer y de revisar. |
| Sin número de página; el pie (cuando existe) cambia de un PDF a otro. | Poco profesional al imprimir varias hojas. |

## Decisiones

| # | Decisión |
|---|---|
| 1 | **Un layout común** `resources/views/pdf/layout.blade.php` y parciales (`pdf/partials/*`): cabecera, sección, ficha de datos (pares rótulo/valor), tabla, estado (badge) y código. Todas las vistas lo extienden. |
| 2 | **Tipografía de la web**: Geist 400/500 y Geist Mono 400 (TTF, licencia OFL, en `resources/fonts/` con su `OFL.txt`), con subconjunto de glifos para que el PDF pese poco. Texto 9 pt, secundario 8 pt, títulos de sección 10.5 pt, título del documento 16 pt; pesos 400/500 (nunca negrita 700); tipo oración. Geist Mono solo en códigos (barras, seriales, `PRE-…`, `NV-…`). |
| 3 | **Colores de la web** (`index.css`): tinta `#1B1A17`, secundario `#5F5B53`, líneas `#E2DFD8`, superficie alterna `#EFEDE8` (cabecera de tabla), burdeo `#6B1729` solo en una línea de 2 pt bajo la cabecera y en la marca «SGIA»; chips de código en crema `#ECE6DA` con texto burdeo `#6B1729` (como `CODE_CHIP_CLASS`). Estados con sus tonos suaves: disponible/procesado/resuelto en verde (`ok`), en préstamo/en revisión en azul (`info`), mantención/pendiente en ámbar (`warn`), atrasado/rechazado/crítico en rojo (`danger`). |
| 4 | **Cabecera**: «SGIA · INACAP Sede Temuco» (marca), título del documento, línea con el código o el período y la fecha de generación a la derecha. Sin fondos grandes ni bloques de color. |
| 5 | **Pie en todas las páginas**: «SGIA — Sistema de Gestión de Inventario y Activos · Generado el dd-mm-aaaa hh:mm» y «Página N de M» (dibujado por DomPDF en cada hoja). |
| 6 | **Etiquetas legibles** para estados, tipos y condiciones (`App\Support\PdfLabels`): `en_revision` → «En revisión», `procesado` → «Procesado», `presencial` → «Presencial», `dado_de_baja` → «Dado de baja», etc. Lo que no tenga traducción se muestra con la primera letra en mayúscula y sin guiones bajos. |
| 7 | **Historial de préstamos en A4 horizontal**, con resumen arriba (total, procesados, rechazados, atrasados), códigos y fechas sin cortar (`dd-mm-aa`), solicitante y carrera en una columna, ítems a lo ancho. |
| 8 | **Etiqueta 50×30 mm**: mismo diseño funcional (código de barras + código), con Geist Mono para el código y la marca «SGIA» chica. No cambia el tamaño ni la orientación (impresora térmica). |
| 9 | Las fechas en el formato de la web: `dd-mm-aaaa` (y `hh:mm` cuando corresponde). |
| 10 | Los tests que leen el texto del PDF pasan a extraerlo con `pdftotext` (poppler, ya requerido por el OCR): con una fuente embebida el texto va codificado y la lectura cruda del stream dejaría de encontrarlo. |

## Archivos

- `resources/fonts/` (Geist, Geist Mono, `OFL.txt`) y `EquipmentPdfService::render()`: `chroot` a `resources/fonts`, caché de fuentes en `storage/fonts`, subconjunto activado, pie con número de página.
- `resources/views/pdf/layout.blade.php`, `resources/views/pdf/partials/*.blade.php`.
- Vistas reescritas: `equipment/technical-sheet`, `equipment/unit-technical-sheet`, `equipment/reports`, `incidents/report`, `loans/report`, `products/label`.
- `app/Support/PdfLabels.php` (+ test unitario).
- `tests/Concerns/ExtractsPdfText.php` usado por `TechnicalSheetTest`, `UnitLifeSheetTest` y el resto que lea texto de PDF.

## Fases

1. [x] Plan y documentos.
2. [x] Fuentes, layout, parciales, `PdfLabels` y pie con número de página.
3. [x] Las seis vistas con el layout nuevo; tests en verde (extracción con `pdftotext`).
4. [x] Revisión visual de cada PDF (renderizado a imagen) y descarga desde la web contra la API real.

## Notas de implementación

- DomPDF no baja la altura de línea de la de la fuente, y Geist declara 1,35 em (×1,1 por defecto): con `fontHeightRatio` 0,9 las líneas quedan cerca de 1,2, como en la web.
- `page-break-inside: avoid` en las filas hacía que DomPDF cortara la página con 5 filas: se quitó. El historial de préstamos junta asignatura y sala en una columna para dar ancho a los ítems (~10 filas por hoja).
- `storage/fonts` guarda la caché de métricas y las fuentes registradas (no se versiona); `resources/fonts` es la única carpeta legible (`chroot`).
- Revisión: cada PDF renderizado a imagen antes y después; descarga de los 9 PDFs (6 tipos) contra la API de desarrollo como PAN-01 y DIR-01, todos con Geist embebido. Tests: `PdfLabelsTest`, `LoanExportTest` (A4 horizontal, estados legibles, «Página 1 de 1», Geist) y los de fichas y hojas de vida leyendo el texto con `pdftotext` (`Tests\Concerns\ExtractsPdfText`).

## Fuera de alcance

- El correo de solicitud de cotización (`mail/purchase-request-quotation`).
- Cambiar el contenido o los permisos de cada documento.
