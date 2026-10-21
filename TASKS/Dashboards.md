### REQ-14 — Dashboards (FU-06, DIR-01)
- [x] Endpoint `GET /api/dashboard/top-products` (más solicitados).
- [x] Endpoint `GET /api/dashboard/top-supplies` (insumos más solicitados).
- [x] Endpoint `GET /api/dashboard/careers-distribution`.
- [x] Endpoint `GET /api/dashboard/least-demanded`.
- [x] Endpoint `GET /api/dashboard/top-teachers`.

---

Para este requisito trabajaras con graphQL el unico endpoint es `api/metrics`.

El frontend debe tener los siguientes graficos, debes crear el codigo de graphQL basandote en que el front debe ser capaz de mostrarlo: 

### 1. Indicadores Clave de Desempeño (KPIs) (`apps/web`)
- [ ] **Tarjetas de Resumen Ejecutivo (`/`):**
  - [ ] Total de activos disponibles vs en préstamo vs en taller vs dados de baja.
  - [ ] Préstamos activos en el día y tasa de atrasos.
  - [ ] Contador de alertas de stock crítico no resueltas.
  - [ ] Tasa de rotación mensual del pañol en porcentaje.

### 2. Gráficos Interactivos con Chart.js (`react-chartjs-2`)
- [ ] **Configuración del Tema Oscuro:**
  - [ ] Paleta institucional integrada desde `packages/design-tokens` (fondo `#111113`, grid lines translúcidas `#222`, acento rojo/naranja `#ef4444`).
- [ ] **Top 10 Productos y Equipos Más Solicitados:**
  - [ ] Gráfico de barras horizontales mostrando cantidad de préstamos por equipo.
- [ ] **Insumos Fungibles de Mayor Consumo:**
  - [ ] Gráfico de barras con proyección de agotamiento.
- [ ] **Distribución de Uso por Carrera Académica:**
  - [ ] Gráfico de dona (Doughnut chart) con porcentajes por especialidad (ej. Ciberseguridad, Telecomunicaciones, Informática).
- [ ] **Inventario Ocioso (Equipos Menos Demandados):**
  - [ ] Tabla destacando equipos sin movimiento para reasignación o dar de baja.
- [ ] **Ranking de Docentes y Préstamos por Asignatura:**
  - [ ] Gráfico comparativo de docentes con mayor volumen de horas/equipos solicitados.

### 3. Filtros Temporales
- [ ] **Selector de Rango de Período:**
  - [ ] Filtro por Semestre Actual (ej. "2026-1", "2026-2"), Año Completo o Rango Personalizado.
  - [ ] Refresco automático de todas las consultas de analítica.
