### REQ-14 — Dashboards de demanda (FU-06, DIR-01 y AD-01)
- [x] Solo GraphQL: los REST `GET /api/dashboard/*` (rankings, `kpis`, `loans-by-teacher`) se eliminaron; `DashboardService::kpis` queda como fuente de `executiveKpis`.
- [x] Capa GraphQL `POST /api/metrics` con `executiveKpis` y `customMetric` (entidades `LOANS`, `SUPPLIES`, `EQUIPMENT`, `ALERTS`, `MAINTENANCE`, `CAREERS`, `TEACHERS`; métricas `COUNT`, `TOTAL`, `AVERAGE`, `RATE`; períodos incluidos `SEMESTER_PREVIOUS`, `YEAR_TO_DATE` y `CUSTOM`).
- [x] `CAREERS` con `groupBy: "level"` desde el catálogo `careers` (`/api/careers`, unido por nombre con `users.area`).
- [x] `EQUIPMENT` y `SUPPLIES` separados por `products.type` (`equipo` / `insumo`).
- [x] `MAINTENANCE.failure_type` sigue siendo la gravedad del informe; `groupBy: maintenance_type` (nuevo) cuenta los registros de mantención por tipo (`preventiva`/`correctiva`/`calibracion`, sin anuladas) (`MetricsGraphQLTest`).

### REQ-15 — Dashboard personalizado por usuario (FU-06, DIR-01 y AD-01)
Modelos `ChartTemplate` y `UserChart` (`TASKS/Equipos.md`, fase 1). Cada usuario arma su panel con hasta 5 gráficos.
Decisiones: la plantilla trae `chart_type`, `entity`, `metric`, `group_by` y `period` por defecto (las genéricas `bar`, `line`, `pie`, `kpi` solo la visualización) y el gráfico hereda lo que no envía. `type` usa `ChartType` (`BAR`, `LINE`, `DOUGHNUT`, `POLAR_AREA`); `group_by` vacío = serie en el tiempo; `CUSTOM` exige `date_from`. Los inactivos ocupan posición y cuentan para el máximo. Los enums del esquema GraphQL se generan de `MetricsService::entities()`, `METRICS`, `TIME_RANGES` y `CHART_TYPES` (misma fuente que la validación).
- [x] `GET /api/chart-templates`: plantillas disponibles (sembradas; sin CRUD por ahora).
- [x] Sin gráficos repetidos por usuario: misma consulta y tipo (`type`, `entity`, `metric`, `group_by`, `period`, fechas) → `422` en `chart`, al crear y al editar la consulta; las escrituras del panel se serializan bloqueando la fila del usuario (doble clic). Test en `UserChartTest`.
- [x] Plantillas con consulta para cada forma, incluida área polar (`ChartTemplateSeeder`); test que crea un gráfico válido desde cada una.
- [x] `GET /api/me/charts`: gráficos del usuario autenticado, ordenados por `position`, con su plantilla.
- [x] `POST /api/me/charts`: `chart_template_id`, `custom_title`, `type`, `position` (1–5), `entity`, `metric`, `group_by`, `period`. `entity`, `metric`, `group_by` y `period` se validan contra los mismos valores que acepta `customMetric` de `/api/metrics` (incluida `SCALE_MODELS`; constantes compartidas con `MetricsService`/`DashboardPeriod`, sin duplicar listas). `422` si la posición está ocupada o ya tiene 5.
- [x] `PATCH /api/me/charts/{userChart}`: editar cualquier campo salvo `user_id`; `is_active` para ocultarlo sin borrarlo.
- [x] `PUT /api/me/charts/positions`: reordenar en bloque (`[{id, position}]`) en una transacción. Requiere que `unique(user_id, position)` sea `DEFERRABLE INITIALLY DEFERRED` (fase 1) para poder intercambiar posiciones.
- [x] `DELETE /api/me/charts/{userChart}`: `204`.
- [x] `UserChartPolicy`: solo el dueño ve y modifica sus gráficos (otro usuario → `404`, para no revelar ids).
- [x] `UserChartResource` y `ChartTemplateResource` en `ResourceContractTest`; `front-end.md` y `ENDPOINTS.md`.
- [x] Test Feature `UserChartTest`: caso feliz, `403` para PAN-01/PRO-01, `422` (posición 6, posición ocupada, sexto gráfico, entidad inválida), reordenar intercambiando dos posiciones, aislamiento entre usuarios.

## Referencia para el cliente (`apps/web`, no es trabajo de este repo)

Gráficos previstos con `react-chartjs-2` sobre tema oscuro (fondo `#111113`, acento `#ef4444`):
- Tarjetas de resumen: activos por estado, préstamos activos y atrasos, alertas sin resolver, rotación mensual.
- Top 10 productos y equipos: barras horizontales.
- Insumos de mayor consumo: barras con proyección de agotamiento.
- Uso por carrera: dona.
- Inventario ocioso: tabla.
- Ranking de docentes y préstamos por asignatura: barras comparativas.
- Selector de período: semestre actual, año completo o rango personalizado.
