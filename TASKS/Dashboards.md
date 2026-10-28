### REQ-14 — Dashboards de demanda (FU-06, DIR-01 y AD-01)
- [x] `GET /api/dashboard/top-products` (más solicitados).
- [x] `GET /api/dashboard/top-supplies` (insumos más solicitados).
- [x] `GET /api/dashboard/careers-distribution`.
- [x] `GET /api/dashboard/least-demanded`.
- [x] `GET /api/dashboard/top-teachers`.
- [ ] KPIs ejecutivos: activos disponibles / en préstamo / en taller / de baja, préstamos del día, tasa de atrasos, alertas de stock sin resolver, rotación mensual. Dependen de devoluciones, vencimientos y estado del activo (ver `rework.md`, fase 5).
- [ ] Filtro por período (semestre, año o rango) en todas las consultas.
- [ ] Decisión pendiente: el requisito original pedía GraphQL en `/api/metrics`; lo implementado es REST. Definir cuál se mantiene (ver `rework.md`, fase 5).

## Referencia para el cliente (`apps/web`, no es trabajo de este repo)

Gráficos previstos con `react-chartjs-2` sobre tema oscuro (fondo `#111113`, acento `#ef4444`):
- Tarjetas de resumen: activos por estado, préstamos activos y atrasos, alertas sin resolver, rotación mensual.
- Top 10 productos y equipos: barras horizontales.
- Insumos de mayor consumo: barras con proyección de agotamiento.
- Uso por carrera: dona.
- Inventario ocioso: tabla.
- Ranking de docentes y préstamos por asignatura: barras comparativas.
- Selector de período: semestre actual, año completo o rango personalizado.
