### REQ-12 — Fichas técnicas de equipos (FU-05, todos los autenticados)
- [x] Endpoint `GET /api/equipment/{id}/technical-sheet` que genere/devuelva PDF de la ficha técnica.
- [x] Endpoint `GET /api/equipment/{id}/reports` para listar informes de novedades asociados (también descargables en PDF).

### REQ-18 — Registro de mantenciones (FU-05, PAN-01 y AD-01; DIR-01 lectura)
Modelo `MaintenanceRecord` (`TASKS/Equipos.md`, fase 5). Requiere las unidades de equipo (fase 6).
Decisiones: se puede abrir sobre una unidad `disponible` o ya `en_reparacion` (p. ej. devuelta dañada); al anular, la unidad y el informe vuelven al estado que tenían al abrirla (`previous_unit_status`, `previous_report_status`). El checklist solo se exige con `result: operativo`; `baja` no lo exige. `requires_additional_maintenance` es informativo (la unidad queda `disponible`). `repair_cost` no descuenta presupuesto. Con una mantención `en_curso`, el estado de la unidad solo lo cambia la mantención (`PATCH /equipment-units/{id}/status` → `422`).
- [x] `MaintenanceRecordStateMachine`: `en_curso` → `finalizada` | `anulada`; ambas finales. Excepción `InvalidMaintenanceRecordStatusTransition`. Test en `StateMachineTest`.
- [x] `GET /api/maintenance-records`: filtros `equipment_unit_id`, `status`, `maintenance_type`, `execution_mode`, `from`/`to`; orden por prioridad del producto (`A` primero) y fecha.
- [x] `GET /api/equipment-units/{equipmentUnit}/maintenance-records`: historial de la unidad.
- [x] `POST /api/equipment-units/{equipmentUnit}/maintenance-records`: `maintenance_type` (`preventiva` | `correctiva` | `calibracion`), `execution_mode`, `external_supplier_id` (obligatorio si `externa`), `incident_report_id?` (debe ser de la misma unidad), `responsible_id?` (por defecto el usuario autenticado), `technical_notes?`. La unidad pasa a `en_reparacion`; `422` si está `prestado` o `dado_de_baja`, o si ya tiene una mantención `en_curso`.
- [x] `PATCH /api/maintenance-records/{maintenanceRecord}`: checklist (`rev_a_approved`, `rev_b_approved`, `rev_c_approved`, `pfg_approved`), `requires_additional_maintenance`, `applies_recf`, `repair_cost`, `spare_parts_used`, `technical_notes`. Solo `en_curso`.
- [x] `POST /api/maintenance-records/{maintenanceRecord}/finish`: exige `rev_a`, `rev_b` y `pfg` aprobadas (y `rev_c` si no es nula). `result`: `operativo` → unidad `disponible`; `baja` → unidad `dado_de_baja`. Fija `finished_at`, `last_maintenance_at` y `next_maintenance_at` (con `maintenance_frequency_months` del producto). Si tiene informe asociado, lo pasa a `reparado` o `dado_de_baja`. Sincroniza contadores del producto.
- [x] `POST /api/maintenance-records/{maintenanceRecord}/cancel`: `anulada`; la unidad vuelve a `disponible`.
- [x] `MaintenanceRecordPolicy`, `MaintenanceRecordResource` (`ResourceContractTest`), `ENDPOINTS.md`, `front-end.md`. La ficha técnica de la unidad (fase 8) lista sus mantenciones.
- [x] Test Feature `MaintenanceRecordTest`: flujo completo con y sin proveedor externo, `422` (externa sin proveedor, unidad prestada, finalizar sin checklist, editar finalizada), `403` (PRO-01; DIR-01 escribiendo), efectos en unidad, informe y contadores.
- [x] Notificar las mantenciones próximas (`next_maintenance_at` vencida o en 7 días, sin mantención en curso ni de baja) a `PAN-01`: resumen diario de `sgia:send-reminders`, una vez por fecha (`ReminderTest`). El listado sigue en `GET /api/equipment-units?maintenance_due=1`.

### REQ-19 — Documentos de mantenimiento (FU-05, PAN-01 y AD-01; DIR-01 lectura)
Modelo `MaintenanceDocument` (`TASKS/Equipos.md`, fase 5). Decisión: los documentos van por unidad (no por producto); suben y borran `PAN-01` y `AD-01`, descargan además `DIR-01`; `PRO-01` no tiene acceso.
- [x] `POST /api/equipment-units/{equipmentUnit}/maintenance-documents` (`multipart`, `throttle:sensitive`): `document_type` (`certificado_calibracion` | `informe_tecnico` | `manual` | `garantia` | `otro`), `title`, `file` (PDF, 10 MB, disco privado), `valid_until?`, `maintenance_record_id?` (de la misma unidad). `uploaded_by` = usuario autenticado. `201`.
- [x] `GET /api/equipment-units/{equipmentUnit}/maintenance-documents`: filtros `document_type`, `expiring_within_days`.
- [x] `GET /api/maintenance-documents/{maintenanceDocument}/file`: descarga autenticada.
- [x] `DELETE /api/maintenance-documents/{maintenanceDocument}`: `204`; borra también el archivo.
- [x] `MaintenanceDocumentResource` con `is_expired` (`valid_until` pasada) en `ResourceContractTest`; `ENDPOINTS.md`, `front-end.md`. La ficha técnica de la unidad lista sus documentos vigentes.
- [x] Test Feature `MaintenanceDocumentTest`: subida y descarga, `422` (no PDF, > 10 MB, mantención de otra unidad), `403` por rol, archivo en disco privado (`Storage::fake('local')`), borrado elimina el archivo.

### Hoja de vida por unidad (plan `Equipos.md`, fase 8)
- [x] `GET /api/equipment-units/{id}/technical-sheet`: PDF con producto, ubicación (pañol/sala/estante), fechas de mantención, préstamos, mantenciones e informes (`UnitLifeSheetTest`).
- [x] La ficha del producto (`/equipment/{id}/technical-sheet`) incluye la tabla de sus unidades (`UnitLifeSheetTest`).
