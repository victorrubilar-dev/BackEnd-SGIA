# Nuevo flujo de compras — plan de desarrollo

Origen: reunión de noviembre 2026. Reemplaza el flujo "Cotización → OC → Factura" descrito en `CLAUDE.md` y `TASK.md`.
Estado: **plan, sin código todavía**. Al cerrar cada fase, marcar aquí y en `TASK.md` §10.

## 1. Flujo acordado

```
DIR-01 crea solicitud (productos + cantidades, código SC-AAAA-NNNN)   [borrador]
   │  botón "Aprobar" (DIR-01)                                        [aprobada]
   ▼
DIR-01 elige ÁREAS (≥1) → correo a todos los proveedores activos     [enviada]
de esas áreas (un proveedor en 2 áreas recibe 1 solo correo)
   │  los proveedores responden por correo (fuera del sistema)
   ▼
DIR-01 sube el archivo de cada cotización recibida a la solicitud
   (proveedor + monto + archivo). Mínimo 3.                           [cotizada]
   │  DIR-01 elige 1 de las ≥3 cotizaciones
   ▼
OC emitida FUERA del sistema → DIR-01 sube el archivo de la OC       [con_oc]
   asociada al código de la solicitud (reserva presupuesto)
   │  llegan los productos (la recepción es física, sin paso en el sistema)
   ▼
Se sube la factura asociada a la solicitud/OC; OCR + ingreso de
stock como hoy; consume presupuesto. La factura confirmada
equivale a la recepción                                              [facturada]
```

`cancelada` es alcanzable desde `borrador`, `aprobada`, `enviada`, `cotizada`, `con_oc` (libera la reserva).

## 2. Decisiones cerradas

| Tema | Decisión |
|---|---|
| Solicitud | **Entidad nueva** `purchase_requests`. Registra productos, cantidades, envíos a proveedores y las cotizaciones (archivos) recibidas. |
| Aprobación | La aprueba **DIR-01 con un botón**, **antes** de enviar correos. Solo `DIR-01` (igual que hoy con el estado de cotización; `AD-01` → 403). |
| Áreas de proveedor | Lista fija: `informatica`, `diseno`, `telecomunicaciones`, `ciberseguridad`. Todo proveedor tiene **al menos 1** (obligatorio al crear/editar, `areas` con `min:1`) y puede tener **más, sin límite**. |
| Envío | Se eligen áreas al enviar; se contactan **todos los proveedores activos** de esas áreas (puede ser más de un área). |
| Mínimo | Se exigen **≥3 cotizaciones** subidas para poder elegir. Sin justificación alternativa (se elimina `quotation_justification` en el flujo nuevo). |
| Cotizaciones | **Nunca entran solas al sistema**: el director sube a mano las que considere pertinentes y **elige libremente** con cuál quedarse (no tiene que ser la más barata; no se pide motivo). |
| OC | Se emite **fuera del sistema**; el sistema solo recibe el archivo y lo liga al código de la solicitud. |
| Factura | Se sube asociada a la misma solicitud u OC; OCR, matching de productos e ingreso de stock **sin cambios**. |
| Código | `SC-{año}-{NNNN}` vía `CodeSequenceService` (ya existe). |

## 3. Supuestos que asumo salvo que digas lo contrario

1. **Monto de la cotización**: el director lo digita al subir cada archivo (`total`), más `unit_price` por ítem de la solicitud. No hay OCR de cotizaciones. El `total` de la cotización elegida es lo que se **reserva** al subir la OC; la OC usa esos `unit_price` para los `purchase_items`.
2. **Presupuesto**: la solicitud lleva `annual_budget_id` (carrera + año) desde la creación; se valida disponibilidad **al subir la OC**, no antes.
3. **Qué pasa con `Quotation` actual**: se deja de crear (`POST /api/quotations` → deprecado y luego eliminado). Las tablas e historial existentes se conservan solo lectura hasta que se confirme su eliminación. `POST /api/purchases` desde cotizaciones deja de usarse.
4. **Recepción**: **sin estado propio** (decidido). La factura confirmada implica que llegaron los productos, como hoy: ingresa stock y deja la OC `completa`. `arrival-scan` no cambia el estado de la solicitud.
5. **Correos**: se reutiliza `QuotationEmailService` (con `email_sent_at`/`email_error` y reintento) pasando a operar por `purchase_request_suppliers`.

## 4. Modelo de datos

Nuevas tablas (migraciones):

| Tabla | Campos clave |
|---|---|
| `supplier_areas` | `supplier_id` FK, `area` (enum de 4), `unique(supplier_id, area)`. Migra `suppliers.category` (texto libre) por mapeo manual en la migración; el campo se mantiene nullable un release y luego se elimina. |
| `purchase_requests` | `id`, `code` unique, `status`, `annual_budget_id` FK, `created_by` FK, `approved_by`/`approved_at`, `sent_at`, `selected_quotation_id` nullable, `notes`, timestamps. |
| `purchase_request_items` | `purchase_request_id`, `product_id`, `quantity` (>0). |
| `purchase_request_areas` | `purchase_request_id`, `area` (trazabilidad de a qué áreas se envió). |
| `purchase_request_suppliers` | `purchase_request_id`, `supplier_id`, `email_sent_at`, `email_error` (reemplaza a `quotation_suppliers`). |
| `purchase_request_quotations` | `purchase_request_id`, `supplier_id`, `file_path` (disco privado), `total`, `received_at`, `uploaded_by`, `unique(purchase_request_id, supplier_id)`. |
| `purchase_request_quotation_items` | `quotation_id`, `purchase_request_item_id`, `unit_price`. |

Cambios a tablas existentes:
- `purchases`: `purchase_request_id` FK nullable (históricas), `order_file_path` (archivo de la OC), `quotation_id` actual queda para histórico.
- `invoice_intakes`: ya tiene `purchase_id`; la solicitud se obtiene por la OC. Sin columna nueva.

Máquina de estados nueva `PurchaseRequestStateMachine` (como `QuotationStateMachine`): `borrador → aprobada → enviada → cotizada → con_oc → facturada`, más `cancelada`. Estados finales: `facturada`, `cancelada`.

## 5. API (contrato; el backend manda)

Todas bajo `auth:sanctum`. Lectura: `AD-01`, `DIR-01`, `PAN-01`. Escritura: `DIR-01` salvo indicación.

| Método | Ruta | Qué hace |
|---|---|---|
| `GET` | `/api/purchase-requests` | Listado; filtros `status`, `code`, `from`/`to`. |
| `POST` | `/api/purchase-requests` | Crea en `borrador`: `items[{product_id, quantity}]`, `annual_budget_id`, `notes`. Genera `code`. |
| `GET` | `/api/purchase-requests/{id}` | Detalle: ítems, áreas, proveedores contactados, cotizaciones, OC, factura. |
| `PATCH` | `/api/purchase-requests/{id}` | Edita ítems/notas solo en `borrador`. |
| `POST` | `/api/purchase-requests/{id}/approve` | `borrador → aprobada` (solo `DIR-01`). Notifica a `AD-01`. |
| `POST` | `/api/purchase-requests/{id}/send` | Body `areas[]` (≥1). Resuelve proveedores activos de esas áreas, envía correos. `422` si no hay proveedores. `aprobada → enviada`. |
| `POST` | `/api/purchase-requests/{id}/resend` | Reintenta solo correos fallidos. |
| `POST` | `/api/purchase-requests/{id}/quotations` | `multipart`: `supplier_id` (debe estar entre los contactados), `file`, `total`, `items[{purchase_request_item_id, unit_price}]`. Pasa a `cotizada` al llegar a 3. |
| `GET` | `/api/purchase-requests/{id}/quotations/{q}/file` | Descarga autenticada (`getBlob()`). |
| `DELETE` | `/api/purchase-requests/{id}/quotations/{q}` | Quita una cotización mal subida (antes de elegir). |
| `POST` | `/api/purchase-requests/{id}/select-quotation` | `quotation_id`; `422` si hay menos de 3 subidas. |
| `POST` | `/api/purchase-requests/{id}/order` | `multipart`: `file` de la OC + `order_number`. Exige cotización elegida. Crea la `Purchase`, **reserva** el `total`, `→ con_oc`. `422` por presupuesto insuficiente. |
| `POST` | `/api/purchase-requests/{id}/cancel` | Cancela; libera la reserva si ya había OC. |
| `GET` | `/api/purchase-requests/{id}/order/file` | Descarga de la OC. |
| `PUT` | `/api/suppliers/{id}` | Acepta `areas[]` (valores del enum). `GET /api/suppliers` filtra por `area`. |
| `GET` | `/api/purchase-requests/areas` | Enum de áreas para selects. |

Factura: se mantiene `invoice-intakes` (`purchase_id`) y `POST /api/purchases/{id}/arrival-scan` sin tocar; además `POST /api/invoice-intakes` acepta `purchase_request_code` como atajo para ligar a la OC de esa solicitud. Al confirmar la factura la solicitud pasa a `facturada`.

Rompe contrato: `POST /api/quotations` y `POST /api/purchases` con `quotation_id`/`quotation_ids`. Se mantienen **deprecados** una versión (responden con cabecera `Deprecation`) y se retiran cuando el frontend migre.

## 6. Preguntas abiertas (responder antes de la fase indicada)

- ~~Recepción como estado propio~~ → resuelto: no; la factura implica recepción (como hoy).
- ~~Elegir cotización no más barata~~ → resuelto: elección libre.
- ~~Proveedores sin área~~ → resuelto: área obligatoria; los datos de prueba se actualizan después (Fase 1).

## 7. Plan de desarrollo por fases

Cada fase deja `php artisan test` en verde y `pint --dirty` limpio (backend) y typecheck/lint/vitest (frontend). Backend y frontend en paralelo con este contrato.

### Fase 1 — Áreas de proveedor (backend → frontend)
- [ ] Migración `supplier_areas`, enum `SupplierArea`, `Supplier::areas()`.
- [ ] `StoreSupplierRequest`/`UpdateSupplierRequest`/`SupplierResource`: `areas[]`; filtro `area` en el index.
- [ ] Seeders (`RealisticDemoSeeder`, `InvoiceDemoSeeder`): proveedores con áreas.
- [ ] **Pendiente de datos (anotado, no se automatiza):** los proveedores actuales son solo de prueba y no tienen área; hay que actualizarlos a mano o reemplazarlos. La migración no los borra ni inventa áreas; como `areas` pasa a ser obligatoria, editar un proveedor viejo exigirá asignarle al menos 1.
- [ ] Test `SupplierAreaTest`. Docs: `ENDPOINTS.md`, `TASKS/front-end.md`.
- [ ] Front: `packages/types` + `api-client`; multiselect de áreas en el formulario de proveedores y columna/filtro en el listado.

### Fase 2 — Solicitud de compra: crear y aprobar
- [ ] Migraciones `purchase_requests`, `_items`; modelos, `PurchaseRequestStateMachine`, `PurchaseRequestService`, Policy, Resource, `StorePurchaseRequestRequest`.
- [ ] Código `SC-AAAA-NNNN` con `CodeSequenceService`.
- [ ] `GET/POST/PATCH /purchase-requests`, `POST …/approve` (solo `DIR-01`, notifica `AD-01`), `POST …/cancel`.
- [ ] Tests `PurchaseRequestTest`: crea con código, `403` por rol, edita solo en borrador, aprobar, `AD-01` → 403.
- [ ] Front: lista, formulario (productos + cantidades + presupuesto), detalle con botón **Aprobar**.

### Fase 3 — Envío por áreas
- [ ] Migraciones `purchase_request_areas`, `purchase_request_suppliers`; adaptar `QuotationEmailService`.
- [ ] `POST …/send` (`areas[]`), `POST …/resend`; dedupe de proveedores en varias áreas; `422` sin proveedores o sin aprobar.
- [ ] Tests con `Mail::fake`: una y varias áreas, proveedor duplicado recibe 1 correo, inactivos excluidos, reintento.
- [ ] Front: selector de áreas al enviar y vista de proveedores contactados con estado del correo.

### Fase 4 — Cotizaciones recibidas y elección
- [ ] Migraciones `purchase_request_quotations` + `_items`; subida a disco privado; descarga autenticada.
- [ ] `POST/DELETE …/quotations`, `POST …/select-quotation` (mínimo 3 subidas; elección libre, sin motivo).
- [ ] Tests: subir 1 y 2 → no se puede elegir; con 3 → elige cualquiera (no solo la más barata); proveedor no contactado → `422`; duplicado por proveedor → `422`; archivo inválido.
- [ ] Front: panel de cotizaciones (subir, ver archivo, comparador de totales, botón "Elegir").

### Fase 5 — Orden de compra
- [ ] Migración `purchases.purchase_request_id`, `order_file_path`.
- [ ] `POST …/order`: crea `Purchase` (ítems con `unit_price` de la cotización elegida), reserva presupuesto vía `AnnualBudgetService::reserve`, guarda el archivo de la OC. `GET …/order/file`.
- [ ] Cancelar con OC libera la reserva (reusar `PurchaseService::cancel`).
- [ ] Tests `PurchaseRequestOrderTest`: reserva el `total`, `422` por fondos, requiere cotización elegida.
- [ ] Front: subir OC y ver su archivo.

### Fase 6 — Factura ligada a la solicitud
- [ ] `invoice-intakes` acepta `purchase_request_code`; al confirmar: libera reserva, consume, solicitud `facturada` (lógica actual de REQ-22 sin cambios).
- [ ] Anular factura confirmada: solicitud vuelve a `con_oc`.
- [ ] Auditoría (`/purchases/{id}/trace`) incluye el código y las cotizaciones.
- [ ] Tests: ampliar `InvoiceIntakeTest` y `InvoiceIntakeCancellationTest`.
- [ ] Front: en el formulario de factura, buscar por código de solicitud.

### Fase 7 — Retiro del flujo antiguo y cierre
- [ ] Deprecar `POST /api/quotations` y `POST /api/purchases` desde cotizaciones; quitar las pantallas de cotizaciones del frontend; decidir si se borran las tablas `quotation*`.
- [ ] Actualizar `RealisticDemoSeeder`/`InvoiceDemoSeeder` al flujo nuevo (solicitudes en distintos estados; la factura 1 sigue sin procesar).
- [ ] Actualizar `ENDPOINTS.md` (ambos repos), `CONTRATO.md`, `CLAUDE.md` (sección "Flujo de negocio") y `TASKS/Compra.md`.
- [ ] Prueba de punta a punta por API y en el navegador (solicitud → aprobar → enviar → 3 cotizaciones → OC → factura).

## 8. Riesgos

- **Migración de `suppliers.category`** (texto libre) a áreas: mapeo imperfecto; revisar a mano.
- **Compras históricas** quedan sin solicitud (`purchase_request_id` null); toda pantalla nueva debe tolerarlo.
- **Presupuesto**: el `total` digitado a mano puede diferir de la OC real; la diferencia se resuelve al facturar (libera la reserva, consume lo facturado), como hoy.
- **Roles**: `DIR-01` crea, aprueba, elige y sube la OC (sin control cruzado, decisión de la reunión); todo queda en `audit_logs`.
