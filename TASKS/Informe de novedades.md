### REQ-13 — Solicitud de reposición mediante informe de novedades (FU-05, PRO-01 y PAN-01)
- [x] ~~`POST /api/equipment/{id}/reports`~~ (retirado en noviembre 2026; lo reemplaza `POST /api/incident-reports`, ver abajo).
- [x] Asociar el informe al equipo para trazabilidad en su hoja de vida.

### Atención de los informes (FU-05)
- [x] `GET /api/incident-reports`: listado global con filtros (`AD-01`, `DIR-01`, `PAN-01`).
- [x] `PATCH /api/incident-reports/{id}/status`: estados `reportado`, `en_revision`, `en_reparacion`, `reparado`, `dado_de_baja`, con transiciones controladas.

### Informes por unidad (plan `Equipos.md`, fase 8)
- [x] `equipment_unit_id` obligatorio en informes de `equipo`/`maqueta`, `request_type` (`falla`/`reposicion`/`mantencion`) y `evidence_photo` privada con descarga autenticada (`UnitLifeSheetTest`).
- [x] `GET /api/incident-reports` filtra por `equipment_unit_id` y `request_type`; `GET /api/equipment-units/{id}/reports` en JSON o PDF (`UnitLifeSheetTest`).

### Formulario del docente (noviembre 2026)
- [x] `POST /api/incident-reports` solo `PRO-01`: asignatura, fecha, laboratorio, problemas marcados (`falla_equipo`, `falta_equipamiento`, `falta_insumo`, `otros`) cada uno con su descripción, sugerencia, firma PNG, foto opcional y equipo opcional por código (`IncidentReportTest`).
- [x] `GET /api/incident-reports/mine`, detalle, `/pdf` y `/signature`: el docente solo ve los suyos (`IncidentReportTest`).
- [x] Estados `reportado` → `en_revision` → `resuelto` | `descartado`; los informes anteriores conservan los suyos (`IncidentReportTest`, `MaintenanceRecordTest`).
- [x] Aviso a `PAN-01`, `AD-01` y `DIR-01` al crear, y al docente al cambiar el estado (`IncidentReportTest`).

### Informes del pañolero (noviembre 2026)
- [x] `POST /api/incident-reports` también para `PAN-01`, con el mismo formulario y firma (`IncidentReportTest`).
- [x] El aviso de creación excluye a quien reporta, y el de cambio de estado no se envía si quien reportó hace el cambio (`IncidentReportTest`).

### Visibilidad de los informes (noviembre 2026)
- [x] Quien reportó ve su informe; `PAN-01`, `DIR-01` y `AD-01` ven todos. Cada pañolero ve todos porque los informes no están asignados a un pañol (`IncidentReportTest`).
- [x] Las fichas técnicas de producto y de unidad solo incluyen los informes que el usuario puede ver: un docente ve únicamente los suyos (`TechnicalSheetTest`).

### Pendiente
- [ ] El PDF incrusta firma y foto solo con la extensión PHP `gd` instalada (el test del PDF no lo verifica porque no está en este entorno).
