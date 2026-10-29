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

### B4. Notificaciones push (Expo) — Pronto (hecho en el backend)
El backend ya envía push con la API de Expo y también correo. Para recibirlas la app móvil debe:
1. Pedir permiso y obtener el `ExpoPushToken` (`expo-notifications`; en SDK recientes requiere `projectId`).
2. Tras el login, `POST /api/devices` con `{ "token": "ExponentPushToken[...]", "platform": "ios" | "android" }`. Guardar el `id` de la respuesta.
3. Al cerrar sesión, `DELETE /api/devices/{id}` (204).
4. Renovar el registro si cambia el token; es idempotente.

Qué llega: alertas de stock a `AD-01`, `DIR-01`, `PAN-01` (`data.type = "stock_alert"`, con `alert_id` y `product_id`) y cambios de estado de préstamos al docente (`data.type = "loan_status"`, con `loan_id` y `status`). Usar `data` para navegar a la pantalla correspondiente. Los tokens inválidos se borran solos cuando Expo informa `DeviceNotRegistered`.

### B5. Escaneo de facturas — Informativo (fase 5)
`POST /api/invoices/scan` hoy devuelve datos fijos (no hay OCR real). El cliente no debe asumir que el borrador es fiel a la factura: la pantalla de confirmación debe permitir editar todos los campos. También dejará de aceptar `.json`.

---

## C. Requisitos que no coinciden con lo implementado

### C1. Dashboards: KPIs y período listos en REST; GraphQL pendiente — Pronto
- Ya existen en REST (`DIR-01`, `AD-01`): `GET /api/dashboard/kpis` y los rankings (`top-products`, `top-supplies`, `careers-distribution`, `least-demanded`, `top-teachers`, `loans-by-teacher`). Todos aceptan el período: `period` (`LAST_7_DAYS`, `LAST_30_DAYS`, `LAST_90_DAYS`, `CURRENT_MONTH`, `CURRENT_SEMESTER`, `YEAR`, `2026-1`, `2026-2`, `2026`) o `from`/`to`. `since` ahora sí se respeta.
- Respuesta de `kpis`: `{ data: { period, assets: { available, on_loan, in_repair, written_off }, loans_today, active_loans, overdue_loans, overdue_rate, unresolved_alerts, critical_alerts, units_lent, rotation_rate }, generated_at }`.
- Decidido: la pantalla irá por GraphQL en `/api/metrics`. **Falta que el frontend entregue su contrato** (ver sección G) para construir el esquema.

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

### C4. Informes de novedades — Informativo
- Existen servicios y hooks en el cliente (`useIncidentReports`, `useUpdateIncidentReportStatus`, `useCreateNovedad`) pero ninguna pantalla los usa; REQ-13 está sin empezar en el frontend.
- El backend solo tiene `POST /api/equipment/{id}/reports` (`multipart/form-data`; `description` o `title`, `severity`, `attachment`; roles `PRO-01`, `PAN-01`) y el listado por equipo (`GET /api/equipment/{id}/reports`; `AD-01`, `DIR-01`, `PAN-01`). **No existen** `GET /api/incident-reports` ni el cambio de estado que esos hooks esperan.
- Adjunto: pdf, jpg, jpeg, png o webp, máximo 10 MB.

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

## G. Lo que necesito del frontend para el GraphQL

Pegar tal cual desde el repositorio del frontend:
1. Los documentos de consulta GraphQL que se envían a `/api/metrics` (en `features/dashboard`, el constructor de gráficos, `useExecutiveKpis`).
2. El tipo `MetricResult` (`features/dashboard/types/metrics.ts`) y cualquier tipo relacionado (filtros, rangos, dimensiones).
3. El `mock-metrics.json` completo (la forma exacta que esperan los gráficos).
4. Cómo se envía el rango (`LAST_30_DAYS`, semestre, etc.): nombres y valores de los argumentos.

## F. Pendiente en el frontend (resumen)

- `http.ts`: cerrar sesión con `403` + `code: "account_inactive"` (A4b).
- Eliminar `revokeAllTokens` o apuntarlo a `/logout-all` (B1).
- Ocultar checkin, cancelación y exportación de préstamos mientras el backend no los tenga (C2).
- Tratar el filtro "De Baja" como no soportado por el backend (C3).
- Dashboards: entregar el contrato GraphQL (sección G); mientras tanto pueden probar los endpoints REST.
- Push: registrar y borrar el token de Expo (B4).
- Préstamos: usar `checkin`, cancelación, exportación y los campos nuevos (C2); producto: `estado` y `PATCH /products/{id}/condition` (C3).
