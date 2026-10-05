### REQ-09 — Solicitud de préstamo remoto (FU-04, PRO-01)
- [x] Endpoint `POST /api/loans/requests` (insumo/producto, cantidad, asignatura, sala, fecha).
- [x] Endpoint `GET /api/loans/requests?estado=en_proceso` para listar solicitudes propias del docente.
- [x] Respuesta debe confirmar "solicitud enviada" y devolver el registro creado.


### REQ-10 — Procesamiento de préstamos presenciales/remotos (FU-04, PAN-01)
- [ ] Endpoint `GET /api/loans/pending` (incluye cantidad solicitada, disponible y ubicación).
- [ ] Endpoint `POST /api/loans/{id}/approve` → descuenta stock y notifica al solicitante.
- [ ] Endpoint `POST /api/loans/{id}/reject` (requiere motivo) → notifica al solicitante.
- [ ] Endpoint para registrar préstamo presencial directo (profesor/estudiante, insumo, cantidad, asignatura, sala, fecha).

### REQ-11 — Historial y listado de préstamos (FU-04, PAN-01)
- [ ] Endpoint `GET /api/loans` con filtros (insumo, profesor, sala, estado).
- [ ] Diferenciar en la respuesta préstamos `procesados` vs `en_proceso` (campo de estado explícito para que el cliente aplique el estilo visual).
