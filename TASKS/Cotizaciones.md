### REQ-07 — Envío de cotizaciones automáticas (FU-03, DIR-01)
- [x] Endpoint `POST /api/quotations` que reciba lista de productos + cantidades.
- [x] Servicio de envío de correo automático a **al menos 1 proveedor** por cotización.
- [x] Persistir cada cotización con su estado y proveedores contactados.
- [x] Validación: mínimo 1 producto, cantidad positiva, mínimo 1 proveedor.
- [x] Registro por proveedor de si el correo salió (`email_sent_at`) o por qué falló (`email_error`), y `POST /api/quotations/{id}/resend` para reintentar solo los pendientes.

### Reglas de noviembre 2026
- [x] Solo `DIR-01` cambia el estado de una cotización (`AD-01` → `403`); cada cambio de estado notifica a `AD-01` y `DIR-01` (`QuotationPurchaseFlowTest`).
- [x] Mínimo recomendado de 3 proveedores que **responden** (`config('sgia.min_quotations')`, `MIN_QUOTATIONS`): no bloquea, la compra exige `quotation_justification` si hay menos (`QuotationPurchaseFlowTest`).
