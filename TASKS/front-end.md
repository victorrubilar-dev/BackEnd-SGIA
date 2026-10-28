# Cambios que afectan al frontend

Documento para traspasar al repositorio `sgia-frontend` (panel web y app móvil). Recoge lo que el backend ya cambió, lo que va a cambiar según `rework.md` y los requisitos que no coinciden con lo implementado.

Prioridad: **Urgente** = el cliente se rompe o queda con una falla de seguridad si no se adapta; **Pronto** = conviene antes de la siguiente entrega; **Informativo** = decisión o cambio futuro.

---

## A. Cambios ya hechos en el backend

### A1. Adjuntos y documentos ahora son privados — Urgente
Los archivos subidos (adjunto de informes de novedades y documento de llegada de compras) ya no son públicos. Si el cliente los mostraba con una URL directa (`<img src>`, `<a href>`, `Linking.openURL`), ahora responden **401**.

- Informe de novedades: el campo `attachment_url` apunta a `GET /api/incident-reports/{id}/attachment`. Roles: `AD-01`, `DIR-01`, `PAN-01`.
- Compra: la respuesta incluye `arrival_document_url` → `GET /api/purchases/{id}/arrival-document`. Roles: `AD-01`, `DIR-01`, `PAN-01`.
- Hay que pedirlos con el header `Authorization: Bearer <token>` y mostrar el resultado como blob (web: `URL.createObjectURL`; móvil: descargar a caché con el header y abrir el archivo local).
- Responden **403** para otros roles (incluido el docente que subió el informe) y **404** si el registro no tiene archivo. No mostrar el botón de descarga en esos casos.

### A2. Alertas de stock restringidas por rol — Urgente
- `GET /api/alerts/critical-stock`: `AD-01`, `DIR-01`, `PAN-01`.
- `PATCH /api/alerts/{id}/resolve`: solo `AD-01`, `PAN-01`. El Director puede ver pero no resolver.
- El docente (`PRO-01`) recibe **403** en ambos: no llamar a estos endpoints ni mostrar el contador de alertas para ese rol. Antes funcionaban para cualquier usuario autenticado.

### A3. Límite de solicitudes en endpoints sensibles — Pronto
Máximo **10 por minuto por usuario** en: `PUT /api/password` (y `POST /api/change-password`), `POST /api/invoices/scan` (y `/products/scan-invoice`), `POST /api/purchases/{id}/arrival-scan`, `POST /api/equipment/{id}/reports`.

- Al superarlo responden **429** con header `Retry-After`.
- El cliente debe deshabilitar el botón mientras la solicitud está en curso (evitar doble envío) y mostrar "Demasiados intentos, espera N segundos" usando `Retry-After`. Sin esto, un doble clic o un reintento automático puede bloquear al usuario un minuto.
- El login ya tenía su propio límite (5 por minuto por correo + IP) con el mismo formato de 429.

### A4. CORS sin valores por defecto — Informativo
El backend ya no permite ningún origen si no se configura `CORS_ALLOWED_ORIGINS`. Para producción hay que entregar al equipo de infraestructura el dominio exacto del panel web. La app móvil nativa no usa CORS; sí Expo en modo web (`http://localhost:8081`, `:19006`).

### A5. Contraseña del administrador sembrado — Informativo
En desarrollo sigue siendo `admin@sgia.cl` / `Admin1234!`. En producción la define `SEED_ADMIN_PASSWORD`. Quitar cualquier credencial fija que haya en el cliente o en sus pruebas e2e.

---

## B. Cambios que vienen (rework)

Avisar al frontend **antes** de aplicarlos; ninguno está hecho todavía.

### B1. Retiro de rutas duplicadas — Pronto (fase 4)
Hoy existen alias: `/me` y `/user`, `/password` y `/change-password`, `/logout-all` y `/tokens/revoke-all`. Se definirá uno canónico y se retirarán los otros. **Propuesta:** `/me`, `/password`, `/logout-all`. El cliente debería usar ya esas tres.

### B2. Formato único de error — Pronto (fase 4)
Se unificará a `{ "message": "...", "errors": { "campo": ["..."] } }`. Hoy algunos endpoints devuelven formas distintas (por ejemplo `alert` en la resolución de alertas y `purchase` en el escaneo de llegada, en vez de `data`). El cliente debe centralizar el manejo de errores en un solo interceptor y leer `message` y `errors`, sin depender de campos por endpoint.

### B3. Eliminaciones: 200 o 204 — Informativo (decisión pendiente)
Hoy `DELETE` responde **200 con `{ "message": ... }`**; el requisito NF-03 pedía 204. Si se cambia a 204 (sin cuerpo), un cliente que haga `response.json()` fallará. Hacer el cliente tolerante a ambos.

### B4. Notificaciones — Informativo (fase 5)
Las alertas hoy solo se emiten por el canal `broadcast`; el correo y el push móvil no existen. El móvil debe definir con el backend el proveedor de push (FCM o Expo) para poder registrar el token del dispositivo. Pendiente: endpoint de registro de token.

### B5. Escaneo de facturas — Informativo (fase 5)
`POST /api/invoices/scan` hoy devuelve datos fijos (no hay OCR real). El cliente no debe asumir que el borrador es fiel a la factura: la pantalla de confirmación debe permitir editar todos los campos. También dejará de aceptar `.json`.

---

## C. Requisitos que no coinciden con lo implementado

### C1. Dashboards: faltan KPIs y filtro por período — Pronto
El requisito pide tarjetas de resumen (activos disponibles / en préstamo / en taller / de baja, préstamos del día, tasa de atrasos, alertas sin resolver, rotación mensual) y un selector de período (semestre, año, rango).

- **Existen** (REST, `DIR-01` y `AD-01`, parámetro `limit` 1-50): `top-products`, `top-supplies`, `careers-distribution`, `least-demanded`, `top-teachers`.
- **No existen**: los KPIs ni el filtro de período. No construir esas tarjetas con datos calculados en el cliente.
- **Decisión abierta:** el requisito original decía GraphQL en `/api/metrics`; el backend usa REST (`/api/dashboard/*`). Mientras no se decida, usar REST.

### C2. Préstamos: no hay devolución ni atrasos — Pronto
No existen el flujo de devolución (`POST /api/loans/checkin`), la cancelación de una solicitud (`DELETE /api/loans/requests/{id}`) ni la exportación (`GET /api/loans/export`). Tampoco hay fecha de vencimiento, así que no se puede mostrar "atrasado". Ocultar esas opciones en la interfaz hasta que existan.

### C3. Estado del activo — Informativo
No hay estados "en taller" ni "dado de baja" en los productos. La ficha técnica y el filtro por estado deben limitarse a `is_active`.

### C4. Informes de novedades — Informativo
- Se crean con `POST /api/equipment/{id}/reports` (`multipart/form-data`; campos `description` o `title`, `severity`, `attachment`). Roles: `PRO-01` y `PAN-01`.
- Solo se pueden listar **por equipo** (`GET /api/equipment/{id}/reports`, roles `AD-01`, `DIR-01`, `PAN-01`). No existe listado global ni cambio de estado del informe; esas pantallas no tienen endpoint todavía.
- Adjunto: pdf, jpg, jpeg, png o webp, máximo 10 MB. Validar tipo y tamaño en el cliente antes de subir para evitar un 422.

### C5. Alertas de stock: destinatarios — Informativo
Hoy se notifica a `AD-01` y `PAN-01` (el documento original decía `DIR-01` y `PAN-01`). Si el Director debe recibir la notificación, avisar para cambiarlo en el backend.

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
5. **No consultar en bucle.** Para alertas y solicitudes pendientes, usar un intervalo mínimo de 30 segundos y solo con la pantalla visible. El backend aún no tiene tiempo real.
6. **Cuenta desactivada.** Una cuenta desactivada recibe 403 con token válido: tratarlo como cierre de sesión forzado, no como error de permisos.
7. **Subidas.** Enviar `multipart/form-data` y validar tamaño (10 MB) y tipo antes de subir; comprimir las fotos del móvil.
8. **Códigos de rol.** Usar los códigos `AD-01`, `DIR-01`, `PAN-01`, `PRO-01` tal como los devuelve `/api/me`; no deducir permisos del nombre ni del área.

---

## E. Pendiente de confirmar con el equipo de frontend

- ¿Los adjuntos se mostraban con URL directa hoy? (A1)
- ¿Qué rutas alias usa el cliente actualmente? (B1)
- ¿Qué formato de error espera el interceptor actual? (B2)
- ¿Expo o FCM para el push móvil? (B4)
- ¿GraphQL o REST para dashboards? (C1)
