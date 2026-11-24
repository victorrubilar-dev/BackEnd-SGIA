# Exportaciones (PDF y Excel) y campana de notificaciones

**Estado: backend implementado (noviembre 2026); frontend en curso.** Rama `feat/exportaciones-y-campana` en ambos repos. Seguimiento en `TASKS/Prestamos.md` (REQ-11), `TASKS/Productos.md` (REQ-06) y cambios de contrato en `TASKS/front-end.md`.

## Auditoría (2026-11-23, contra la API de desarrollo y en Chromium)

Archivos que **genera** el sistema (no los que sube el usuario):

| Archivo | Endpoint | API | Botón en la web |
|---|---|---|---|
| Historial de préstamos PDF | `GET /api/loans/export?format=pdf` | ✅ | ❌ «Error al exportar archivo PDF» |
| Historial de préstamos «Excel» | `GET /api/loans/export?format=excel` | ⚠️ entrega CSV | ❌ «Error al exportar archivo EXCEL»; además se guardaba con extensión `.xlsx` |
| Ficha técnica del equipo | `GET /api/equipment/{id}/technical-sheet` | ✅ | ✅ |
| Hoja de vida del equipo (informes) | `GET /api/equipment/{id}/reports?format=pdf` | ✅ | ✅ |
| Hoja de vida de la unidad | `GET /api/equipment-units/{id}/technical-sheet` | ✅ | ✅ |
| Informe de novedades | `GET /api/incident-reports/{id}/pdf` | ✅ | ✅ |
| Etiquetas (insumo, unidad, cajón) | `GET /api/{products,equipment-units,cajones}/{id}/label` | ✅ | ✅ (iframe de impresión) |
| Etiqueta térmica de equipo (producto) | — (se arma en el navegador) | — | ✅ con clic real (ventana emergente) |

Causas de lo que falla:

1. `exportLoans` (api-client) usa `get<Blob>`, que hace `response.json()`: con un PDF o un CSV siempre lanza error.
2. Las fechas del filtro no llegan: el cliente envía `from_date`/`to_date` y el export lee `from`/`to`.
3. `format=excel` es un alias de CSV, pero la web lo guarda como `.xlsx`: Excel dice que el archivo está dañado.
4. `downloadBlob` revoca la URL del blob en el mismo tick del `click()`: Chrome descarga, pero Firefox y Safari pueden cancelar la descarga.

## Decisiones

| # | Decisión |
|---|---|
| 1 | **Excel real (`.xlsx`)** con un escritor propio (`App\Support\XlsxWriter`): XML de SpreadsheetML dentro de un zip sin compresión armado en PHP. No requiere la extensión `zip` (no está en el entorno local) ni dependencias nuevas. |
| 2 | `format=excel` (y su alias `xlsx`) devuelve `.xlsx`; `format=csv` (y el valor por defecto) sigue siendo CSV para no romper clientes. |
| 3 | El cliente usa `getBlob` y envía `from`/`to`. `downloadBlob` revoca la URL con un retraso. |
| 4 | **Quitar una notificación = ocultarla solo para mí**: tabla `stock_alert_dismissals` (usuario + alerta). La alerta sigue activa en `/alertas` y para los demás. Si el stock se repone y vuelve a bajar, es otra alerta y vuelve a aparecer. |
| 5 | La campana pide `GET /api/alerts/critical-stock?exclude_dismissed=1`: la lista y los totales (`counts`) excluyen las ocultadas por el usuario, así el número de la campana baja. Sin el parámetro, nada cambia. |
| 6 | **Sonido de prueba** al llegar una notificación nueva: un tono corto generado con Web Audio (sin archivo). La campana consulta cada 60 s; suena solo si aparece una alerta que no estaba en la consulta anterior (no al cargar la página). |
| 7 | **Hover**: el ítem se pone rojo de forma gradual (fondo `danger-bg`, texto `danger`, transición de 300 ms) y aparece un ícono de basurero; **clic** (o Enter) la quita sin cerrar el menú. Sin movimiento con `prefers-reduced-motion`. El acceso a la bandeja queda en «Ver bandeja completa de alertas». |

## Contrato

### `GET /api/loans/export`

| `format` | Respuesta |
|---|---|
| ausente o `csv` | `text/csv` (sin cambios) |
| `excel` o `xlsx` | `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, archivo `prestamos-{fecha}.xlsx`: hoja «Préstamos» con las mismas columnas que el CSV, cabecera en negrita, fechas como texto ISO |
| `pdf` | `application/pdf` (sin cambios) |

`422` si `format` no es uno de esos valores.

### Notificaciones ocultas

```
stock_alert_dismissals: id, user_id (FK users, cascade), stock_alert_id (FK stock_alerts, cascade), created_at
unique (user_id, stock_alert_id)
```

| Método | Ruta | Roles | Respuesta |
|---|---|---|---|
| `POST` | `/api/alerts/{id}/dismiss` | `DIR-01`, `PAN-01` | `204`. Idempotente. `404` si la alerta no existe. |
| `GET` | `/api/alerts/critical-stock?exclude_dismissed=1` | `DIR-01`, `PAN-01` | Excluye de `data` y de `counts.active/critical/warning` las alertas ocultadas por el usuario. |

### Tests (backend)

- `LoanExportTest`: `format=excel` y `xlsx` devuelven un `.xlsx` válido con la fila del préstamo y caracteres escapados; `csv` sin cambios; `from`/`to` filtran; `pdf` sigue; `format=docx` → `422`.
- `XlsxWriterTest` (unitario): lee el zip a mano (sin la extensión `zip`), comprueba CRC y que cada XML sea válido; números como número, celdas vacías omitidas, caracteres de control quitados y nombre de hoja saneado. Además se abrió un archivo de prueba con LibreOffice.
- `StockAlertDismissTest`: ocultar por `PAN-01`/`DIR-01` (`204`, idempotente), `PRO-01`/`AD-01` `403`; `exclude_dismissed=1` saca la alerta y baja los totales solo para ese usuario; otro usuario la sigue viendo.

## Frontend

- `packages/api-client`: `exportLoans` con `getBlob` y `from`/`to`; `dismissStockAlert(id)` + `useDismissStockAlert` (optimista sobre la campana); `exclude_dismissed` en `CriticalStockAlertQueryParams`.
- `features/equipos/utils/download.ts`: `downloadBlob` revoca la URL después.
- Historial de préstamos: el archivo Excel se guarda `.xlsx` (ahora sí lo es).
- `NotificationBell`: `refetchInterval` de 60 s, sonido con `lib/notification-sound.ts` ante alertas nuevas, hover rojo gradual con basurero y clic para quitar.
- Tests: exportación (cliente pide `getBlob` y `from`/`to`), campana (hover/clic llama a ocultar y la quita de la lista; sonido solo con alerta nueva).

## Fases

1. [x] Plan y documentos.
2. [x] Backend: `XlsxWriter`, export `.xlsx`, tabla y endpoint de ocultar, `exclude_dismissed`, tests.
3. [ ] Frontend: exportación y descargas.
4. [ ] Frontend: campana (sonido, hover y quitar).
5. [ ] Prueba contra la API real: descargar PDF y Excel del historial (abrir el `.xlsx`), ocultar notificaciones y sonido.

## Fuera de alcance

- Botón para el PDF de informes por unidad (`/equipment-units/{id}/reports?format=pdf` existe pero ninguna pantalla lo usa).
- Volver a mostrar una notificación oculta, preferencia para silenciar el sonido y notificaciones en la app móvil.
- Etiqueta térmica de equipos a nivel de producto: funciona (ventana emergente), aunque el backend etiqueta los equipos por unidad.
