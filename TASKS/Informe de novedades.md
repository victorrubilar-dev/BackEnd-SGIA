### REQ-13 — Solicitud de reposición mediante informe de novedades (FU-05, PRO-01 y PAN-01)
- [x] Endpoint `POST /api/equipment/{id}/reports` que acepte formulario + adjunto opcional (documento/imagen).
- [x] Asociar el informe al equipo para trazabilidad en su hoja de vida.

### Atención de los informes (FU-05)
- [x] `GET /api/incident-reports`: listado global con filtros (`AD-01`, `DIR-01`, `PAN-01`).
- [x] `PATCH /api/incident-reports/{id}/status`: estados `reportado`, `en_revision`, `en_reparacion`, `reparado`, `dado_de_baja`, con transiciones controladas.
