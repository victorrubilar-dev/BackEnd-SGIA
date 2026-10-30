### REQ-14 — Dashboards de demanda (FU-06, DIR-01 y AD-01)
- [x] `GET /api/dashboard/top-products` (más solicitados).
- [x] `GET /api/dashboard/top-supplies` (insumos más solicitados).
- [x] `GET /api/dashboard/careers-distribution`.
- [x] `GET /api/dashboard/least-demanded`.
- [x] `GET /api/dashboard/top-teachers`.
- [x] KPIs ejecutivos en `GET /api/dashboard/kpis`: activos disponibles / en préstamo / en taller / de baja, préstamos del día, tasa de atrasos, alertas de stock sin resolver y rotación.
- [x] `GET /api/dashboard/loans-by-teacher`: préstamos por docente y asignatura.
- [x] Filtro por período (`period`, `from`/`to`, `since`) en todas las consultas.
- [x] Capa GraphQL `POST /api/metrics` con `executiveKpis` y `customMetric` (entidades `LOANS`, `SUPPLIES`, `EQUIPMENT`, `ALERTS`, `MAINTENANCE`, `CAREERS`, `TEACHERS`; métricas `COUNT`, `TOTAL`, `AVERAGE`, `RATE`; períodos incluidos `SEMESTER_PREVIOUS`, `YEAR_TO_DATE` y `CUSTOM`).
- [x] `CAREERS` con `groupBy: "level"` desde el catálogo `careers` (`/api/careers`, unido por nombre con `users.area`).
- [x] `EQUIPMENT` y `SUPPLIES` separados por `products.type` (`equipo` / `insumo`).
- [ ] `MAINTENANCE.failure_type` sin dato propio: se usa la gravedad del informe.

## Referencia para el cliente (`apps/web`, no es trabajo de este repo)

Gráficos previstos con `react-chartjs-2` sobre tema oscuro (fondo `#111113`, acento `#ef4444`):
- Tarjetas de resumen: activos por estado, préstamos activos y atrasos, alertas sin resolver, rotación mensual.
- Top 10 productos y equipos: barras horizontales.
- Insumos de mayor consumo: barras con proyección de agotamiento.
- Uso por carrera: dona.
- Inventario ocioso: tabla.
- Ranking de docentes y préstamos por asignatura: barras comparativas.
- Selector de período: semestre actual, año completo o rango personalizado.
