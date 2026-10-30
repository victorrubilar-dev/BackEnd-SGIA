# Catálogo de Endpoints — SGIA Backend API

> Sistema de Gestión de Inventario y Almacenamiento (INACAP Sede Temuco)  
> Base URL local: `http://localhost:8000/api`  
> Formato de datos: `application/json`  
> Mecanismo de autenticación: `Bearer Token` vía Laravel Sanctum en cabecera `Authorization: Bearer <token>`.

---

## Convenciones y Roles de Usuario

> **Fechas:** los campos de fecha-hora se devuelven en ISO 8601 con zona (UTC, p. ej. `2026-10-28T15:30:00+00:00`); el cliente debe mostrarlas en `America/Santiago`. Los campos solo-fecha (p. ej. `loan_date`) vienen como `YYYY-MM-DD`.
>
> **Errores:** siempre `{"message": "..."}` en español; los de validación (422) añaden `errors` (campo → lista de mensajes). 404 es `Recurso no encontrado.` y 401 es `No autenticado.`.
>
> **Listados:** paginados con `per_page` (1-100, 15 por defecto) y la estructura `data`, `links`, `meta`.

> Las eliminaciones (`DELETE`) responden `204 No Content` sin cuerpo. La excepción es `DELETE /api/suppliers/{id}` cuando el proveedor tiene productos: se desactiva y responde `200` con el proveedor.

> Límite `throttle:sensitive` (10 solicitudes por minuto por usuario) en cambio de contraseña, escaneo de facturas, escaneo de llegada e informes de novedades; `429` al superarlo.

| Código | Rol | Descripción |
|---|---|---|
| **AD-01** | Administrador | Acceso total al sistema, gestión de usuarios, auditoría y catálogos. |
| **DIR-01** | Director de Carrera / Asesor | Subida de facturas, cotizaciones, órdenes de compra, reportes y dashboards. |
| **PAN-01** | Pañolero / Encargado | Altas de productos, códigos de barra, préstamos presenciales/remotos y devoluciones. |
| **PRO-01** | Docente / Profesor | Solicitud remota de préstamos de pañol, catálogo y reporte de novedades. |

---

## 🟢 Endpoints Implementados (Listos para Uso)

### 1. Autenticación y Sesiones (`FU-01` / `REQ-01`)

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `POST` | `/api/login` | Público | Autenticación con email y password. Rate limit: 5 intentos/min. Retorna Bearer token, expiración según dispositivo (`web` 8h, `mobile` 60d) y datos de usuario. <br>**Body:** `email`, `password`, `device_name` (`web` \| `mobile`). | ✅ Implementado |
| `GET` | `/api/me` | Todos (`auth`) | Retorna el recurso del usuario autenticado (`UserResource`) con rol, área, estado y último login. | ✅ Implementado |
| `PUT` | `/api/password` | Todos (`auth`) | Cambio de contraseña del usuario autenticado. Valida contraseña actual, complejidad mínima (8 caracteres, letras y números) y revoca automáticamente las demás sesiones remotas.<br>**Body:** `current_password`, `password`, `password_confirmation`. | ✅ Implementado |
| `POST` | `/api/logout` | Todos (`auth`) | Cierra la sesión activa revocando el Bearer token actual. | ✅ Implementado |
| `POST` | `/api/devices` | Todos (`auth`) | Registra el token de push de Expo del dispositivo del usuario (si ya existía, pasa a este usuario).<br>**Body:** `token` (`ExponentPushToken[...]`), `platform` (`ios` \| `android`, opcional). Responde `201` con `id`. | ✅ Implementado |
| `DELETE` | `/api/devices/{id}` | Todos (`auth`) | Elimina un token propio (llamar al cerrar sesión). `204`; `404` si no es suyo. | ✅ Implementado |
| `POST` | `/api/logout-all` | Todos (`auth`) | Revoca todos los tokens activos del usuario autenticado. | ✅ Implementado |
| `GET` | `/api/tokens` | Todos (`auth`) | Lista todos los tokens y dispositivos activos del usuario autenticado con fecha de creación y expiración. | ✅ Implementado |
| `DELETE` | `/api/tokens/{tokenId}` | Todos (`auth`) | Revoca un token o dispositivo específico por ID. | ✅ Implementado |
| `POST` | `/api/tokens/revoke-others` | Todos (`auth`) | Revoca todas las sesiones excepto la que realiza la petición. | ✅ Implementado |

---

### 2. Administración de Usuarios (`FU-01` / `REQ-02`)

> Todos los endpoints de este módulo están restringidos estrictamente al rol **AD-01** (Administrador) y protegidos contra auto-eliminación o auto-desactivación del propio administrador.

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `GET` | `/api/users` | `AD-01` | Listado paginado de usuarios con soporte de búsqueda y filtros.<br>**Query Params:** `role`, `area`, `is_active`, `search` (nombre/email), `per_page` (1-100). | ✅ Implementado |
| `POST` | `/api/users` | `AD-01` | Crea un nuevo usuario en el sistema.<br>**Body:** `name`, `email` (único), `role` (`AD-01`, `DIR-01`, `PAN-01`, `PRO-01`), `area` (opcional), `password`, `password_confirmation`, `is_active` (opcional). | ✅ Implementado |
| `GET` | `/api/users/{id}` | `AD-01` | Obtiene el detalle de un usuario específico. | ✅ Implementado |
| `PUT / PATCH` | `/api/users/{id}` | `AD-01` | Actualiza los datos de un usuario (nombre, rol, área, email con validación de unicidad ignorando propio ID, contraseña opcional). | ✅ Implementado |
| `DELETE` | `/api/users/{id}` | `AD-01` | Elimina un usuario y borra en cascada sus tokens y registros de sesión. | ✅ Implementado |
| `PATCH` | `/api/users/{id}/status` | `AD-01` | Activa o desactiva la cuenta sin borrarla. Si se desactiva (`is_active = false`), se revocan de inmediato todas sus sesiones activas.<br>**Body:** `is_active` (`true` \| `false`). | ✅ Implementado |

### 2.0 Carreras (`FU-06` / `REQ-14`)

Catálogo de carreras con su nivel. El `area` del usuario se une por nombre exacto con `careers.name`; es lo que alimenta `CAREERS` con `groupBy: "level"` en `/api/metrics`.

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `GET` | `/api/careers` | `AD-01`, `DIR-01` | Listado paginado.<br>**Query Params:** `level`, `search` (nombre), `per_page`. Cada carrera: `id`, `name`, `level`, `level_label`, auditoría. | ✅ Implementado |
| `GET` | `/api/careers/{id}` | `AD-01`, `DIR-01` | Detalle de una carrera. | ✅ Implementado |
| `POST` | `/api/careers` | `AD-01` | Crea una carrera.<br>**Body:** `name` (único), `level` (`tecnico` \| `profesional`). | ✅ Implementado |
| `PATCH` | `/api/careers/{id}` | `AD-01` | Cambia nombre o nivel. | ✅ Implementado |
| `DELETE` | `/api/careers/{id}` | `AD-01` | Elimina la carrera (204). Los usuarios conservan su `area`; sus préstamos pasan a "Sin nivel". | ✅ Implementado |

---

### 2.1 Ubicaciones Físicas y Cajones (`FU-02` / `REQ-05`)

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `GET` | `/api/locations` | Todos (`auth`) | Listado paginado de ubicaciones (salas, pañoles, talleres). Soporta filtros por `tipo` (`sala`, `panol`, `taller`, `bodega`) y búsqueda por texto (`search`). Incluye conteo y lista de cajones, más auditoría (`created_by`, `updated_by`). | ✅ Implementado |
| `POST` | `/api/locations` | `AD-01`, `DIR-01`, `PAN-01` | Crea una nueva ubicación física.<br>**Body:** `nombre`, `tipo` (`sala`, `panol`, `taller`, `bodega`), `descripcion`. Registra automáticamente `created_by` y `updated_by`. | ✅ Implementado |
| `GET` | `/api/locations/{id}` | Todos (`auth`) | Detalle de ubicación con todos sus cajones registrados y auditoría. | ✅ Implementado |
| `PUT / PATCH` | `/api/locations/{id}` | `AD-01`, `DIR-01`, `PAN-01` | Actualiza nombre, tipo o descripción de la ubicación. Actualiza `updated_by`. | ✅ Implementado |
| `DELETE` | `/api/locations/{id}` | `AD-01`, `DIR-01` | Elimina una ubicación y sus cajones asociados. | ✅ Implementado |
| `GET` | `/api/cajones` | Todos (`auth`) | Listado de cajones/gavetas/compartimientos. Soporta filtros por `location_id` y búsqueda por código/descripción (`search`). Incluye relación de ubicación y auditoría. | ✅ Implementado |
| `POST` | `/api/cajones` | `AD-01`, `DIR-01`, `PAN-01` | Crea un nuevo cajón dentro de una ubicación.<br>**Body:** `location_id`, `codigo`, `descripcion`. Valida código único por ubicación y registra `created_by` / `updated_by`. | ✅ Implementado |
| `GET` | `/api/cajones/{id}` | Todos (`auth`) | Detalle del cajón con su ubicación padre y total de productos almacenados. | ✅ Implementado |
| `PUT / PATCH` | `/api/cajones/{id}` | `AD-01`, `DIR-01`, `PAN-01` | Actualiza código, descripción o mueve el cajón de ubicación. Actualiza `updated_by`. | ✅ Implementado |
| `DELETE` | `/api/cajones/{id}` | `AD-01`, `DIR-01` | Elimina un cajón. `422` con `products_count` si tiene productos asignados. | ✅ Implementado |
| `POST` | `/api/cajones/bulk-delete` | `AD-01`, `DIR-01` | Borrado masivo.<br>**Body:** `ids` (array), `partial` (opcional). Sin `partial`, si alguno tiene productos responde `422` con `blocked_ids`, `blocked_cajones` y `deletable_ids` y no borra nada. Con `partial: true` borra los libres (`deleted_ids`, `deleted_count`) e informa los bloqueados. | ✅ Implementado |

---

### 3. Inventario y Productos (`FU-02` / `REQ-03`, `REQ-04`, `REQ-05`)

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `GET` | `/api/products` | Todos (`auth`) | Listado paginado de productos con relaciones eager loaded (`supplier`, `location`).<br>**Query Params:** `search` (nombre, código de barras o descripción), `type` (`equipo` \| `insumo`), `area`, `is_active`, `supplier_id`, `location_id`, `sala`, `cajon`, `critical_only`, `per_page`. | ✅ Implementado |
| `POST` | `/api/products` | `AD-01`, `DIR-01`, `PAN-01` | Alta de producto. Si no se envía código de barras, genera automáticamente uno único tipo Code128 (`SGIA-XXXXXXXX`). Si se envían `sala` y `cajon`, crea/asocia la ubicación física automáticamente.<br>**Body:** `type` (opcional: `equipo` \| `insumo`, por defecto `equipo`), `name`, `description`, `barcode` (opcional), `quantity`, `stock_minimo`, `supplier_id`, `sala`, `cajon`, `area`, `photo_url`, `is_active`. | ✅ Implementado |
| `GET` | `/api/products/{id}` | Todos (`auth`) | Detalle de un producto con sus relaciones. | ✅ Implementado |
| `PUT / PATCH` | `/api/products/{id}` | `AD-01`, `DIR-01`, `PAN-01` | Edición de producto (incluido `type`). Al modificar stock o stock mínimo, evalúa y dispara alertas de stock automáticamente. | ✅ Implementado |
| `DELETE` | `/api/products/{id}` | `AD-01`, `DIR-01` | Eliminación de producto de inventario. | ✅ Implementado |
| `PATCH` | `/api/products/{id}/condition` | `AD-01`, `DIR-01`, `PAN-01` | Mueve unidades entre disponible, taller y baja.<br>**Body:** `action` (`send_to_repair`, `repaired`, `write_off`), `quantity`, `source` (`stock` \| `repair`, obligatorio en `write_off`). `422` si no hay unidades suficientes en el origen. | ✅ Implementado |
| `PATCH` | `/api/products/{id}/status` | `AD-01`, `DIR-01`, `PAN-01` | Activa o desactiva un producto del inventario.<br>**Body:** `is_active` (`true` \| `false`). | ✅ Implementado |
| `GET` | `/api/products/{id}/location` | Todos (`auth`) | Obtiene la sala, cajón y descripción de ubicación física de un producto. | ✅ Implementado |
| `PATCH` | `/api/products/{id}/location` | `AD-01`, `DIR-01`, `PAN-01` | Asigna o actualiza la ubicación física.<br>**Body:** `location_id` o combinación de `sala` y `cajon` con `descripcion` opcional. | ✅ Implementado |
| `GET` | `/api/products/{id}/barcode` | Todos (`auth`) | Retorna el código de barras del producto, su representación vectorial en SVG y su formato Data URI / HTML. | ✅ Implementado |

---

### 4. Escaneo de Facturas vía OCR (`FU-02` / `REQ-03`)

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `POST` | `/api/invoices/scan` | `AD-01`, `DIR-01` | Procesa documento o imagen de factura (PDF, PNG, JPG, WEBP de hasta 10MB) y extrae número de factura, proveedor sugerido y lista de borradores de productos con cantidades y precios para su posterior confirmación. Lectura con AWS Textract (`AnalyzeExpense`); si falla, respaldo local con `pdftotext` (PDF digital) o Tesseract (imagen / PDF escaneado), que es heurístico: el borrador puede venir incompleto (`invoice_number`/`supplier_name` nulos, `draft_products` vacío). `503` si Textract y el lector local fallan. | ✅ Implementado |
| `POST` | `/api/products/scan-invoice` | `AD-01`, `DIR-01` | Alias idéntico de `POST /api/invoices/scan`. | ✅ Implementado |

---

### 5. Alertas de Stock Crítico (`FU-02` / `REQ-06`)

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `GET` | `/api/alerts/critical-stock` | `AD-01`, `DIR-01`, `PAN-01` | Listado paginado de alertas activas generadas automáticamente cuando un producto llega a `stock_minimo + 5` (warning) o a `<= stock_minimo` (critical).<br>**Query Params:** `alert_type` (`warning` \| `critical`), `is_resolved` (`false` por defecto), `per_page`. | ✅ Implementado |
| `PATCH` | `/api/alerts/{id}/resolve` | `AD-01`, `DIR-01`, `PAN-01` | Marca manualmente una alerta de stock como resuelta. | ✅ Implementado |

---

### 6. Compras y Adquisiciones (`FU-03` / `REQ-08`)

> Máquina de estados de las órdenes (`App\Services\PurchaseStateMachine`): **`pendiente` → `en_camino` → `completa`**. Las órdenes pueden generarse a partir de una cotización aceptada de REQ-07 (ver sección 8), en cuyo caso la cotización queda `convertida`.

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `GET` | `/api/purchases` | `AD-01`, `DIR-01`, `PAN-01` | Listado paginado de órdenes de compra con proveedor e ítems.<br>**Query Params:** `status` (alias `estado`: `pendiente`, `en_camino`, `completa`), `supplier_id`, `quotation_id`, `search` (código, guía o factura), `per_page` (1-100). | ✅ Implementado |
| `POST` | `/api/purchases` | `AD-01`, `DIR-01` | Registra una nueva orden de compra en estado `pendiente`. Genera código único (`OC-<año>-XXXXXX`), persiste los ítems y calcula el total. Si se envía `quotation_id` de una cotización **aceptada**, copia proveedor e ítems de la cotización (a menos que se envíen explícitamente) y la marca como `convertida`.<br>**Body:** `quotation_id` **o** `supplier_id` + `items` (array con `name`, `quantity`, `unit_price` opcional, `product_id` opcional; mínimo 1), `quotation_reference`, `expected_at`, `notes`. | ✅ Implementado |
| `GET` | `/api/purchases/{id}` | `AD-01`, `DIR-01`, `PAN-01` | Detalle de la orden de compra, sus ítems adquiridos, la cotización de origen y las transiciones permitidas (`allowed_transitions`). | ✅ Implementado |
| `PATCH` | `/api/purchases/{id}/status` | `AD-01`, `DIR-01`, `PAN-01` | Transición de estados según la máquina (`pendiente` → `en_camino` → `completa`); una transición inválida responde `422` con los estados permitidos. Al pasar a `completa` registra `received_at` y notifica al pañolero para el ingreso físico a stock.<br>**Body:** `status` (alias `estado`). | ✅ Implementado |
| `POST` | `/api/purchases/{id}/arrival-scan` | `AD-01`, `DIR-01`, `PAN-01` | Marca la orden como `completa` al escanear la guía de despacho / factura de llegada: extrae el número de factura vía OCR, guarda el documento escaneado y notifica al pañolero (PAN-01).<br>**Body (multipart o JSON):** `document` (PDF, PNG, JPG o WEBP hasta 10MB), `guide_number`, `invoice_number` (al menos uno de los tres). | ✅ Implementado |
| `GET` | `/api/purchases/{id}/arrival-document` | `AD-01`, `DIR-01`, `PAN-01` | Descarga autenticada del documento de llegada (almacenado en disco privado). El recurso de la compra incluye `arrival_document_url`. | ✅ Implementado |

---

### 7. Proveedores (`FU-03` / `REQ-07`)

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `GET` | `/api/suppliers` | `AD-01`, `DIR-01`, `PAN-01` | Listado paginado de empresas proveedoras.<br>**Query Params:** `category`, `is_active`, `search` (nombre, contacto o correo), `per_page` (1-100). Incluye `products_count`. | ✅ Implementado |
| `POST` | `/api/suppliers` | `AD-01`, `DIR-01` | Crear proveedor.<br>**Body:** `name`, `contact_name`, `email`, `phone`, `category`, `is_active`. | ✅ Implementado |
| `GET` | `/api/suppliers/{id}` | `AD-01`, `DIR-01`, `PAN-01` | Detalle del proveedor y su historial de productos suministrados. | ✅ Implementado |
| `PUT / PATCH` | `/api/suppliers/{id}` | `AD-01`, `DIR-01` | Actualizar información del proveedor. | ✅ Implementado |
| `DELETE` | `/api/suppliers/{id}` | `AD-01` | Eliminar proveedor; si tiene productos asociados solo se desactiva, preservando el historial de inventario. | ✅ Implementado |
| `PATCH` | `/api/suppliers/{id}/status` | `AD-01`, `DIR-01` | Activar o suspender proveedor.<br>**Body:** `is_active` (`true` \| `false`). | ✅ Implementado |

---

### 8. Cotizaciones Automáticas (`FU-03` / `REQ-07`)

> Máquina de estados de las cotizaciones (`App\Services\QuotationStateMachine`): **`pendiente` → `aceptada` / `rechazada` → `convertida`** (al generarse la orden de compra, ver sección 6). Además, cada proveedor contactado registra su propia respuesta (`pendiente`, `aceptada`, `rechazada`).

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `POST` | `/api/quotations` | `AD-01`, `DIR-01` | Crea la cotización, persiste ítems y proveedores contactados y **envía por correo la solicitud a los 3+ proveedores seleccionados** (`App\Services\QuotationEmailService` + `QuotationRequestMail`). Devuelve `emails_sent`.<br>**Body:** `products` (array con `id` y `quantity`, mínimo 1), `supplier_ids` (mínimo 3 proveedores distintos), `notes`, `expires_at`. | ✅ Implementado |
| `GET` | `/api/quotations` | `AD-01`, `DIR-01` | Historial de cotizaciones emitidas con ítems y proveedores contactados.<br>**Query Params:** `status` (alias `estado`: `pendiente`, `aceptada`, `rechazada`, `convertida`), `search` (código), `per_page`. | ✅ Implementado |
| `GET` | `/api/quotations/{id}` | `AD-01`, `DIR-01` | Detalle de la cotización: ítems, proveedores contactados con su respuesta (`status`, `offer_total`, `responded_at`), orden de compra generada y transiciones permitidas. | ✅ Implementado |
| `POST` | `/api/quotations/{id}/resend` | `AD-01`, `DIR-01` | Reintenta el correo a los proveedores que no lo recibieron (sin correo o con error). Solo cotizaciones `pendiente`. Responde `emails_sent` y `emails_failed`. Cada proveedor contactado trae `email_sent_at` y `email_error`. | ✅ Implementado |
| `PATCH` | `/api/quotations/{id}/status` | `AD-01`, `DIR-01` | Transición según la máquina de estados; `422` con los estados permitidos si es inválida.<br>**Body:** `status` (alias `estado`). | ✅ Implementado |
| `PATCH` | `/api/quotations/{id}/responses` | `AD-01`, `DIR-01` | Registra la respuesta de un proveedor contactado. Al registrar una aceptación la cotización pasa a `aceptada`; si todos rechazan, a `rechazada`.<br>**Body:** `supplier_id`, `status` (`aceptada` \| `rechazada`), `offer_total`, `notes`. | ✅ Implementado |

---

### 9. Préstamos Remotos (Pre-reservas Docente) (`FU-04` / `REQ-09`)

> Máquina de estados de los préstamos (`App\Services\LoanStateMachine`): **`pendiente` → `en_proceso` → `procesado`**, con salida a `rechazado` (operaciones del pañol en la sección 10). El docente solo crea y consulta sus propias solicitudes.

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `POST` | `/api/loans/requests` | `PRO-01` | Solicita préstamo remoto de insumos/equipos para clases. Genera código único (`REM-<año>-XXXXXX`) y responde **"Solicitud enviada correctamente."** junto al registro creado (claves `data` y `loan`) en estado `pendiente`. Valida que cada producto exista, esté activo y tenga stock suficiente.<br>**Body:** `items` (array con `product_id` y `quantity`, mínimo 1), `subject` (alias `asignatura`), `room` (alias `sala`), `loan_date` (alias `fecha`), `time_block` (opcional), `notes` (opcional). | ✅ Implementado |
| `GET` | `/api/loans/requests` | `PRO-01` | Listado paginado de las solicitudes **propias** del docente autenticado.<br>**Query Params:** `estado` (alias `status`: `pendiente`, `en_proceso`, `procesado`, `rechazado`), `per_page` (1-100). | ✅ Implementado |
| `GET` | `/api/loans/my-requests` | `PRO-01` | Alias idéntico de `GET /api/loans/requests` (referencia del catálogo original). | ✅ Implementado |
| `DELETE` | `/api/loans/requests/{id}` | `PRO-01` | Cancela (elimina) una solicitud **propia** en estado `pendiente`. `204` sin cuerpo; `403` si es ajena; `422` si ya no está pendiente. | ✅ Implementado |

---

### 10. Operación de Préstamos en Pañol (`FU-04` / `REQ-10`)

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `GET` | `/api/loans/pending` | `PAN-01`, `AD-01` | Solicitudes remotas en estado `pendiente` por despachar; cada ítem devuelve la **cantidad solicitada**, el **stock disponible** (`available_stock`) y la **ubicación física** (`location`: `sala`, `cajon`, `descripcion`).<br>**Query Params:** `per_page` (1-100). | ✅ Implementado |
| `POST` | `/api/loans/{id}/approve` | `PAN-01`, `AD-01` | Aprueba la solicitud: **descuenta el stock** de cada producto (transacción con bloqueo de filas, 422 si no alcanza) y **notifica al solicitante**. Pasa a `en_proceso` y registra `approved_by` / `approved_at`. Evalúa además las alertas de stock (REQ-06). | ✅ Implementado |
| `POST` | `/api/loans/{id}/reject` | `PAN-01`, `AD-01` | Rechaza la solicitud con **motivo obligatorio** y **notifica al solicitante**. No descuenta stock.<br>**Body:** `rejection_reason` (alias `motivo`, mínimo 5 caracteres). | ✅ Implementado |
| `POST` | `/api/loans/checkout` | `PAN-01`, `AD-01` | Registra un **préstamo presencial directo**: crea el préstamo `procesado` con código (`PRE-<año>-XXXXXX`), descuenta stock atómicamente y guarda profesor/estudiante, ítems, asignatura, sala y fecha.<br>**Body:** `borrower_name` (alias `solicitante`), `borrower_document` (alias `credencial`, opcional), `items` (`product_id` + `quantity`, mínimo 1), `subject` (alias `asignatura`), `room` (alias `sala`), `loan_date` (alias `fecha`), `time_block`, `notes` opcionales. | ✅ Implementado |
| `POST` | `/api/loans/{id}/deliver` | `PAN-01`, `AD-01` | Confirma la entrega material al docente (`en_proceso` → `procesado`), registra `processed_by` / `processed_at` y notifica al solicitante. **Nuevo respecto del catálogo original**, necesario para cerrar el ciclo del préstamo remoto. | ✅ Implementado |
| `POST` | `/api/loans/checkin` | `PAN-01`, `AD-01` | Registra la devolución de ítems de un préstamo `procesado`; admite devoluciones parciales.<br>**Body:** `loan_id`, `general_notes` (opcional), `items[]` con `item_id` o `product_id`, `condition` (`bueno`, `optimo`, `regular`, `dañado`, `extraviado`) y `damaged` (bool; fuerza `dañado` si el estado era bueno).<br>`bueno`/`regular` reintegran stock; `dañado` suma a `quantity_in_repair`; `extraviado` a `quantity_written_off`. Cuando todos los ítems volvieron, el préstamo trae `returned_at`. | ✅ Implementado |

---

### 11. Historial y Auditoría de Préstamos (`FU-04` / `REQ-11`)

> El listado y el detalle devuelven el **campo explícito `status`** junto a los booleanos `is_pending`, `is_in_progress`, `is_processed` y `is_rejected` y el `status_label` correspondiente, para que el cliente aplique su estilo visual y diferencie de un vistazo los préstamos **`procesados`** de los que siguen **`en_proceso`**.

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `GET` | `/api/loans` | `PAN-01`, `DIR-01`, `AD-01` | Historial de préstamos con ítems (stock disponible y ubicación) y solicitante.<br>**Query Params:** `estado` (alias `status`: `pendiente`, `en_proceso`, `procesado`, `rechazado`), `insumo` (alias `product` o `product_id`: nombre/código del producto en los ítems), `profesor` (alias `teacher` / `borrower`: nombre del docente o de quien retira), `sala` (alias `room`), `tipo` (alias `type`: `remoto` \| `presencial`), `search` (código o asignatura), `atrasado` (alias `overdue`: solo entregados, no devueltos y vencidos), `devuelto` (alias `returned`), `per_page` (1-100). `estado` o `tipo` inválidos responden `422`. | ✅ Implementado |
| `GET` | `/api/loans/{id}` | `PAN-01`, `DIR-01`, `AD-01` | Detalle de un préstamo: ítems con producto y ubicación física, solicitante, quién aprobó y quién entregó, motivo de rechazo y `allowed_transitions`. | ✅ Implementado |
| `GET` | `/api/loans/export` | `DIR-01`, `PAN-01`, `AD-01` | Exporta el historial en `format=csv` (por defecto, abre en Excel; `excel` es alias) o `format=pdf`. Acepta `from`, `to` (fecha del préstamo) y los mismos filtros de `GET /api/loans`. Máximo 5.000 filas. | ✅ Implementado |

---

### 12. Fichas Técnicas de Equipos (`FU-05` / `REQ-12`)

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `GET` | `/api/equipment/{id}/technical-sheet` | Todos (`auth`) | Descarga la ficha técnica en PDF: datos del equipo, proveedor, ubicación física e historial de novedades. | ✅ Implementado |
| `GET` | `/api/equipment/{id}/reports` | Todos (`auth`) | Informes de novedades del equipo, paginados en JSON o en PDF con `?format=pdf`.<br>**Query Params:** `severity` (`leve`, `media`, `critica`), `status`, `per_page` (1-100). | ✅ Implementado |

---

### 13. Informes de Novedades (`FU-05` / `REQ-13`)

| Método | Endpoint | Roles Permitidos | Descripción / Parámetros | Estado |
|---|---|---|---|:---:|
| `POST` | `/api/equipment/{id}/reports` | `PRO-01`, `PAN-01` | Crea un informe de novedades asociado al equipo (`multipart/form-data`).<br>**Body:** `description` (obligatorio si no hay `title`), `title`, `severity` (`leve`, `media`, `critica`), `attachment` (opcional: pdf, jpg, png o webp, máx. 10 MB). | ✅ Implementado |
| `GET` | `/api/incident-reports` | `AD-01`, `DIR-01`, `PAN-01` | Listado global de informes, paginado, con el equipo y el reportante.<br>**Query Params:** `severity`, `status`, `product_id`, `search` (código, título o descripción), `per_page`. | ✅ Implementado |
| `PATCH` | `/api/incident-reports/{id}/status` | `AD-01`, `DIR-01`, `PAN-01` | Cambia el estado de atención. Transiciones: `reportado` → `en_revision` \| `en_reparacion` \| `dado_de_baja`; `en_revision` → `en_reparacion` \| `reparado` \| `dado_de_baja`; `en_reparacion` → `reparado` \| `dado_de_baja`. `reparado` y `dado_de_baja` son finales. Cada informe trae `allowed_transitions`. `422` si la transición no es válida. | ✅ Implementado |
| `GET` | `/api/incident-reports/{id}/attachment` | `AD-01`, `DIR-01`, `PAN-01` | Descarga autenticada del adjunto del informe (disco privado). Es la `attachment_url` que devuelve el informe. | ✅ Implementado |

---

### 14. Dashboards de Demanda (`FU-06` / `REQ-14`)

Todos: `DIR-01`, `AD-01`. Aceptan `limit` (1-50, por defecto 10; no aplica a `careers-distribution` ni a `kpis`) y un período opcional: `period` (`LAST_7_DAYS`, `LAST_30_DAYS`, `LAST_90_DAYS`, `CURRENT_MONTH`, `CURRENT_SEMESTER` (`SEMESTER_CURRENT`), `SEMESTER_PREVIOUS`, `YEAR_TO_DATE`, `YEAR`, un semestre `2026-1` / `2026-2` o un año `2026`) o `from` / `to` (`since` es alias de `from`). Se aplica a la fecha del préstamo (`loan_date`). Sin período, los rankings cubren todo el historial; `kpis` usa los últimos 30 días.

| Método | Endpoint | Descripción | Estado |
|---|---|---|:---:|
| `POST` | `/api/metrics` | **GraphQL** del panel: `executiveKpis(timeRange)` (tarjetas) y `customMetric(input)` (cualquier gráfico del constructor). Cuerpo `{query, variables, operationName}`; respuesta `{data, errors}` con HTTP 200 (los errores de consulta viajan en `errors`). Límites: profundidad 6, complejidad 200, 120 solicitudes por minuto, sin introspección en producción. Matriz de entidades y dimensiones en `TASKS/front-end.md` (C1). | ✅ Implementado |
| `GET` | `/api/dashboard/kpis` | Tarjetas ejecutivas: `assets` (`available`, `on_loan`, `in_repair`, `written_off`), `loans_today`, `active_loans`, `overdue_loans`, `overdue_rate` (%), `unresolved_alerts`, `critical_alerts`, `units_lent` y `rotation_rate` (% = unidades entregadas en el período ÷ (disponible + prestado)). | ✅ Implementado |
| `GET` | `/api/dashboard/top-products` | Productos/equipos más solicitados. | ✅ Implementado |
| `GET` | `/api/dashboard/top-supplies` | Insumos fungibles más solicitados. | ✅ Implementado |
| `GET` | `/api/dashboard/careers-distribution` | Distribución de uso por carrera/área. | ✅ Implementado |
| `GET` | `/api/dashboard/least-demanded` | Equipos con menor demanda (inventario ocioso). | ✅ Implementado |
| `GET` | `/api/dashboard/top-teachers` | Docentes con más solicitudes. | ✅ Implementado |
| `GET` | `/api/dashboard/loans-by-teacher` | Préstamos y unidades por docente y asignatura. | ✅ Implementado |

---

## 🟡 Endpoints Pendientes (Por Implementar)

| Método | Endpoint Planificado | Roles Previstos | Descripción | Ver |
|---|---|---|---|---|
| `GET` / `PUT` | `/api/equipment/{id}/specs` | Todos / `DIR-01`, `AD-01` | Especificaciones técnicas, manual, fecha de compra y vida útil. | REQ-12 |

---

## 📊 Resumen

Fuente de verdad: `php artisan route:list --path=api` (94 rutas, contando las variantes `PUT`/`PATCH`). Las filas con `PUT/PATCH` o alias comparten el mismo controlador. Los endpoints pendientes están listados arriba.
