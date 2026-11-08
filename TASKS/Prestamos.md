### REQ-09 — Solicitud de préstamo remoto (FU-04, PRO-01)
- [x] Endpoint `POST /api/loans/requests` (insumo/producto, cantidad, asignatura, sala, fecha).
- [x] Endpoint `GET /api/loans/requests?estado=en_proceso` para listar solicitudes propias del docente.
- [x] Respuesta debe confirmar "solicitud enviada" y devolver el registro creado.


### REQ-10 — Procesamiento de préstamos presenciales/remotos (FU-04, PAN-01, DIR-01 y AD-01)
- [x] Endpoint `GET /api/loans/pending` (incluye cantidad solicitada, disponible y ubicación).
- [x] Endpoint `POST /api/loans/{id}/approve` → descuenta stock y notifica al solicitante.
- [x] Endpoint `POST /api/loans/{id}/reject` (requiere motivo) → notifica al solicitante.
- [x] Endpoint `GET /api/loans/{id}/review`: unidades propuestas por el sistema y disponibles del pañol, cada una con sala y cajón; insumos con la ubicación del producto; `shortage` y `can_approve` (`LoanReviewTest`, `ResourceContractTest`; `docs/revision-solicitud-prestamo.md`).
- [x] `POST /api/loans/{id}/approve` acepta `items: [{item_id, unit_ids}]` para que el pañol cambie unidades; los ítems no indicados se asignan solos, sin chocar con las elegidas; `422` por campo y sin cambios si falla (`LoanReviewTest`).
- [x] Endpoint para registrar préstamo presencial directo (profesor/estudiante, insumo, cantidad, asignatura, sala, fecha).
- [x] `PAN-01` solo revisa, aprueba, rechaza, entrega y registra devoluciones de préstamos de su pañol (`users.storeroom_id`, o el pañol por defecto): `403` si son de otro; `AD-01` no se limita (`LoanStoreroomAccessTest`).
- [x] `POST /api/loans/checkout`: `PAN-01` solo registra préstamos presenciales en su pañol (`403` si `storeroom_id` es de otro); `AD-01` en cualquiera (`LoanStoreroomAccessTest`, `LoanUnitsTest`).

### REQ-11 — Historial y listado de préstamos (FU-04, PAN-01, DIR-01 y AD-01)
- [x] Endpoint `GET /api/loans` con filtros (insumo, profesor, sala, estado).
- [x] Diferenciar en la respuesta préstamos `procesados` vs `en_proceso` (campo de estado explícito para que el cliente aplique el estilo visual).
- [x] `PAN-01` solo ve los préstamos de su pañol en `GET /api/loans`, `GET /api/loans/{id}`, `GET /api/loans/export` y `GET /api/students/{id}/loans`; `DIR-01` y `AD-01` ven todos (`LoanStoreroomAccessTest`).
- [x] `PAN-01` tampoco ve préstamos de otro pañol en `active_loan` de `GET /api/equipment-units/{id}` ni en el historial de la hoja de vida de la unidad (`LoanStoreroomAccessTest`).

### Devoluciones y vencimiento (FU-04, PAN-01 y AD-01)
- [x] `due_date` por préstamo (por defecto, el día del préstamo; no puede ser anterior a `loan_date`).
- [x] `POST /api/loans/checkin`: devolución total o parcial por ítem, con condición `bueno`/`regular` (reintegra stock), `dañado` (a taller) o `extraviado` (a baja).
- [x] Campos `due_date`, `returned_at`, `is_returned` e `is_overdue` en el préstamo y filtros `atrasado` / `devuelto` en `GET /api/loans`.
- [x] `DELETE /api/loans/requests/{id}`: el docente cancela su solicitud mientras está pendiente.
- [x] `GET /api/loans/export`: historial en CSV (Excel) o PDF por rango de fechas.
- [x] Notificar al docente cuando su préstamo vence (el mismo día) o queda atrasado (una vez): `sgia:send-reminders`, diario 08:00 Chile, broadcast + correo + push (`ReminderTest`). Los préstamos presenciales sin docente con usuario no se avisan.

### REQ-20 — Registro de estudiantes (FU-04, PAN-01 y AD-01)
Modelo `Student` (`TASKS/Equipos.md`, fase 5).
- [x] `GET /api/students`: filtros `search` (RUT o nombre), `career_id`.
- [x] `GET /api/students/by-rut/{rut}`: búsqueda exacta para el checkout (acepta RUT con o sin puntos y guion). `404` si no existe.
- [x] `POST /api/students`: `rut` (regla `ChileanRut`, se guarda normalizado `12345678-9`, único), `full_name?`, `email?`, `career_id?`.
- [x] `PATCH /api/students/{student}`: mismos campos.
- [x] `DELETE /api/students/{student}` (`AD-01`): `204`; `422` si tiene préstamos.
- [x] `GET /api/students/{student}/loans`: historial de préstamos del estudiante.
- [x] `POST /api/loans/checkout` acepta `student_id` o `student_rut`: liga `loans.student_id` y completa `borrower_name`/`borrower_document` desde el estudiante. Si el RUT no existe y viene `borrower_name`, crea el estudiante.
- [x] `StudentResource` (`ResourceContractTest`), `LoanResource` añade `student_id` y `student`; `ENDPOINTS.md`, `front-end.md`.
- [x] Test Feature `StudentTest`: caso feliz, `403` (PRO-01, DIR-01), `422` (RUT inválido, duplicado, borrar con préstamos), búsqueda por RUT con formatos distintos, checkout con `student_rut` existente y nuevo.

### Préstamos con unidades (plan `Equipos.md`, fase 7)
- [x] `checkout` y `approve` asignan unidades (`unit_ids` o automáticas) y las pasan a `prestado`; stock insuficiente → `422` (`LoanUnitsTest`).
- [x] `deliver` admite `items: [{item_id, unit_ids}]` para reemplazar la asignación (`LoanUnitsTest`).
- [x] `checkin` devuelve todas las unidades pendientes o `unit_ids` concretas; `bueno`/`regular` → `disponible`, `danado` → `en_reparacion`, `extraviado` → `dado_de_baja` (`LoanUnitsTest`).
- [x] `LoanItemResource`: `units`, `delivered_quantity`, `returned_quantity`; export CSV/PDF con los códigos (`LoanUnitsTest`, `ResourceContractTest`).
- [x] El checkout con RUT válido en `borrower_document` crea o reutiliza el `Student` (`LoanUnitsTest`).
- [x] Solicitud, aprobación, presencial y entrega usan solo unidades del pañol del préstamo; `422` que nombra el pañol (`LoanUnitsTest`, `LoanRemoteRequestTest`).
- [x] `items[].units[]` trae `cajon` y `location` de cada unidad (`docs/ubicacion-unidades-prestamo.md`, `LoanUnitsTest`).

### Carrera en el préstamo (noviembre 2026)
- [x] `career_id` obligatorio al solicitar o registrar un préstamo; `LoanResource` muestra `career` junto a `subject`; filtro `career_id` en `GET /api/loans` (`LoanRequestTest`).

### Solicitud remota ampliada del profesor (REQ-21 del panel web; especificación en `docs/solicitud-prestamo-profesor.md`)
- [x] `POST /api/loans/requests` registra carrera (`career_id`, cualquiera), asignatura, fecha (hoy o futura), horario (`start_time`/`end_time`), laboratorio (`location_id`: sala o taller del pañol) y pañol (`storeroom_id`); el profesor sale solo del token (`LoanRemoteRequestTest`).
- [x] Cada regla responde `422` con `errors` por campo: carrera, hora de término, fecha pasada, laboratorio de otro pañol o de tipo pañol/bodega, ítems vacíos o repetidos, stock insuficiente (`LoanRemoteRequestTest`).
- [x] Retrocompatibilidad: clientes con solo `room` y `time_block` siguen funcionando; `time_block` y `room` se rellenan al crear (`LoanRemoteRequestTest`, `LoanRequestTest`).
- [x] `GET /api/loans/requests` filtra por `storeroom_id`, `career_id` y `loan_date`; el historial suma `storeroom_id`, `location_id` y `loan_date` (`LoanRemoteRequestTest`).
- [x] La cola `GET /api/loans/pending` de `PAN-01` muestra solo su pañol; `AD-01` ve todas (`LoanRemoteRequestTest`).
- [x] `GET /api/careers` de solo lectura para `PRO-01`; `GET /api/locations?storeroom_id=` (`LoanRemoteRequestTest`, `CareerManagementTest`).
- [x] `LoanResource` suma `storeroom`, `location`, `start_time`, `end_time` y `teacher` (`ResourceContractTest`).
- [ ] La disponibilidad no considera otras solicitudes ni el rango horario (solo el stock actual); queda documentado, sin reserva por horario.

### Disponibilidad por pañol en el panel del profesor (plan en `docs/disponibilidad-por-panol.md`)
El panel muestra `quantity` (todos los pañoles), pero la solicitud valida solo el pañol elegido: puede mostrar 8 existencias y rechazar con "hay 0 disponibles".
- [ ] `GET /api/products?storeroom_id=` suma `available_in_storeroom`: unidades `disponible` del pañol en equipos y maquetas, `quantity` en insumos; sin el parámetro, la respuesta no cambia.
- [ ] `storeroom_id` inválido o inexistente responde `422` con `errors.storeroom_id`.
- [ ] El listado y `assertItemsAvailable` comparten la misma regla: pedir más de lo informado da `422` y pedir esa cantidad exacta se acepta.
- [ ] El listado con `storeroom_id` no introduce N+1 (`ListQueryPerformanceTest`).
- [ ] `ProductStoreroomAvailabilityTest` y `ResourceContractTest` cubren el contrato.
- [ ] `ENDPOINTS.md`, `TASKS/front-end.md` y el documento del plan quedan actualizados como implementado.
