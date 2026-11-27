# Rankings en el centro de reportes: proveedores con más compras y profesores con más préstamos

**Estado: implementado en backend y frontend (2026-11-26), probado contra la API real.** Rama `feat/reportes-excel` de ambos repos. Amplía `docs/centro-reportes.md` (catálogo, vista previa `preview=1`, selector de período, hoja «Info», auditoría `report.export`). Pedido: «incluir informes sobre proveedores con más compras o profesores con más préstamos con períodos de tiempo a elegir».

## Problema

El centro de reportes lista operaciones, pero no responde «¿a quién le compramos más?» ni «¿qué profesores piden más préstamos?». Hoy hay que descargar las órdenes o los préstamos y agruparlos a mano en Excel.

## Decisiones

| # | Decisión |
|---|---|
| 1 | **Dos reportes de período nuevos** en un grupo nuevo del catálogo, «Rankings» (entre «Compras» y «Administración»): «Proveedores con más compras» y «Profesores con más préstamos». |
| 2 | **El período es obligatorio** (`from`/`to`, como el «Informe del período»): se elige con el mismo selector (año, semestre, mes, rango, últimos 30 días). Por defecto, el semestre en curso. |
| 3 | **Proveedores con más compras** (`GET /api/reports/top-suppliers`, `DIR-01`, como las demás de compras). Cuenta las órdenes de compra creadas en el período, **sin las canceladas** y sin las que no tienen proveedor. Por proveedor: Puesto, Proveedor, RUT, Órdenes, Recibidas (estado `completa`), Unidades (suma de cantidades de los ítems), Monto total, Promedio por orden y Última orden. Orden: monto total, luego órdenes, luego nombre. Filtro opcional `career_id` (la carrera del presupuesto de la orden). |
| 4 | **Profesores con más préstamos** (`GET /api/reports/top-teachers`, `DIR-01`, `PAN-01`). Cuenta los préstamos con `loan_date` en el período cuyo solicitante (`requested_by`) es `PRO-01`; PAN-01 ve solo los de su pañol (`Loan::visibleTo`, fila «Alcance» como en préstamos). Por profesor: Puesto, Profesor, Préstamos (sin los rechazados), Presenciales, Remotos, Rechazados, Unidades prestadas, Atrasados, Asignaturas, Carreras y Último préstamo. Orden: préstamos, luego unidades, luego nombre. Filtros opcionales `career_id`, `storeroom_id` y `tipo` (`presencial`/`remoto`). |
| 5 | **Puesto con empates**: el mismo puesto si la cifra principal (monto o préstamos) es igual; el siguiente salta (1, 2, 2, 4). |
| 6 | **`top` opcional** (1–100): deja solo los primeros N del ranking. En la página, el filtro «Mostrar» trae Top 10 (por defecto), Top 20, Top 50 y Todos. Sin `top` va el ranking completo. |
| 7 | **Segunda hoja con el detalle** de los que quedaron en el ranking, para revisar de dónde sale cada cifra. Proveedores: «Órdenes», con las columnas de «Órdenes de compra». Profesores: «Préstamos», con las columnas de «Historial de préstamos». Ninguna de las dos lleva hoja «Resumen». |
| 8 | **Fila de totales** en el ranking: órdenes, recibidas, unidades y monto, o préstamos, presenciales, remotos, rechazados, unidades y atrasados. |
| 9 | **Sin datos personales nuevos**: solo nombres, como el historial de préstamos (sin correo ni RUN del profesor). La descarga se audita como todas (`report.export`) y la vista previa no. |

## Contrato

```
GET /api/reports/top-suppliers?from=2026-07-01&to=2026-12-31[&career_id=3][&top=10][&preview=1]     DIR-01
GET /api/reports/top-teachers?from=2026-07-01&to=2026-12-31[&career_id=3][&storeroom_id=1][&tipo=remoto][&top=10][&preview=1]     DIR-01, PAN-01
```

- `from` y `to` obligatorios (`to` ≥ `from`), si faltan: `422`. `top`: entero 1–100.
- Respuesta: el `.xlsx` (`proveedores-mas-compras-AAAAMMDD-HHMMSS.xlsx`, `profesores-mas-prestamos-…`) o, con `preview=1`, el JSON de la vista previa de `docs/centro-reportes.md`.
- Hojas: «Ranking» + «Órdenes» (proveedores) o «Ranking» + «Préstamos» (profesores), e «Info» al final.
- Otros roles: `403`.

## Backend

- `app/Reports/TopSuppliersReport.php` y `app/Reports/TopTeachersReport.php` (el segundo `ScopedReport`), con la agregación en una consulta por cifra (`count(*) filter (where …)`, `sum`) y el puesto calculado en PHP (`TopRanking`). Las asignaturas, carreras y el último préstamo tampoco cuentan los rechazados.
- `ReportController::topSuppliers` y `::topTeachers`; `ExportTopSuppliersRequest` y `ExportTopTeachersRequest` (sobre `ExportRequest`).
- Rutas junto a `/reports/period`.

## Frontend

- `catalog.ts`: grupo «Rankings» y los dos reportes (`kind: 'period'`, período obligatorio, select «Mostrar» con `top`, carrera, y pañol y tipo en profesores).
- Nada más: la página, la vista previa, la descarga y «Mis descargas» ya funcionan con cualquier ruta del catálogo.

## Fases

- [x] 1. Plan y documentos (este archivo, `CLAUDE.md`, `ENDPOINTS.md` de ambos repos, `TASKS`).
- [x] 2. `TopSuppliersReport` + ruta + tests (ranking, cancelada fuera, período, carrera, `top`, empates, `403`/`422`).
- [x] 3. `TopTeachersReport` + ruta + tests (ranking, rechazados aparte, pañol de PAN-01, tipo, `top`, `403`/`422`).
- [x] 4. Catálogo del frontend + tests. En el menú se llaman «Top proveedores» y «Top profesores» (los nombres largos se cortaban en la columna del catálogo); el `.xlsx` mantiene el título completo.
- [x] 5. Prueba contra la API real (2026-11-26): DIR-01 ve 6 proveedores y 7 profesores en el 2.º semestre 2026 (empate en el puesto 3), con totales; PAN-01 solo los préstamos de su pañol y `403` en proveedores; AD-01 `403`.

## Fuera de alcance

- Gráficos del ranking (los dashboards van por GraphQL, `POST /api/metrics`).
- Rankings de otras cosas (productos más prestados, carreras con más gasto). Se pueden sumar con el mismo patrón.
- Comparar dos períodos en el mismo libro.
