### REQ-13 — Solicitud de reposición mediante informe de novedades (FU-05, PRO-01 y PAN-01)
- [x] Endpoint `POST /api/equipment/{id}/reports` que acepte formulario + adjunto opcional (documento/imagen).
- [x] Asociar el informe al equipo para trazabilidad en su hoja de vida.

### Atención de los informes (FU-05)
- [x] `GET /api/incident-reports`: listado global con filtros (`AD-01`, `DIR-01`, `PAN-01`).
- [x] `PATCH /api/incident-reports/{id}/status`: estados `reportado`, `en_revision`, `en_reparacion`, `reparado`, `dado_de_baja`, con transiciones controladas.

### Informes por unidad (plan `Equipos.md`, fase 8)
- [x] `equipment_unit_id` obligatorio en informes de `equipo`/`maqueta`, `request_type` (`falla`/`reposicion`/`mantencion`) y `evidence_photo` privada con descarga autenticada (`UnitLifeSheetTest`).
- [x] `GET /api/incident-reports` filtra por `equipment_unit_id` y `request_type`; `GET /api/equipment-units/{id}/reports` en JSON o PDF (`UnitLifeSheetTest`).
