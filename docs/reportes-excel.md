# Reportes en Excel por módulo

**Estado: aprobado (2026-11-25), en implementación en la rama `feat/reportes-excel` de ambos repos.** Primero los botones en cada pantalla (como en el historial de préstamos); el centro de reportes `/reportes` se decide después de ver cómo quedan. Seguimiento en `TASKS/*.md` (casillas «Exportar Excel»), contrato para el frontend en `TASKS/front-end.md` y rutas en «Endpoints Pendientes» de `ENDPOINTS.md`.

## Problema

Hoy solo el historial de préstamos exporta a Excel (`GET /api/loans/export?format=xlsx`, `docs/exportaciones-y-campana.md`). El resto de pantallas con listados (inventario, auditoría, mantenciones, compras, etc.) no permite sacar los datos. Se quiere un botón **«Exportar Excel»** en cada pantalla con listado: exporta lo que la pantalla tiene filtrado y, si no hay filtros, el reporte general.

## Decisiones propuestas

| # | Decisión |
|---|---|
| 1 | **El Excel se genera en el backend**, no en el navegador: los listados vienen paginados, y el backend ya aplica la visibilidad por rol (PAN-01 solo ve su pañol). |
| 2 | **Una ruta `GET /api/<recurso>/export` por módulo**, con los mismos filtros que el `index`, como `/loans/export`. Roles: los de la pantalla que tiene el botón (nunca más que el listado), así PRO-01 no exporta unidades ni novedades y proveedores queda en `DIR-01`. Reutiliza los `applyFilters` existentes para que el Excel y la tabla nunca difieran. |
| 3 | **Filtros = los de la pantalla.** El botón envía los filtros activos. Sin filtros, sale el reporte general (todos los registros visibles para el rol). El botón es el mismo «Exportar Excel» del historial de préstamos; el aviso (toast) y la hoja «Info» dicen si salió filtrado o completo. |
| 4 | **`XlsxWriter` crece** (sin dependencias nuevas): varias hojas, fechas como fecha real de Excel, montos con formato CLP (`$ #.##0`), cabecera en negrita con fondo gris (sin verde ni amarillo). |
| 5 | **Hoja «Info»** al final de cada libro: nombre del reporte, generado por, fecha y hora (Chile), filtros aplicados en texto legible, total de filas y si se cortó por el tope. |
| 6 | **Tope de 10.000 filas** por hoja (hoy 5.000 en préstamos). Si se corta, se avisa en la hoja «Info» y con la cabecera `X-Report-Truncated: 1`, y la web muestra un aviso. |
| 7 | Solo `.xlsx`. El CSV y el PDF de préstamos siguen igual; no se agregan CSV nuevos. |
| 8 | Dashboards/analítica **fuera de alcance**: son GraphQL (`/api/metrics`) y no se agregan REST de analítica. |
| 9 | **Datos personales completos** (RUN/RUT, correo, IP) en Usuarios, Estudiantes, Auditoría y Monitoreo; cada exportación de esos cuatro queda en `audit_logs` (acción `export`). |
| 10 | **Centro de reportes `/reportes`: después.** Se decide tras ver los botones funcionando. |

## Vistas propuestas (reportes)

### Operación (PAN-01, DIR-01)

| Pantalla | Ruta nueva | Filtros que respeta | Hojas y columnas |
|---|---|---|---|
| Inventario `/inventario` | `GET /api/products/export` | área/carrera, estado de stock, sala, cajón, búsqueda, pañol | **Productos**: código, nombre, tipo, categoría, carrera, sala, cajón, stock, stock mínimo, en reparación, de baja, crítico (sí/no), proveedor. **Unidades** (equipos y maquetas): código, producto, serie, estado, ubicación, próxima mantención |
| Equipos `/equipos` | `GET /api/equipment-units/export` | categoría, estado, búsqueda, pañol | **Unidades**: código, producto, serie, estado, sala, cajón, última y próxima mantención, préstamos totales |
| Historial de préstamos `/prestamos/historial` | ya existe (`/loans/export`) | **mejora**: carrera, estado, sala, pañol, fechas (hoy la web no envía el estado) | **Préstamos** (igual que hoy, con fechas reales) + **Detalle** nuevo: una fila por ítem/unidad |
| Alertas `/alertas` | `GET /api/alerts/export` | tipo, resuelta/activa | **Alertas**: producto, tipo, stock al alertar, stock mínimo, fecha, resuelta, resuelta por |
| Mantenciones `/mantenciones` | `GET /api/maintenance-records/export` | carrera / sin carrera, desde/hasta | **Mantenciones**: unidad, producto, carrera, tipo, resultado, fecha, responsable, notas técnicas, próxima |
| Novedades `/novedades` | `GET /api/incident-reports/export` | estado, severidad, búsqueda | **Informes**: folio, equipo/unidad, reportado por, severidad, estado, fecha, descripción, atendido por |
| Estudiantes `/estudiantes` (PAN-01) | `GET /api/students/export` | carrera, activo/inactivo, búsqueda | **Estudiantes**: RUT, nombre, carrera, correo, activo, préstamos totales, préstamos activos |

### Compras (DIR-01)

| Pantalla | Ruta nueva | Filtros | Hojas |
|---|---|---|---|
| Solicitudes `/compras/solicitudes` | `GET /api/purchase-requests/export` | estado, código, desde/hasta | **Solicitudes** (código, estado, presupuesto, total, fechas por etapa) + **Ítems** |
| Órdenes `/compras/ordenes` | `GET /api/purchases/export` | estado, búsqueda | **Órdenes** (OC, proveedor, solicitud, total, estado, llegada) + **Ítems** |
| Facturas `/compras/facturas` | `GET /api/invoice-intakes/export` | estado, presupuesto, desde/hasta | **Facturas** (folio, proveedor, OC, solicitud, neto, IVA, total, estado) + **Ítems** |
| Presupuestos `/compras/presupuestos` | `GET /api/budgets/export` | año | **Resumen** por carrera (asignado, reservado, gastado, disponible) + **Movimientos** del año |
| Proveedores `/compras/proveedores` | `GET /api/suppliers/export` | área, categoría, búsqueda | **Proveedores**: RUT, razón social, áreas, contacto, correo, activo |

### Administración (AD-01)

| Pantalla | Ruta nueva | Filtros | Hojas |
|---|---|---|---|
| Auditoría `/auditoria` | `GET /api/audit-logs/export` | acción, tipo de entidad, usuario, desde/hasta | **Eventos**: fecha, usuario, rol, acción, entidad, ID, cambios (antes → después en texto) |
| Usuarios `/usuarios` | `GET /api/users/export` | rol, área, carrera, pañol, estado, búsqueda | **Usuarios**: RUN, nombre, correo, rol, área, carrera, pañol, activo, último acceso |
| Monitoreo `/monitoreo` | `GET /api/monitoring/export` | horas, correo, IP | **Inicios de sesión** + **Fallidos** + **Sesiones activas** |

### Después: centro de reportes `/reportes`

Una pantalla por rol que lista los reportes disponibles con un formulario de filtros (fechas, carrera, pañol) para descargarlos sin entrar a cada módulo. Usa las mismas rutas `/export`; no agrega backend. Se decide tras ver los botones (decisión 10).

## Contrato común

- `GET /api/<recurso>/export?<filtros del index>` → `200` `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`, `Content-Disposition: attachment; filename="<reporte>-AAAAMMDD-HHMMSS.xlsx"`, `X-Report-Truncated: 0|1`.
- `403` por rol (roles de la pantalla), `422` por filtro inválido (FormRequest propio o compartido con el `index`).
- Las rutas `/export` van antes de las `{id}` en `routes/api.php`.

## Backend

- `App\Support\XlsxWriter`: `build()` se mantiene; nuevo `buildWorkbook(list<Sheet>)` con varias hojas, estilos de fecha, fecha-hora y moneda, y tipo de celda por columna.
- `App\Reports\<Módulo>Report` (una clase por reporte): recibe el query ya filtrado y devuelve sus hojas. `App\Services\ReportExporter` arma la hoja «Info», aplica el tope y responde la descarga.
- Lectura con `lazy()`/`cursor()` y `with()` de las relaciones, para no cargar todo en memoria ni hacer N+1.
- Auditoría: registrar en `audit_logs` cada exportación de Auditoría, Usuarios, Estudiantes y Monitoreo (datos personales).
- Tests: un Feature por reporte (caso feliz con lectura del `.xlsx`, filtros aplicados, alcance por pañol de PAN-01, `403`, `422`) + `XlsxWriterTest` para varias hojas y estilos.

## Frontend

- `packages/api-client`: `exportReport(path, params)` genérico con `getBlob` que devuelve el blob, el nombre del archivo y si se cortó.
- `components/shared/export-excel-button.tsx`: el mismo botón «Exportar Excel» del historial de préstamos (outline, `sm`, ícono `FileSpreadsheet`), con estado de carga, toast de error y aviso si se cortó. Recibe la ruta y los filtros activos de la página; el toast dice «con filtros» o «completo». Va junto al título de cada pantalla, como en préstamos.
- Tests: el botón envía los filtros de la página; aviso filtrado/completo; error y aviso de corte.

## Fases

1. [ ] Plan y documentos (este archivo, TASKS, ENDPOINTS).
2. [ ] Backend: `XlsxWriter` multihoja con estilos, `ReportExporter`, hoja «Info», tests.
3. [ ] Backend: reportes de operación (inventario, equipos, préstamos mejorado, alertas, mantenciones, novedades, estudiantes).
4. [ ] Backend: reportes de compras (solicitudes, órdenes, facturas, presupuestos, proveedores).
5. [ ] Backend: reportes de administración (auditoría, usuarios, monitoreo).
6. [ ] Frontend: `exportReport` + `ExportExcelButton` y botón en cada pantalla.
7. [ ] (Después) Frontend: centro de reportes `/reportes`, según cómo queden los botones.
8. [ ] Prueba contra la API real con cada rol: abrir cada `.xlsx` en LibreOffice, con y sin filtros.

## Fuera de alcance

- Exportar dashboards/analítica (GraphQL).
- Reportes programados o enviados por correo.
- Exportación desde la app móvil (PRO-01).
- Historial de cotizaciones antiguas (solo lectura, poco uso).
