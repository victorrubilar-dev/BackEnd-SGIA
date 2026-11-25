# Centro de reportes `/reportes` con vista previa

**Estado: plan aprobado (2026-11-25), sin implementar.** Rama `feat/reportes-excel` de ambos repos. Continúa `docs/reportes-excel.md` (los 16 reportes Excel por pantalla, ya implementados). Pedido: «mejor creemos una página de reportes específica» y «que se muestre una vista previa de cómo queda antes de descargarlo».

## Problema

Los reportes solo se sacan desde el botón de cada pantalla, con los filtros que esa pantalla tenga. No hay un lugar para elegir el reporte y el período (año, semestre, mes), varios reportes de operaciones no aceptan fechas, no hay totales ni resumen, y no se ve cómo queda el archivo hasta abrirlo.

## Decisiones

| # | Decisión |
|---|---|
| 1 | **Página `/reportes`** para `DIR-01`, `PAN-01` y `AD-01` (entrada «Reportes» en el menú). Cada rol ve solo los reportes de sus pantallas (los mismos roles de cada `/export`). PRO-01 queda fuera. Los botones de cada pantalla se mantienen (descarga directa). |
| 2 | **Maestro-detalle compacto** (neutros + burdeo): a la izquierda el catálogo agrupado (Operación, Compras, Administración, Paquetes) en filas de una línea, marcado «foto» (estado actual: inventario, ubicaciones, unidades, estudiantes, proveedores, usuarios) o «período» (operaciones); a la derecha los filtros, el alcance, la **vista previa** y «Descargar Excel». |
| 3 | **Catálogo en el frontend** (`features/reportes/catalog.ts`): por reporte, su ruta `/export`, sus filtros (tipo de control y opciones) y si es foto o período. Sin endpoint de catálogo: los roles los aplica la ruta de cada export. |
| 4 | **Vista previa = la misma consulta que la descarga.** Cada `/export` (y `/reports/period`) acepta `preview=1` y responde JSON con las mismas hojas: cabeceras, tipo de cada columna, las primeras 20 filas, el total de filas, la fila de totales, si se cortó y la hoja «Info». Lo que se ve es lo que se descarga. La vista previa no se audita ni cuenta como descarga. |
| 5 | **La vista previa se actualiza sola** al cambiar los filtros (espera 400 ms; TanStack Query cachea por filtros). Pestañas por hoja, formato como en Excel (`dd-mm-aaaa`, `$ 1.990.000`, años sin separador, sí/no), «Mostrando 20 de 165 filas». |
| 6 | **Período en todas las operaciones**: `from`/`to` sobre la fecha de la operación en alertas (creada), novedades (fecha del informe), órdenes (creada), movimientos de presupuesto (fecha del movimiento) y monitoreo (inicios y fallidos), además de los que ya lo tienen (préstamos, mantenciones, solicitudes, facturas, auditoría). Se agrega al filtro compartido, así el listado también lo acepta (aditivo). |
| 7 | **Selector de período** en la página: año, semestre (`2026-1` = enero–junio, `2026-2` = julio–diciembre, la regla de `DashboardPeriod`), mes, rango y «últimos 30 días». Muestra las fechas exactas («01-01-2026 a 30-06-2026») y las envía como `from`/`to`. Por defecto, el semestre en curso. |
| 8 | **Hoja «Resumen»** en los reportes de período: una tabla `Agrupado por` · `Valor` · `Cantidad` · `Monto` (filtrable) armada por `ReportExporter` desde las filas ya filtradas de la hoja principal, así cuadra con el detalle. Préstamos (estado, tipo, carrera), mantenciones (tipo, estado, carrera; costo), novedades (severidad, estado, carrera), alertas (tipo, estado), solicitudes (estado, carrera; total), órdenes (estado, proveedor; total), facturas (estado, carrera; total), movimientos (tipo, carrera; monto), auditoría (acción, usuario), inicios de sesión (rol, usuario). |
| 9 | **Fila de totales** al pie de las hojas con montos o cantidades sumables: `SUBTOTAL(109, …)` con el valor ya calculado, en negrita y fuera del autofiltro, así en Excel suma solo lo filtrado. |
| 10 | **«Informe del período»** (`GET /api/reports/period`, `DIR-01`, `PAN-01`): un libro con «Resumen general» (préstamos, unidades prestadas, atrasados, mantenciones y su costo, novedades por severidad, alertas críticas), y las hojas de préstamos, mantenciones, novedades y alertas del período, con sus resúmenes. `from` y `to` obligatorios; `career_id` y `storeroom_id` opcionales. PAN-01 ve lo de su pañol, como en cada reporte. |
| 11 | **«Alcance» en la hoja «Info»**: «Préstamos del pañol Pañol Central Temuco» para PAN-01 en préstamos (y el informe del período); «Todo lo visible para el rol» en el resto. |
| 12 | **Todas las descargas se auditan** (`report.export`), no solo las de datos personales; `new_values` suma `path` y `query` (parámetros originales) y `personal` (sí/no). Así existe el historial y se sabe quién descargó qué. |
| 13 | **«Mis descargas»** (`GET /api/me/report-downloads`, los tres roles): las últimas 20 descargas del usuario (reporte, filtros legibles, filas, fecha) con «Repetir», que vuelve a pedir la misma ruta con los mismos parámetros. |
| 14 | **Filtros del servidor donde hoy filtra el navegador**: `/equipos` exporta `/products/export?type=equipo` con `search`, `oracle_category_id` y `estado` (nuevo filtro de productos: `disponible`, `en_reparacion`, `dado_de_baja`, `sin_stock`, la misma regla de `Product::operationalStatus`) y trae las hojas «Productos» y «Unidades»; `/alertas` suma `search` (nombre o código del producto); `/compras/proveedores` envía al servidor la `search` que hoy solo filtra la página cargada. |
| 15 | Dashboards sin cambios: siguen en GraphQL. Los resúmenes viven solo dentro del Excel y su vista previa. |

## Contrato

### Vista previa: `GET /api/<recurso>/export?…&preview=1`

Mismos roles, filtros y `422` que la descarga.

```json
{
  "title": "Historial de préstamos",
  "filename": "prestamos-20261125-101500.xlsx",
  "truncated": false,
  "sheets": [
    {
      "name": "Préstamos",
      "headers": ["Código", "Tipo", "…", "Fecha", "Año"],
      "types": ["text", "text", "…", "date", "year"],
      "rows": [["REM-2026-AB12CD", "Remoto", "…", "2026-03-10", 2026]],
      "total_rows": 165,
      "totals": [null, "Total", "…"],
      "truncated": false
    }
  ],
  "info": [["Reporte", "Historial de préstamos"], ["Alcance", "Todo lo visible para el rol"], ["Filtros", "Desde: 2026-01-01; Hasta: 2026-06-30"]]
}
```

`rows`: hasta 20 por hoja; fechas en ISO (`2026-03-10` o `2026-03-10T14:30:00-03:00`), números como número, `sí`/`no` como texto. `types` usa los nombres de `XlsxWriter` (`text`, `date`, `datetime`, `money`, `integer`, `year`). `totals`: `null` si la hoja no tiene totales.

### Período

`from`/`to` (fecha, `to >= from`) en `/alerts/export`, `/incident-reports/export`, `/purchases/export`, `/budgets/export` (movimientos) y `/monitoring/export` (inicios y fallidos; `hours` sigue si no hay `from`), y en sus listados.

### Informe del período

| Método | Ruta | Roles | Respuesta |
|---|---|---|---|
| `GET` | `/api/reports/period?from&to[&career_id][&storeroom_id][&preview=1]` | `DIR-01`, `PAN-01` | `.xlsx` `informe-periodo-…` (o JSON con `preview=1`); `422` sin `from`/`to` |

### Mis descargas

| Método | Ruta | Roles | Respuesta |
|---|---|---|---|
| `GET` | `/api/me/report-downloads` | `DIR-01`, `PAN-01`, `AD-01` | `{ data: [{ id, report, title, filters: {etiqueta: valor}, path, query: {…}, rows, created_at }] }`, las 20 últimas del usuario |

### Otros

- `GET /api/products` y `/products/export`: filtros nuevos `estado` y `oracle_category_id`.
- `GET /api/alerts/critical-stock` y `/alerts/export`: filtro nuevo `search`.
- Hoja «Info»: fila nueva «Alcance».

## Backend

- `ReportExporter::preview()` (JSON) junto a `download()`; ambos usan el mismo `collect()`. `ExportRequest` acepta `preview` (booleano) y los controladores llaman a `$exporter->respond($report, $request)`, que elige.
- `ReportSheet` suma `summary` (cabeceras por las que agrupar y cabecera del monto) y `totals` (cabeceras a sumar). `XlsxWriter` escribe la fila de totales con fórmula `SUBTOTAL` y estilo negrita, fuera del rango del autofiltro.
- `ScopedReport` (interfaz opcional) para el texto de «Alcance»; lo implementan `LoansReport` y `PeriodReport`.
- `PeriodReport` combina las hojas de `LoansReport`, `MaintenanceRecordsReport`, `IncidentReportsReport` y `StockAlertsReport` con la misma solicitud, más «Resumen general».
- `ReportDownloadController@index` lee `audit_logs` (`report.export` del usuario).
- Tests: vista previa de cada tipo de reporte (mismas hojas y filas que el `.xlsx`, 20 filas, sin auditoría), período en los exports nuevos, «Resumen» y totales (cuadran con el detalle; `SUBTOTAL` en el XML), informe del período (roles, `422`, alcance de PAN-01), historial de descargas (solo las propias), filtros nuevos de productos y alertas.

## Frontend

- `features/reportes/`: `pages/reportes-page.tsx`, `catalog.ts`, `components/report-catalog.tsx`, `report-filters.tsx`, `period-picker.tsx`, `report-preview.tsx` (pestañas y tabla con el formato de Excel), `my-downloads.tsx`.
- `api-client`: `fetchReportPreview(path, params)` + `useReportPreview` (query con `placeholderData` para no parpadear), `fetchMyReportDownloads` + `useMyReportDownloads`; la descarga reutiliza `exportReport`.
- Ruta `/reportes` en `ROUTE_PERMISSIONS` (`DIR-01`, `PAN-01`, `AD-01`) y en `nav-config.ts`.
- `/equipos`, `/alertas` y `/compras/proveedores`: los filtros pasan al servidor (decisión 14) y el botón los envía.
- Tests: catálogo por rol, selector de período (semestre → fechas), la vista previa pide `preview=1` con los filtros y muestra pestañas y formato, «Descargar» envía los mismos parámetros, «Repetir» de mis descargas.

## Fases

1. [ ] Plan y documentos (este archivo, TASKS, ENDPOINTS).
2. [ ] Backend: vista previa (`preview=1`), «Alcance», auditar todas las descargas (`path`, `query`).
3. [ ] Backend: `from`/`to` en alertas, novedades, órdenes, movimientos y monitoreo; `estado` y `oracle_category_id` en productos; `search` en alertas.
4. [ ] Backend: hoja «Resumen» y fila de totales.
5. [ ] Backend: «Informe del período» y `GET /api/me/report-downloads`.
6. [ ] Frontend: página `/reportes` (catálogo, filtros, período, vista previa, descarga) y menú.
7. [ ] Frontend: «Mis descargas» con «Repetir»; filtros del servidor en `/equipos`, `/alertas` y `/compras/proveedores`.
8. [ ] Prueba contra la API real con los tres roles (vista previa = archivo descargado, abierto con LibreOffice).

## Fuera de alcance

- Reportes nuevos (historial de una unidad, préstamos de un estudiante, mantenciones vencidas): después.
- Reportes programados o enviados por correo, favoritos y reportes en la app móvil.
- Vista previa en los botones de cada pantalla (siguen descargando directo).
