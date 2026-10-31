### REQ-12 — Fichas técnicas de equipos (FU-05, todos los autenticados)
- [x] Endpoint `GET /api/equipment/{id}/technical-sheet` que genere/devuelva PDF de la ficha técnica.
- [x] Endpoint `GET /api/equipment/{id}/reports` para listar informes de novedades asociados (también descargables en PDF).

### REQ-18 — Registro de mantenciones (FU-05, PAN-01 y AD-01; DIR-01 lectura)
Modelo `MaintenanceRecord` (`TASKS/Equipos.md`, fase 5). Requiere las unidades de equipo (fase 6).
- [ ] `MaintenanceRecordStateMachine`: `en_curso` → `finalizada` | `anulada`; ambas finales. Excepción `InvalidMaintenanceRecordStatusTransition`. Test en `StateMachineTest`.
- [ ] `GET /api/maintenance-records`: filtros `equipment_unit_id`, `status`, `maintenance_type`, `execution_mode`, `from`/`to`; orden por prioridad del producto (`A` primero) y fecha.
- [ ] `GET /api/equipment-units/{equipmentUnit}/maintenance-records`: historial de la unidad.
- [ ] `POST /api/equipment-units/{equipmentUnit}/maintenance-records`: `maintenance_type` (`preventiva` | `correctiva` | `calibracion`), `execution_mode`, `external_supplier_id` (obligatorio si `externa`), `incident_report_id?` (debe ser de la misma unidad), `responsible_id?` (por defecto el usuario autenticado), `technical_notes?`. La unidad pasa a `en_reparacion`; `422` si está `prestado` o `dado_de_baja`, o si ya tiene una mantención `en_curso`.
- [ ] `PATCH /api/maintenance-records/{maintenanceRecord}`: checklist (`rev_a_approved`, `rev_b_approved`, `rev_c_approved`, `pfg_approved`), `requires_additional_maintenance`, `applies_recf`, `repair_cost`, `spare_parts_used`, `technical_notes`. Solo `en_curso`.
- [ ] `POST /api/maintenance-records/{maintenanceRecord}/finish`: exige `rev_a`, `rev_b` y `pfg` aprobadas (y `rev_c` si no es nula). `result`: `operativo` → unidad `disponible`; `baja` → unidad `dado_de_baja`. Fija `finished_at`, `last_maintenance_at` y `next_maintenance_at` (con `maintenance_frequency_months` del producto). Si tiene informe asociado, lo pasa a `reparado` o `dado_de_baja`. Sincroniza contadores del producto.
- [ ] `POST /api/maintenance-records/{maintenanceRecord}/cancel`: `anulada`; la unidad vuelve a `disponible`.
- [ ] `MaintenanceRecordPolicy`, `MaintenanceRecordResource` (`ResourceContractTest`), `ENDPOINTS.md`, `front-end.md`. La ficha técnica de la unidad (fase 8) lista sus mantenciones.
- [ ] Test Feature `MaintenanceRecordTest`: flujo completo con y sin proveedor externo, `422` (externa sin proveedor, unidad prestada, finalizar sin checklist, editar finalizada), `403` (PRO-01; DIR-01 escribiendo), efectos en unidad, informe y contadores.
- [ ] Pendiente: notificar las mantenciones próximas (`next_maintenance_at`) a `PAN-01`; mientras, se consultan con `GET /api/equipment-units?maintenance_due=1`.

### REQ-19 — Documentos de mantenimiento (FU-05, PAN-01 y AD-01; DIR-01 lectura)
Modelo `MaintenanceDocument` (`TASKS/Equipos.md`, fase 5).
- [ ] `POST /api/equipment-units/{equipmentUnit}/maintenance-documents` (`multipart`, `throttle:sensitive`): `document_type` (`certificado_calibracion` | `informe_tecnico` | `manual` | `garantia` | `otro`), `title`, `file` (PDF, 10 MB, disco privado), `valid_until?`, `maintenance_record_id?` (de la misma unidad). `uploaded_by` = usuario autenticado. `201`.
- [ ] `GET /api/equipment-units/{equipmentUnit}/maintenance-documents`: filtros `document_type`, `expiring_within_days`.
- [ ] `GET /api/maintenance-documents/{maintenanceDocument}/file`: descarga autenticada.
- [ ] `DELETE /api/maintenance-documents/{maintenanceDocument}`: `204`; borra también el archivo.
- [ ] `MaintenanceDocumentResource` con `is_expired` (`valid_until` pasada) en `ResourceContractTest`; `ENDPOINTS.md`, `front-end.md`. La ficha técnica de la unidad lista sus documentos vigentes.
- [ ] Test Feature `MaintenanceDocumentTest`: subida y descarga, `422` (no PDF, > 10 MB, mantención de otra unidad), `403` por rol, archivo en disco privado (`Storage::fake('local')`), borrado elimina el archivo.
