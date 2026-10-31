### REQ-09 — Solicitud de préstamo remoto (FU-04, PRO-01)
- [x] Endpoint `POST /api/loans/requests` (insumo/producto, cantidad, asignatura, sala, fecha).
- [x] Endpoint `GET /api/loans/requests?estado=en_proceso` para listar solicitudes propias del docente.
- [x] Respuesta debe confirmar "solicitud enviada" y devolver el registro creado.


### REQ-10 — Procesamiento de préstamos presenciales/remotos (FU-04, PAN-01, DIR-01 y AD-01)
- [x] Endpoint `GET /api/loans/pending` (incluye cantidad solicitada, disponible y ubicación).
- [x] Endpoint `POST /api/loans/{id}/approve` → descuenta stock y notifica al solicitante.
- [x] Endpoint `POST /api/loans/{id}/reject` (requiere motivo) → notifica al solicitante.
- [x] Endpoint para registrar préstamo presencial directo (profesor/estudiante, insumo, cantidad, asignatura, sala, fecha).

### REQ-11 — Historial y listado de préstamos (FU-04, PAN-01, DIR-01 y AD-01)
- [x] Endpoint `GET /api/loans` con filtros (insumo, profesor, sala, estado).
- [x] Diferenciar en la respuesta préstamos `procesados` vs `en_proceso` (campo de estado explícito para que el cliente aplique el estilo visual).

### Devoluciones y vencimiento (FU-04, PAN-01 y AD-01)
- [x] `due_date` por préstamo (por defecto, el día del préstamo; no puede ser anterior a `loan_date`).
- [x] `POST /api/loans/checkin`: devolución total o parcial por ítem, con condición `bueno`/`regular` (reintegra stock), `dañado` (a taller) o `extraviado` (a baja).
- [x] Campos `due_date`, `returned_at`, `is_returned` e `is_overdue` en el préstamo y filtros `atrasado` / `devuelto` en `GET /api/loans`.
- [x] `DELETE /api/loans/requests/{id}`: el docente cancela su solicitud mientras está pendiente.
- [x] `GET /api/loans/export`: historial en CSV (Excel) o PDF por rango de fechas.
- [ ] Notificar al docente cuando su préstamo vence o está atrasado (sin implementar).

### REQ-20 — Registro de estudiantes (FU-04, PAN-01 y AD-01)
Modelo `Student` (`TASKS/Equipos.md`, fase 5).
- [ ] `GET /api/students`: filtros `search` (RUT o nombre), `career_id`.
- [ ] `GET /api/students/by-rut/{rut}`: búsqueda exacta para el checkout (acepta RUT con o sin puntos y guion). `404` si no existe.
- [ ] `POST /api/students`: `rut` (regla `ChileanRut`, se guarda normalizado `12345678-9`, único), `full_name?`, `email?`, `career_id?`.
- [ ] `PATCH /api/students/{student}`: mismos campos.
- [ ] `DELETE /api/students/{student}` (`AD-01`): `204`; `422` si tiene préstamos.
- [ ] `GET /api/students/{student}/loans`: historial de préstamos del estudiante.
- [ ] `POST /api/loans/checkout` acepta `student_id` o `student_rut`: liga `loans.student_id` y completa `borrower_name`/`borrower_document` desde el estudiante. Si el RUT no existe y viene `borrower_name`, crea el estudiante.
- [ ] `StudentResource` (`ResourceContractTest`), `LoanResource` añade `student_id` y `student`; `ENDPOINTS.md`, `front-end.md`.
- [ ] Test Feature `StudentTest`: caso feliz, `403` (PRO-01, DIR-01), `422` (RUT inválido, duplicado, borrar con préstamos), búsqueda por RUT con formatos distintos, checkout con `student_rut` existente y nuevo.

### Préstamos con unidades (plan `Equipos.md`, fase 7)
- [x] `checkout` y `approve` asignan unidades (`unit_ids` o automáticas) y las pasan a `prestado`; stock insuficiente → `422` (`LoanUnitsTest`).
- [x] `deliver` admite `items: [{item_id, unit_ids}]` para reemplazar la asignación (`LoanUnitsTest`).
- [x] `checkin` devuelve todas las unidades pendientes o `unit_ids` concretas; `bueno`/`regular` → `disponible`, `danado` → `en_reparacion`, `extraviado` → `dado_de_baja` (`LoanUnitsTest`).
- [x] `LoanItemResource`: `units`, `delivered_quantity`, `returned_quantity`; export CSV/PDF con los códigos (`LoanUnitsTest`, `ResourceContractTest`).
- [x] El checkout con RUT válido en `borrower_document` crea o reutiliza el `Student` (`LoanUnitsTest`).
