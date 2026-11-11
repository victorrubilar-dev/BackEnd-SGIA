### REQ-07 — Envío de cotizaciones automáticas (FU-03, DIR-01) — **retirado** (Fase 7: solo lectura)
> Historial: lo de abajo se implementó y luego se retiró. Hoy solo quedan `GET /api/quotations` y `GET /api/quotations/{id}` (`QuotationTest`); tablas `quotation*` y datos conservados.
- [x] Endpoint `POST /api/quotations` que reciba lista de productos + cantidades.
- [x] Servicio de envío de correo automático a **al menos 1 proveedor** por cotización.
- [x] Persistir cada cotización con su estado y proveedores contactados.
- [x] Validación: mínimo 1 producto, cantidad positiva, mínimo 1 proveedor.
- [x] Registro por proveedor de si el correo salió (`email_sent_at`) o por qué falló (`email_error`), y `POST /api/quotations/{id}/resend` para reintentar solo los pendientes.

### Reglas de noviembre 2026
- [x] ~~Solo `DIR-01` cambia el estado de una cotización~~ (retirado en la Fase 7 junto con `PATCH /api/quotations/{id}/status`).
- [x] ~~Mínimo recomendado de 3 proveedores que responden~~ (retirado con `POST /api/purchases`; en el flujo nuevo el mínimo de 3 cotizaciones subidas sí bloquea la elección).

---

### Refactor nov 2026 — solicitud de compra (reemplaza REQ-07)
`POST /api/quotations` y el envío por proveedor elegido a mano **se retiraron** (Fase 7). El envío pasa a la solicitud de compra, por áreas de proveedor, y las cotizaciones son archivos que sube `DIR-01` (mínimo 3). Plan por fases, modelo y contrato en `NUEVO-FLUJO-COMPRAS.md`.
- [x] Fase 1: `supplier_areas` (informatica, diseno, telecomunicaciones, ciberseguridad), ≥1 obligatoria por proveedor, sin tope; los proveedores de prueba actuales se actualizan a mano.
- [x] Fase 2: `purchase_requests` con código `SC-AAAA-NNNN`, `POST …/approve` solo `DIR-01`.
- [x] Fase 3: `POST …/send` con `areas[]`; reutiliza `QuotationEmailService`.
- [x] Fase 4: `POST …/quotations` (archivo + total + precios), `POST …/select-quotation` (≥3).
  Tests: `SupplierAreaTest`, `PurchaseRequestTest`, `PurchaseRequestSendTest`, `PurchaseRequestQuotationTest`. 
- [x] Fase 7: escrituras retiradas (`POST /api/quotations` → `405`; `POST …/resend`, `PATCH …/status`, `PATCH …/responses` → `404`); historial en solo lectura con `allowed_transitions: []` (`QuotationTest`).
