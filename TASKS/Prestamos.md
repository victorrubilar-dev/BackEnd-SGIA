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
