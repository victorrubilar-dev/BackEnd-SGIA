
### REQ-08 — Estado de compra (FU-03, DIR-01)
- [x] Máquina de estados para `Quotation`/`Purchase`: `pendiente` → `en_camino` → `completa`.
- [x] Endpoint para actualizar estado a `completa` al escanear la guía de despacho/factura de llegada.
- [x] Endpoint `GET /api/purchases` con filtro por estado.

### REQ-16 — Presupuesto anual por carrera (FU-03, DIR-01 y AD-01)
Modelo `AnnualBudget` (`TASKS/Equipos.md`, fase 4).
- [x] `GET /api/budgets`: filtros `year`, `career_id`. `AD-01` y `DIR-01` ven todas las carreras (DIR-01 es director del Área de Informática).
- [x] `GET /api/budgets/{annualBudget}`: detalle con lo consumido y la lista de ingresos confirmados (REQ-17).
- [x] `POST /api/budgets` (`AD-01` y `DIR-01`, REQ-24): `career_id`, `year`, `initial_amount` (> 0); `available_amount` arranca igual a `initial_amount`. `422` si ya existe esa carrera + año.
- [x] `PATCH /api/budgets/{annualBudget}` (`AD-01` y `DIR-01`, REQ-24): cambiar `initial_amount`; `available_amount` se recalcula como `initial_amount − consumido`. `422` si quedaría negativo. `available_amount` nunca se edita directo.
- [x] `DELETE /api/budgets/{annualBudget}` (`AD-01` y `DIR-01`, REQ-24): `204`; `422` si tiene ingresos.
- [x] `AnnualBudgetPolicy`, `AnnualBudgetResource` (`ResourceContractTest`), `ENDPOINTS.md`, `front-end.md`.
- [x] Test Feature `AnnualBudgetTest`: caso feliz, `403` (PAN-01, PRO-01), `422` (duplicado, monto negativo, recálculo bajo cero, borrar con ingresos), DIR-01 sin carreras asignadas gestiona y ve todas.

### REQ-17 — Ingreso de facturas con descuento de presupuesto (FU-03, DIR-01 y AD-01)
Modelos `InvoiceIntake` e `InvoiceIntakeItem` (`TASKS/Equipos.md`, fase 4). Extiende REQ-03: la factura queda guardada, descuenta presupuesto e ingresa el stock.
- [x] `POST /api/invoice-intakes` (`multipart`, `throttle:sensitive`): `file` (PDF/imagen, 10 MB) y `annual_budget_id`. Guarda el archivo en disco privado, corre `InvoiceOcrService` y crea el ingreso en `borrador` con `raw_ocr_payload`, `document_number` y el borrador de ítems detectados (dentro del payload: los ítems definitivos necesitan `product_id`, que el OCR no conoce). `201`.
- [x] `GET /api/invoice-intakes` (filtros `status`, `annual_budget_id`, `from`/`to`) y `GET /api/invoice-intakes/{invoiceIntake}`.
- [x] `GET /api/invoice-intakes/{invoiceIntake}/file`: descarga autenticada del archivo.
- [x] `POST /api/invoice-intakes/{invoiceIntake}/confirm`: `document_number`, `items: [{product_id, quantity, unit_price, detected_name?, cajon_id?}]`. En una transacción: bloquea el presupuesto, calcula `subtotal` y `total_deducted`, `422` si supera `available_amount`, descuenta, crea los `invoice_intake_items` y **ingresa el stock**: `insumo` suma `quantity` (y evalúa alertas); `equipo`/`maqueta` crea `quantity` unidades con `cajon_id` obligatorio (`EquipmentUnitService::createUnits`). Estado `confirmado`. Solo desde `borrador`.
- [x] `POST /api/invoice-intakes/{invoiceIntake}/cancel`: `borrador` → `anulado`. Un ingreso confirmado también se anula con reversa de stock y reposición (REQ-23).
- [x] DIR-01 es director de área: opera sobre los presupuestos de todas las carreras del Área de Informática (ISD, II, IC, AP), sin depender de `career_user`. La regla vive en `User::managesBudgets()`.
- [x] `POST /api/invoices/scan` se mantiene hasta que el frontend migre; anotarlo en `front-end.md`.
- [x] `InvoiceIntakeResource`, `InvoiceIntakeItemResource` (`ResourceContractTest`), `ENDPOINTS.md`.
- [x] Test Feature `InvoiceIntakeTest` (`OCR_DRIVER=local` + `Process::fake`): subida, confirmación que descuenta presupuesto y suma stock / crea unidades, `422` por presupuesto insuficiente, confirmar dos veces, producto inexistente, unidad sin `cajon_id`; `403` por rol (DIR-01 opera sin carreras asignadas); descarga del archivo.

---

## Plan de presupuesto: reservas, anulación, roles y reportes (REQ-21 a REQ-24)

Orden de implementación: REQ-21 → REQ-22 → REQ-23 → REQ-24. Cada REQ deja `php artisan test` en verde y `pint --dirty` limpio.

**Decisiones cerradas (no re-discutir):**
- El presupuesto es **por carrera y por año** (`annual_budgets`, `unique(career_id, year)`) y se **ingresa siempre a mano** (`POST /api/budgets`). No hay carga automática ni importación.
- Invariante: `available_amount = initial_amount − consumed − reserved`. Nunca negativo (CHECK existente). `reserved_amount` y `consumed_amount` se derivan/escriben **solo** desde `AnnualBudgetService`.
- Todo cambio de montos genera una fila en `budget_movements` (ledger), dentro de la misma transacción y con el presupuesto bloqueado (`lockForUpdate`).
- Montos en centavos internamente (`AnnualBudgetService::cents()`), strings con 2 decimales en la API.
- Sin fondos → `422` (error en `total` o `items`), sin excepción ni `force`.

### REQ-21 — Ledger y reserva de presupuesto (FU-03, AD-01 y DIR-01)
Base técnica de los REQ siguientes.
- [x] Migración `annual_budgets.reserved_amount` (decimal 15,2, default 0, `CHECK (reserved_amount >= 0)`) y `CHECK (initial_amount - reserved_amount >= 0)`. Los presupuestos existentes quedan con `reserved_amount = 0`.
- [x] Migración `budget_movements`: `id`, `annual_budget_id` (FK `restrictOnDelete`), `type` (`asignacion_inicial` | `ajuste` | `reserva` | `liberacion_reserva` | `consumo` | `reposicion`), `amount` (decimal 15,2, siempre positivo; el signo lo da el tipo), `purchase_id` (FK nullable), `invoice_intake_id` (FK nullable), `user_id` (FK), `notes` (nullable), `created_at`. Sin `updated_at`: el ledger es inmutable. Modelo `BudgetMovement` sin endpoints de escritura.
- [x] `AnnualBudgetService` (única vía de escritura de montos): `reserve(AnnualBudget, amount, Purchase, userId)`, `releaseReservation(...)`, `consume(...)`, `restore(...)`. Cada método bloquea el presupuesto, valida, actualiza `available_amount`/`reserved_amount` y escribe el movimiento.
- [x] `POST /api/budgets` registra el movimiento `asignacion_inicial`. `PATCH` registra `ajuste` (monto = diferencia absoluta; nota con el valor anterior y el nuevo). `updateInitialAmount` pasa a calcular `consumed = initial − available − reserved` y rechaza con `422` si `initial_amount < consumed + reserved`.
- [x] `AnnualBudgetResource`: agregar `reserved_amount` (y mantener `consumed_amount`, ahora sin incluir lo reservado). Actualizar `ResourceContractTest`, `ENDPOINTS.md`, `front-end.md`.
- [x] Test Unit/Feature: reservar, liberar, consumir y reponer dejan el invariante; `422` al reservar más que `available_amount`; dos reservas concurrentes no sobregiran; `PATCH` bajo `consumed + reserved` da `422`; cada operación deja su movimiento.

### REQ-22 — Compras con reserva de presupuesto (FU-03, DIR-01 y AD-01)
Crear la compra equivale a aprobarla.
- [x] Migración `purchases.annual_budget_id` (FK `restrictOnDelete`, **nullable solo para filas históricas**) y `invoice_intakes.purchase_id` (FK nullable).
- [x] `POST /api/purchases`: `annual_budget_id` obligatorio y `unit_price` obligatorio en todos los ítems (`total` deja de ser nullable en compras nuevas). En la misma transacción de `PurchaseService::create` se reserva el `total`. `422` si supera `available_amount` (error en `total`). DIR-01 puede usar el presupuesto de cualquier carrera del área. Si la compra nace de una cotización, `unit_price` se toma de la oferta elegida (`quotation_offer_items`) cuando exista; si no, se exige en el body.
- [x] Nuevo estado `cancelada` en `PurchaseStateMachine`: `pendiente` → `cancelada` y `en_camino` → `cancelada`; `completa` y `cancelada` son finales. `POST /api/purchases/{purchase}/cancel` (AD-01; DIR-01 si la compra tiene presupuesto): libera la reserva (`releaseReservation`). `422` (`InvalidPurchaseStatusTransition`) desde `completa` o `cancelada`. Actualizar el filtro de estado de `PurchaseService::applyFilters` y su mensaje de error.
- [x] `POST /api/invoice-intakes` acepta `purchase_id` opcional; si viene, debe tener el mismo `annual_budget_id`. Al **confirmar** un ingreso con `purchase_id`: se libera la reserva de esa compra y se consume el monto real de la factura (`total_deducted`), en una sola transacción; la diferencia (reserva − real) vuelve a `available_amount`. `422` si el real supera `available_amount + reserva` liberada. Un ingreso sin `purchase_id` consume directo, como hoy.
- [x] `PurchaseResource`: `annual_budget_id`, `reserved_amount` (reserva vigente, `0` si cancelada o ya facturada). Actualizar `ResourceContractTest`, `ENDPOINTS.md`, `front-end.md` (rompe contrato: campos obligatorios nuevos en `POST /api/purchases`).
- [x] Test Feature `PurchaseBudgetTest`: crear compra reserva monto; `422` por fondos insuficientes; `422` por ítem sin `unit_price`; DIR-01 usa el presupuesto de otra carrera; PAN-01 y PRO-01 `403`; cancelar libera; cancelar una `completa` da `422`; confirmar factura ligada libera reserva y consume lo real (caso con diferencia); compra desde cotización aceptada.

### REQ-23 — Anulación de factura confirmada con reposición (FU-03, DIR-01 y AD-01)
Reemplaza la regla actual de REQ-17 "un ingreso confirmado no se anula".
- [x] `POST /api/invoice-intakes/{invoiceIntake}/cancel` acepta también `confirmado`. Todo o nada, en una transacción, con el presupuesto bloqueado: (1) `422` y sin cambios si algún `insumo` quedaría con `quantity < 0`, o si alguna unidad creada por la factura (`equipo`/`maqueta`) no está en estado `disponible` o ya tiene préstamos; (2) resta el stock de insumos (evalúa alertas); (3) da de baja las unidades creadas por el ingreso vía `EquipmentUnitService` (sin tocar contadores a mano, `syncProductCounters`); (4) repone el monto (`restore`, movimiento `reposicion`); (5) estado `anulado`.
- [x] Trazabilidad: `invoice_intake_items` debe permitir ubicar las unidades creadas (agregar `invoice_intake_item_id` a `equipment_units` o equivalente; migración con las unidades históricas en `null`, que se consideran **no revertibles** → `422` con mensaje claro).
- [x] Si la factura estaba ligada a una compra `completa`, la compra no cambia de estado; solo se repone el presupuesto.
- [x] Mensajes de `422` en español indicando producto/unidad que impide la anulación (clave `items` o `status`).
- [x] Actualizar `ENDPOINTS.md`, `front-end.md`, y la línea correspondiente de REQ-17 en este archivo.
- [x] Test Feature (ampliar `InvoiceIntakeTest`): anular confirmada repone presupuesto y resta stock de insumo; anula unidades; `422` si el insumo ya se consumió (stock insuficiente); `422` si una unidad está `prestado`; `422` con unidad histórica sin trazabilidad; segunda anulación `422`; `403` por rol; el ledger registra la `reposicion`.

### REQ-24 — Roles y reportes de ejecución presupuestaria (FU-03/FU-06, AD-01 y DIR-01)
- [x] **Roles:** `POST`, `PATCH` y `DELETE /api/budgets` pasan a `role:AD-01,DIR-01`. `AnnualBudgetPolicy`: AD-01 y DIR-01 crean/editan/borran para todas las carreras del área. Actualizar el bloque de rutas de REQ-16 y sus tests (`403` ahora es PAN-01 y PRO-01).
- [x] Controlador `BudgetReportController` bajo `/api/budgets/*`, rutas estáticas **antes** de `{annualBudget}`. Todos aceptan `year` y `career_id`; AD-01 y DIR-01 ven todas las carreras. Respuestas JSON con Resources.
- [x] `GET /api/budgets/summary`: por carrera/año → `initial_amount`, `reserved_amount`, `consumed_amount`, `available_amount`, `execution_percent` (`(consumed + reserved) / initial × 100`, `0` si `initial = 0`).
- [x] `GET /api/budgets/{annualBudget}/movements`: ledger paginado (`type`, `from`/`to`, `purchase_id`, `invoice_intake_id`), con `user`, `amount`, `type`, `notes`, `created_at`.
- [x] `GET /api/budgets/{annualBudget}/spending`: gasto consumido agrupado con `group_by=product|product_type|supplier` (`422` si el valor es otro). Fuente: `invoice_intake_items` de ingresos `confirmado` (excluye anulados); `supplier` solo para ingresos ligados a una compra.
- [x] `GET /api/budgets/{annualBudget}/monthly`: serie mensual (12 meses del año, meses sin movimiento en `0`) con `reserved`, `consumed` y `restored`, calculada desde el ledger.
- [x] `BudgetSummaryResource`, `BudgetMovementResource`, y respuestas de `spending`/`monthly` fijadas en `ResourceContractTest`; `ENDPOINTS.md`; `front-end.md`.
- [x] Test Feature `BudgetReportTest`: datos sembrados con compras, facturas, anulaciones; verifica cada número; `403` PAN-01/PRO-01; DIR-01 ve todas las carreras; `422` en `group_by` inválido; año sin datos devuelve ceros.

**Notas de implementación:** el test de "reservas concurrentes" de REQ-21 se cubre con reservas secuenciales + `lockForUpdate` y los `CHECK` de BD (no hay prueba con procesos paralelos). `GET /budgets/{id}/monthly` acepta un `year` opcional (año calendario del ledger; por defecto el del presupuesto). Tests: `BudgetLedgerTest`, `PurchaseBudgetTest`, `InvoiceIntakeCancellationTest`, `BudgetReportTest`.

### Reglas de noviembre 2026
- [x] Toda compra parte de una cotización aceptada (`quotation_id` obligatorio); con menos de 3 proveedores que respondieron se exige `quotation_justification` (`QuotationPurchaseFlowTest`).
- [x] Facturas: el OCR detecta la carrera (nombre o sigla) y asigna el presupuesto del año; si no la detecta, `confirm` exige `annual_budget_id` (`InvoiceIntakeTest`).
