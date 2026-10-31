
### REQ-08 — Estado de compra (FU-03, DIR-01)
- [x] Máquina de estados para `Quotation`/`Purchase`: `pendiente` → `en_camino` → `completa`.
- [x] Endpoint para actualizar estado a `completa` al escanear la guía de despacho/factura de llegada.
- [x] Endpoint `GET /api/purchases` con filtro por estado.

### REQ-16 — Presupuesto anual por carrera (FU-03, DIR-01 y AD-01)
Modelo `AnnualBudget` (`TASKS/Equipos.md`, fase 4).
- [ ] `GET /api/budgets`: filtros `year`, `career_id`. `AD-01` ve todos; `DIR-01` solo los de sus carreras (`career_user`).
- [ ] `GET /api/budgets/{annualBudget}`: detalle con lo consumido y la lista de ingresos confirmados (REQ-17).
- [ ] `POST /api/budgets` (`AD-01`): `career_id`, `year`, `initial_amount` (> 0); `available_amount` arranca igual a `initial_amount`. `422` si ya existe esa carrera + año.
- [ ] `PATCH /api/budgets/{annualBudget}` (`AD-01`): cambiar `initial_amount`; `available_amount` se recalcula como `initial_amount − consumido`. `422` si quedaría negativo. `available_amount` nunca se edita directo.
- [ ] `DELETE /api/budgets/{annualBudget}` (`AD-01`): `204`; `422` si tiene ingresos.
- [ ] `AnnualBudgetPolicy`, `AnnualBudgetResource` (`ResourceContractTest`), `ENDPOINTS.md`, `front-end.md`.
- [ ] Test Feature `AnnualBudgetTest`: caso feliz, `403` (PAN-01, PRO-01; DIR-01 creando), `422` (duplicado, monto negativo, recálculo bajo cero, borrar con ingresos), DIR-01 no ve carreras ajenas.

### REQ-17 — Ingreso de facturas con descuento de presupuesto (FU-03, DIR-01 y AD-01)
Modelos `InvoiceIntake` e `InvoiceIntakeItem` (`TASKS/Equipos.md`, fase 4). Extiende REQ-03: la factura queda guardada, descuenta presupuesto e ingresa el stock.
- [ ] `POST /api/invoice-intakes` (`multipart`, `throttle:sensitive`): `file` (PDF/imagen, 10 MB) y `annual_budget_id`. Guarda el archivo en disco privado, corre `InvoiceOcrService` y crea el ingreso en `borrador` con `raw_ocr_payload`, `document_number` y el borrador de ítems detectados (dentro del payload: los ítems definitivos necesitan `product_id`, que el OCR no conoce). `201`.
- [ ] `GET /api/invoice-intakes` (filtros `status`, `annual_budget_id`, `from`/`to`) y `GET /api/invoice-intakes/{invoiceIntake}`.
- [ ] `GET /api/invoice-intakes/{invoiceIntake}/file`: descarga autenticada del archivo.
- [ ] `POST /api/invoice-intakes/{invoiceIntake}/confirm`: `document_number`, `items: [{product_id, quantity, unit_price, detected_name?, cajon_id?}]`. En una transacción: bloquea el presupuesto, calcula `subtotal` y `total_deducted`, `422` si supera `available_amount`, descuenta, crea los `invoice_intake_items` y **ingresa el stock**: `insumo` suma `quantity` (y evalúa alertas); `equipo`/`maqueta` crea `quantity` unidades con `cajon_id` obligatorio (`EquipmentUnitService::createUnits`). Estado `confirmado`. Solo desde `borrador`.
- [ ] `POST /api/invoice-intakes/{invoiceIntake}/cancel`: `borrador` → `anulado`. Un ingreso confirmado no se anula (se corrige con un ajuste manual de stock).
- [ ] DIR-01 solo opera sobre presupuestos de sus carreras.
- [ ] `POST /api/invoices/scan` se mantiene hasta que el frontend migre; anotarlo en `front-end.md`.
- [ ] `InvoiceIntakeResource`, `InvoiceIntakeItemResource` (`ResourceContractTest`), `ENDPOINTS.md`.
- [ ] Test Feature `InvoiceIntakeTest` (`OCR_DRIVER=local` + `Process::fake`): subida, confirmación que descuenta presupuesto y suma stock / crea unidades, `422` por presupuesto insuficiente, confirmar dos veces, producto inexistente, unidad sin `cajon_id`; `403` por rol y por carrera ajena; descarga del archivo.
