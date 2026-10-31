# Cambios que afectan al frontend

Documento para traspasar al repositorio `sgia-frontend` (panel web y app móvil). Recoge lo que el backend ya cambió, lo que va a cambiar según `rework.md` y los requisitos que no coinciden con lo implementado.

Prioridad: **Urgente** = el cliente se rompe o queda con una falla de seguridad si no se adapta; **Pronto** = conviene antes de la siguiente entrega; **Informativo** = decisión o cambio futuro.

---

## A. Cambios ya hechos en el backend

### A1. Adjuntos y documentos ahora son privados — Informativo
Los archivos subidos (adjunto de informes de novedades y documento de llegada de compras) ya no son públicos. Respuesta del frontend: hoy ninguna pantalla los muestra (`attachment_url` solo existe como tipo en `packages/types/src/equipo.ts`; `arrival_document` no aparece), y las descargas que sí hace (ficha técnica, reportes, exportación) usan `getBlob()` con el token. **No hay impacto actual.**

Para cuando se implementen:
- Informe de novedades: `attachment_url` → `GET /api/incident-reports/{id}/attachment`. Roles: `AD-01`, `DIR-01`, `PAN-01`.
- Compra: `arrival_document_url` → `GET /api/purchases/{id}/arrival-document`. Roles: `AD-01`, `DIR-01`, `PAN-01`.
- Usar siempre `getBlob()`; un `<img src>` o `<a href>` recibirá 401. Responden 403 a otros roles y 404 si no hay archivo.

### A2. Alertas de stock restringidas por rol — Pronto
- `GET /api/alerts/critical-stock` y `PATCH /api/alerts/{id}/resolve`: `AD-01`, `DIR-01`, `PAN-01`. Coincide con los roles que ya entran a `/alertas` en la web, así que **no requiere cambios**.
- El docente (`PRO-01`) recibe **403**. La web no lo deja entrar y el móvil no llama a esos endpoints, pero la campana (`NotificationBell`) se muestra a cualquier rol web: si algún día entra otro rol, ocultarla o filtrarla por rol.

### A3. Límite de solicitudes en endpoints sensibles — Pronto
Máximo **10 por minuto por usuario** en: `PUT /api/password`, `POST /api/invoices/scan` (y `/products/scan-invoice`), `POST /api/purchases/{id}/arrival-scan`, `POST /api/equipment/{id}/reports`.

- Al superarlo responden **429** con header `Retry-After`.
- El cliente debe deshabilitar el botón mientras la solicitud está en curso (evitar doble envío) y mostrar "Demasiados intentos, espera N segundos" usando `Retry-After`. Sin esto, un doble clic o un reintento automático puede bloquear al usuario un minuto.
- El login ya tenía su propio límite (5 por minuto por correo + IP) con el mismo formato de 429.

### A4. CORS sin valores por defecto — Informativo
El backend ya no permite ningún origen si no se configura `CORS_ALLOWED_ORIGINS`. Para producción hay que entregar al equipo de infraestructura el dominio exacto del panel web. La app móvil nativa no usa CORS; sí Expo en modo web (`http://localhost:8081`, `:19006`).

### A4b. Cuenta desactivada: nuevo campo `code` — Pronto
La respuesta 403 de una cuenta desactivada (en el login y con un token ya emitido) ahora incluye `"code": "account_inactive"`. Hoy el cliente solo cierra sesión con 401 y muestra el 403 como "Error 403". Cambio sugerido en `http.ts` (compartido por web y móvil): si el 403 trae `code === "account_inactive"`, borrar el token y volver al login. Un 403 sin ese código sigue siendo "sin permisos".

### A5. Contraseña del administrador sembrado — Informativo
En desarrollo sigue siendo `admin@sgia.cl` / `Admin1234!`. En producción la define `SEED_ADMIN_PASSWORD`. Quitar cualquier credencial fija que haya en el cliente o en sus pruebas e2e.

---

## B. Cambios que vienen (rework)

Avisar al frontend **antes** de aplicarlos; ninguno está hecho todavía.

### B1. Rutas duplicadas retiradas — Informativo (hecho)
Se eliminaron `/user`, `/change-password` y `/tokens/revoke-all`. El frontend ya usaba `/me`, `/password` y `/logout-all`. Solo falta eliminar `revokeAllTokens` del cliente o apuntarlo a `/logout-all`.

### B2. Formato de error — Informativo (hecho)
Todos los errores traen `message` en español; los 422 añaden `errors`. Cambió el texto de 401 (`No autenticado.`), 403 por permisos (`No tienes permisos para realizar esta acción.`) y 404 (`Recurso no encontrado.`), antes en inglés o con el nombre de la clase. El cliente no depende de esos textos, solo del código HTTP.

### B2b. Fechas — Informativo
Las fecha-hora llegan en ISO 8601 UTC; mostrarlas en `America/Santiago`. Las fechas sin hora (`loan_date`) llegan como `YYYY-MM-DD` y no deben convertirse de zona.

### B3. Eliminaciones responden 204 — Informativo (hecho)
Todos los `DELETE` (ubicaciones, cajones, productos, usuarios, proveedores y `/tokens/{id}`) responden 204 sin cuerpo. El frontend ya lo tolera. Excepción: `DELETE /api/suppliers/{id}` con productos asociados responde 200 con el proveedor desactivado.

### B3b. Cajones: borrar con productos y borrado masivo — Pronto (hecho en el backend)
`DELETE /api/cajones/{id}` ahora responde `422` (con `products_count`) si el cajón tiene productos; antes los dejaba sin ubicación. Nuevo `POST /api/cajones/bulk-delete` con `{ids: [..], partial?: bool}`; la respuesta `422` trae `blocked_cajones` (`id`, `codigo`, `products_count`) y `deletable_ids`. Una sola ruta: no existen los alias `/estantes/*` ni `DELETE` con cuerpo.

### B4. Notificaciones push (Expo) — Pronto (hecho en el backend)
El backend ya envía push con la API de Expo y también correo. Para recibirlas la app móvil debe:
1. Pedir permiso y obtener el `ExpoPushToken` (`expo-notifications`; en SDK recientes requiere `projectId`).
2. Tras el login, `POST /api/devices` con `{ "token": "ExponentPushToken[...]", "platform": "ios" | "android" }`. Guardar el `id` de la respuesta.
3. Al cerrar sesión, `DELETE /api/devices/{id}` (204).
4. Renovar el registro si cambia el token; es idempotente.

Qué llega: alertas de stock a `AD-01`, `DIR-01`, `PAN-01` (`data.type = "stock_alert"`, con `alert_id` y `product_id`) y cambios de estado de préstamos al docente (`data.type = "loan_status"`, con `loan_id` y `status`). Usar `data` para navegar a la pantalla correspondiente. Los tokens inválidos se borran solos cuando Expo informa `DeviceNotRegistered`.

### B5. Escaneo de facturas — Informativo (fase 5)
`POST /api/invoices/scan` ahora lee la factura de verdad (PDF digital o imagen/escaneo) pero con heurística: `invoice_number` y `supplier_name` pueden venir `null` y `draft_products` vacío, y `area` siempre `null`. La pantalla de confirmación debe permitir editar todo y agregar filas a mano. Ya no acepta `.json` (422) y puede responder `503` si el OCR no está disponible: mostrar el mensaje y dejar ingresar manualmente.

---

## C. Requisitos que no coinciden con lo implementado

### C1. Dashboards: GraphQL `/api/metrics` — Pronto (hecho en el backend)
Implementado con los dos documentos exactos de `packages/api-client/src/services/metrics.ts` (`GetExecutiveKpis` y `GetCustomMetric`), enums idénticos a `packages/types/src/metric.ts` y respuesta `{data, errors}`. **Para activarlo en la web basta con que los hooks de `use-metrics.ts` llamen a los servicios del api-client en lugar del mock.**

- `POST /api/metrics`, roles `DIR-01` y `AD-01`, token Bearer. Errores de consulta o de datos: HTTP 200 con `errors[0].message` en español; 401/403 por token o rol.
- Quitar el *fallback* a datos fijos del api-client cuando el backend falla: hoy ocultaría errores reales (por ejemplo un `groupBy` no soportado) mostrando cifras inventadas.
- `executiveKpis(timeRange)` (por defecto `LAST_30_DAYS`; `CUSTOM` no se admite aquí): `activosDisponibles`, `activosEnPrestamo`, `activosEnTaller`, `activosDeBaja`, `prestamosActivosHoy`, `tasaAtrasosPct`, `alertasCriticasNoResueltas`, `tasaRotacionMensualPct` (esta última se calcula sobre el `timeRange` pedido, no solo el mes).
- `customMetric(input)`: `limit` (1-50, por defecto 10) recorta los rankings; las series de tiempo no se recortan. `CUSTOM` exige `dateFrom` (`YYYY-MM-DD`) y admite `dateTo` (por defecto hoy). El `title` se ignora.

**Qué devuelve cada entidad** (el período filtra por fecha del préstamo, de la alerta o del informe, salvo `EQUIPMENT` por categoría, ubicación y condición, que es una foto del inventario actual):

| Entidad | Sin `groupBy` | `groupBy` válidos |
|---|---|---|
| `LOANS` | serie en el tiempo (`Semana N` hasta 31 días, `YYYY-MM` si es más largo) | `status`, `teacher`, `subject`, `item_type` (categoría del producto) |
| `SUPPLIES` | ranking de productos por consumo; `secondaryValue` = stock restante | `career`, `category`, `turnover` (alta / media / baja / sin movimiento) |
| `EQUIPMENT` | ranking de productos más solicitados; `secondaryValue` = stock | `hardware_category` (alias `category`), `location`, `physical_condition` (disponible / en taller / dado de baja) |
| `ALERTS` | serie en el tiempo | `criticality`, `specialty_area` |
| `MAINTENANCE` | serie en el tiempo | `equipment`, `failure_type` (= gravedad del informe), `workshop` (ubicación del equipo) |
| `CAREERS` | préstamos por carrera (área del solicitante) | `specialty` |
| `TEACHERS` | ranking de docentes | `subject`, `department` (= área del docente) |

Semántica de la métrica: `COUNT` = registros; `TOTAL` = unidades prestadas; `AVERAGE` = unidades por registro; `RATE` = porcentaje del grupo sobre el total (suma 100). `LOANS` + `RATE` sin `groupBy` devuelve la dona *Devuelto a tiempo / Activo / Atrasado*. En `ALERTS` y `MAINTENANCE` no hay unidades, así que `TOTAL` y `AVERAGE` muestran el conteo y lo avisan en `summary`.

**Desajustes que el frontend debe corregir:**
- `CAREERS.level` y `TEACHERS.department` están en `GROUP_BY_OPTIONS_BY_ENTITY`: ambos funcionan (`level` desde C1b).
- Un `groupBy` fuera de la tabla devuelve error (no cae en datos de ejemplo). Las plantillas de `dashboard-page.tsx` usan `groupBy: 'category'` para `EQUIPMENT`: se acepta como alias de `hardware_category`.
- `EQUIPMENT` y `SUPPLIES` ya filtran por `products.type` (C1b).
- `secondaryValue` y `category` ahora llegan en `points` (el mock nunca los usaba): el gráfico "insumos con proyección de agotamiento" puede usar `secondaryValue` como stock restante.
- Aceptan además REST: `GET /api/dashboard/kpis` y los rankings, con `period` (`LAST_7_DAYS`, `LAST_30_DAYS`, `LAST_90_DAYS`, `CURRENT_MONTH`, `CURRENT_SEMESTER`/`SEMESTER_CURRENT`, `SEMESTER_PREVIOUS`, `YEAR_TO_DATE`, `YEAR`, `2026-1`, `2026`) o `from`/`to`.

### C1b. Tipo de producto y nivel de carrera — Pronto
- `POST` y `PATCH /api/products` aceptan `type` opcional (`equipo` | `insumo`; por defecto `equipo`, otro valor → `422`). Agregar el selector al formulario de alta (web y móvil) y a la confirmación del escaneo de facturas; sin él, todo lo nuevo cuenta como equipo en los dashboards. `ProductResource` trae `type` y `GET /api/products` filtra con `?type=`. Los productos que ya existían quedaron como `equipo`.
- En `/api/metrics`, `EQUIPMENT` solo cuenta productos `equipo` y `SUPPLIES` solo `insumo` (incluida la rotación). Las tarjetas de `executiveKpis` siguen sumando todos los productos.
- `CAREERS` acepta `groupBy: "level"`: etiquetas `Técnico de Nivel Superior`, `Profesional` y `Sin nivel` (el `area` del solicitante no coincide con ninguna carrera del catálogo).
- Nuevo catálogo `GET/POST /api/careers`, `GET/PATCH/DELETE /api/careers/{id}` (lectura `AD-01`/`DIR-01`, escritura `AD-01`): `id`, `name`, `code` (`ISD`|`II`|`IC`|`AP`, nullable; opcional en alta/edición, único), `level` (`tecnico` | `profesional`), `level_label`. El `area` del usuario debe escribirse igual que `name` para que cuente: conviene que el formulario de usuarios ofrezca las carreras como opciones.

### C2. Préstamos: devolución, cancelación, exportación y vencimiento — Pronto (hecho en el backend)
Ya existen, con el contrato que el cliente esperaba:
- `POST /api/loans/checkin` (`PAN-01`, `AD-01`). Body: `loan_id`, `general_notes`, `items[]` con `item_id` o `product_id`, `condition` (`bueno`, `optimo`, `regular`, `dañado`, `extraviado`) y `damaged`. Admite devolución parcial (solo algunos ítems). Respuesta: `{ message, data: <préstamo> }`.
- `DELETE /api/loans/requests/{id}` (`PRO-01`): 204; 403 si no es suyo; 422 si ya no está pendiente.
- `GET /api/loans/export?format=csv|pdf&from=&to=` + filtros del historial. Es una descarga: usar `getBlob()`. `csv` abre en Excel.
- Nombres canónicos de fechas del préstamo: `due_date` (vencimiento, `YYYY-MM-DD`), `returned_at` (ISO 8601; `null` mientras falten ítems) y `loan_date`. Dejar de leer `fechaDevolucion` y `return_date`.
- Campos nuevos: préstamo `is_returned`, `is_overdue`, `return_notes`, `returned_by`; ítem `is_returned`, `returned_at`, `return_condition` (`bueno`, `regular`, `danado`, `extraviado`).
- Filtros nuevos de `GET /api/loans`: `atrasado=1` y `devuelto=0|1`.
- `due_date` es opcional al crear (`POST /loans/requests` y `/loans/checkout`); si falta, vale `loan_date`.

### C3. Estado del activo — Pronto (hecho en el backend)
Los productos traen `quantity_in_repair`, `quantity_written_off` y `estado` (`disponible`, `en_reparacion`, `dado_de_baja` o `sin_stock`). Ojo: el tipo del cliente no conoce `sin_stock` (producto activo con 0 unidades disponibles). `quantity` sigue siendo solo lo disponible; lo que está prestado no se cuenta ahí. Para mover unidades: `PATCH /api/products/{id}/condition` (`send_to_repair`, `repaired`, `write_off` con `source`).

### C4. Informes de novedades — Pronto (hecho en el backend)
- `POST /api/equipment/{id}/reports` (`multipart/form-data`; `description` o `title`, `severity`, `attachment`; roles `PRO-01`, `PAN-01`).
- `GET /api/incident-reports` (`AD-01`, `DIR-01`, `PAN-01`): listado global con `severity`, `status`, `product_id`, `search`, `per_page`. Cada informe trae `product` y `reporter`.
- `PATCH /api/incident-reports/{id}/status` con `{ "status": "en_revision" | "en_reparacion" | "reparado" | "dado_de_baja" }`. Cada informe trae `allowed_transitions`: ofrecer solo esas opciones. 422 si la transición no es válida.
- `GET /api/equipment/{id}/reports` sigue disponible para la hoja de vida de un equipo.
- Adjunto: pdf, jpg, jpeg, png o webp, máximo 10 MB (ver A1 para descargarlo).

### C4b. Cotizaciones: envío de correo — Informativo
La respuesta de `POST /api/quotations` trae `emails_sent` y `emails_failed`; cada proveedor contactado trae `email_sent_at` y `email_error`. Si hay fallos, mostrar un aviso y ofrecer `POST /api/quotations/{id}/resend`, que reenvía solo a los que faltan (solo en cotizaciones `pendiente`).

### C5. Alertas de stock: destinatarios — Informativo
Decidido: se notifica a `AD-01`, `DIR-01` y `PAN-01` (ya implementado). Quien tenga la campana o el push debe esperar alertas también para el Director.

---

## D. Prácticas para que el sistema no colapse

Estas recomendaciones son del lado cliente y evitan sobrecargar la API o dejar al usuario en estados rotos.

1. **Paginar siempre.** Todos los listados aceptan `per_page` (por defecto 15, máximo 100). No pedir 100 por defecto ni recorrer todas las páginas de una vez; paginación o scroll infinito.
2. **Deshabilitar acciones en curso** (envío, escaneo, cambio de contraseña) hasta recibir respuesta, por los límites de A3.
3. **Reintentos con espera.** Reintentar solo errores de red o 5xx, con espera creciente, y solo en `GET`. Nunca reintentar automáticamente un `POST`: puede duplicar préstamos, compras o cotizaciones.
4. **Respetar 401, 403 y 429.**
   - 401: borrar el token y volver al login (los tokens web duran 8 horas, los móviles 60 días).
   - 403: mostrar "sin permisos", no reintentar. Además, ocultar en la interfaz lo que el rol no puede usar.
   - 429: esperar `Retry-After`.
5. **Polling.** Hoy el cliente no hace polling (la campana solo se actualiza al recargar o invalidar caché), y la especificación de REQ-09 pide refetch periódico. Si se añade: mínimo 30 segundos, solo con la pantalla visible, y nunca por usuario sin pausar en segundo plano. El backend aún no tiene tiempo real.
6. **Cuenta desactivada.** Una cuenta desactivada recibe 403 con `code: "account_inactive"`: cerrar sesión (ver A4b), no mostrar "Error 403".
7. **Subidas.** Enviar `multipart/form-data` y validar tamaño (10 MB) y tipo antes de subir. La web ya valida 10 MB en facturas; el móvil aún no sube fotos y, cuando lo haga, debe comprimirlas.
8. **Códigos de rol.** Usar los códigos `AD-01`, `DIR-01`, `PAN-01`, `PRO-01` tal como los devuelve `/api/me`; no deducir permisos del nombre ni del área.

---

## E. Preguntas ya respondidas por el frontend

1. Adjuntos: no usa URL directa (A1). 2. Alertas: web con `AD`/`DIR`/`PAN` (A2). 3. Alias: ya usa los canónicos (B1). 4. Errores: lee `message` y `errors` (B2). 5. `DELETE`: tolera 204 (B3). 6. Dashboards: GraphQL mockeado (C1). 7. Push: no existe (B4). 8. Préstamos: ver C2. 9. Estado: ver C3. 10. Novedades: ver C4. 11. Polling: ninguno. 12. 403: no cierra sesión (A4b). 13. Fotos: el móvil no sube todavía.

## F. Pendiente en el frontend (resumen)

- `http.ts`: cerrar sesión con `403` + `code: "account_inactive"` (A4b).
- Eliminar `revokeAllTokens` o apuntarlo a `/logout-all` (B1).
- Ocultar checkin, cancelación y exportación de préstamos mientras el backend no los tenga (C2).
- Tratar el filtro "De Baja" como no soportado por el backend (C3).
- Dashboards: conectar `use-metrics.ts` al api-client, quitar el *fallback* a datos fijos y ajustar `groupBy` (C1).
- Productos: selector de `type` en el alta (C1b); usuarios: elegir `area` desde `/api/careers` (C1b).
- Push: registrar y borrar el token de Expo (B4).
- Préstamos: usar `checkin`, cancelación, exportación y los campos nuevos (C2); producto: `estado` y `PATCH /products/{id}/condition` (C3).

## Plan Equipos — fase 2 (ubicaciones, categorías y códigos)

- `LocationResource` añade `storeroom_id`, `building`, `floor` (opcionales en `POST/PATCH /api/locations`; `storeroom_id` por defecto el pañol `PAN-TEMUCO`).
- `CajonResource` añade `barcode` (autogenerado `EST-xxxxxxxx`).
- `ProductResource` añade `category_id`, `career_id`, `maintenance_priority` (`A`|`B`|`C`), `requires_calibration`, `maintenance_frequency_months`, `reference_price`. `type` acepta además `maqueta`.
- **422 nuevos:** `POST /api/products` sin `category_id`; insumo sin `barcode` y sin `career_id` (la carrera debe tener `code`); `DELETE /api/locations/{id}` con cajones.
- Insumos sin `barcode` reciben `INS-{carrera}-000001`.

## Plan Equipos — fase 3 (unidades)

- **422 nuevos:** `DELETE /api/products/{id}` con unidades ("El producto tiene equipos registrados; dalos de baja en su lugar.", `units_count`); `DELETE /api/cajones/{id}` y `bulk-delete` con unidades (`units_count`).

## Plan Equipos — fase 4 (finanzas y cotizaciones)

- `SupplierResource` añade `rut` (normalizado `12345678-5`, único, validado con dígito verificador) y `address`; ambos opcionales.
- `QuotationResource` añade `career_id` y `title` (opcionales en `POST /api/quotations`).
- `QuotationSupplierResource` añade `quotation_number` (opcional en la respuesta del proveedor) y `has_backup_file`.
- **422 nuevos:** `DELETE /api/suppliers/{id}` con cotizaciones (desactívalo); `DELETE /api/products/{id}` referenciado por ofertas o facturas.

## Plan Equipos — fase 6 (unidades de equipo)

- Rutas nuevas: `GET/POST /api/products/{id}/units`, `GET /api/equipment-units`, `GET /api/equipment-units/by-code/{code}`, `GET/PATCH/DELETE /api/equipment-units/{id}`, `PATCH /api/equipment-units/{id}/status`, `GET /api/equipment-units/{id}/barcode`.
- `EquipmentUnitResource`: `id`, `product_id`, `product`, `code`, `serial_number`, `status` (`disponible`|`prestado`|`en_reparacion`|`dado_de_baja`), `cajon_id`, `cajon`, `location`, `storeroom`, `active_loan` (solo en el detalle), `last_maintenance_at`, `next_maintenance_at`, auditoría y fechas.
- `POST /api/products` de `equipo`/`maqueta` con `quantity > 0`: exige `career_id` (con código) y `cajon_id` (o `sala` + `cajon`) y crea las unidades con códigos `PRO-{carrera}-000001` / `MAQ-{carrera}-000001`; `units: [{serial_number}]` opcional.
- **422 nuevos:** `PATCH /api/products/{id}` con `quantity` en un equipo ("La cantidad de un equipo se calcula a partir de sus unidades."); `type` → `insumo` con unidades; `PATCH /api/products/{id}/condition` en un equipo (usar `PATCH /api/equipment-units/{id}/status`); cambiar a `prestado` a mano; `DELETE` de una unidad prestada o con historial.
- `products.quantity`, `quantity_in_repair` y `quantity_written_off` de un equipo se derivan de las unidades (las `prestado` no suman).

## Plan Equipos — fase 7 (préstamos con unidades)

- `LoanItemResource` añade `delivered_quantity`, `returned_quantity` y `units` (`id`, `code`, `serial_number`, `status`, `returned_at`, `return_condition`; solo si el ítem es un equipo/maqueta y las unidades vienen cargadas: detalle, historial, pendientes, checkout, checkin, deliver).
- `POST /api/loans/checkout`: `items.*.unit_ids` opcional (prohibido en insumos; debe haber exactamente `quantity` ids, del mismo producto y `disponible`); `storeroom_id` opcional (por defecto el del pañolero). Sin `unit_ids` se asignan unidades automáticas. Si `borrower_document` es un RUT válido se crea/reutiliza el `Student`.
- `POST /api/loans/{id}/approve`: asigna unidades automáticas.
- `POST /api/loans/{id}/deliver`: cuerpo opcional `items: [{item_id, unit_ids}]` para reemplazar las unidades asignadas por las realmente entregadas.
- `POST /api/loans/checkin`: `items.*.unit_ids` opcional para devolución parcial por unidad; `bueno`/`regular` → `disponible`, `danado` → `en_reparacion`, `extraviado` → `dado_de_baja`. El ítem queda devuelto cuando todas sus unidades lo están (`return_condition` = la peor).
- Export CSV/PDF: los códigos de las unidades van entre corchetes junto al nombre del ítem.

## Plan Equipos — fase 8 (hoja de vida por unidad)

- `POST /api/equipment/{product}/reports`: `equipment_unit_id` obligatorio si el producto es `equipo`/`maqueta` (debe ser una unidad de ese producto); `request_type` (`falla` por defecto | `reposicion` | `mantencion`); `evidence_photo` opcional (jpg/png/webp, 10 MB, disco privado).
- `IncidentReportResource` añade `equipment_unit_id`, `equipment_unit`, `request_type`, `has_evidence_photo`. Foto: `GET /api/incident-reports/{id}/evidence-photo` (AD/DIR/PAN).
- `GET /api/incident-reports` acepta `equipment_unit_id` y `request_type`.
- Nuevos: `GET /api/equipment-units/{id}/reports` (JSON paginado o `?format=pdf`) y `GET /api/equipment-units/{id}/technical-sheet` (PDF de la hoja de vida: producto, ubicación, mantención, préstamos, mantenciones e informes). La ficha del producto (`/equipment/{id}/technical-sheet`) incluye la tabla de unidades.
