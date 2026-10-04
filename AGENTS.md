# AGENTS.md — SGIA Backend API

> Guía técnica y de arquitectura para agentes de IA que trabajan en el backend de SGIA.
> Fuente: Documento de formulación de proyecto SGIA (INACAP Sede Temuco, 2026).

---

## 0. Alcance de este repositorio

Este repositorio contiene **exclusivamente la API REST del backend** de SGIA.
- **Frontend web (React + Vite + Tailwind):** repositorio separado.
- **App móvil (React Native + Expo):** repositorio separado.
- Cualquier mención en este documento a interfaces, pantallas, navegación o formularios de usuario describe el comportamiento esperado del cliente que consumirá esta API, no vistas que deban implementarse aquí (salvo vistas Blade de correos transaccionales si aplica).

---

## 1. Contexto del proyecto

**SGIA** (Sistema de Gestión de Inventario y Almacenamiento) es una plataforma desarrollada para la Dirección de Área de Electricidad, Electrónica y Telecomunicaciones de INACAP Sede Temuco. Actualmente, el control de inventario de equipos y herramientas se realiza con registros en papel y libros de actas manuales.

### Problema que resuelve
- Registro manual propenso a pérdidas, errores y duplicidad de información.
- Desconocimiento en tiempo real del stock disponible y del estado de los préstamos.
- Tiempos de espera elevados en el pañol durante cambios de módulo de clases.
- Procesos de compra reactivos y sin trazabilidad formal.
- Historial de reparaciones y bajas disperso o inexistente.

### Objetivo del sistema
Centralizar y digitalizar la gestión integral del inventario de pañol: administración de stock, control de préstamos presenciales y remotos mediante códigos QR/barras, alertas de stock crítico, cotizaciones automáticas a proveedores y generación de reportes y dashboards para la toma de decisiones.

---

## 2. Roles de usuario (importante para permisos y RBAC)

El sistema define 4 perfiles con permisos diferenciados que la API debe hacer cumplir:

| Código | Rol | Responsabilidades clave |
|---|---|---|
| **AD-01** | Administrador | Gestión completa de usuarios (crear, suspender, asignar rol). Auditoría y configuración global del sistema. |
| **DIR-01** | Director de Carrera / Asesor | Subir facturas de compra, autorizar adquisiciones, emitir cotizaciones automáticas, dar de baja equipos, ver dashboards analíticos y reportes de inventario/préstamos. |
| **PAN-01** | Encargado de Pañol / Pañolero | Operación diaria: registrar altas de productos, generar códigos de barras/QR, entregar/recibir préstamos presenciales, gestionar préstamos remotos solicitados por docentes, actualizar estados de ítems. |
| **PRO-01** | Docente / Profesor | Solicitar préstamos de equipos de forma remota (pre-reserva para clases), consultar catálogo y disponibilidad, emitir informes de novedades/fallas. |

---

## 3. Módulos funcionales (alto nivel)

1. **FU-01 — Gestión de Usuarios y Accesos:** Login, perfiles, RBAC, auditoría de sesiones.
2. **FU-02 — Control de Stock e Inventario:** CRUD de productos, categorías, marcas, modelos, números de serie, ubicación física (sala/cajón), alta vía OCR de facturas, generación de códigos de barra/QR, alertas de stock crítico.
3. **FU-03 — Compras y Adquisiciones:** Carga de facturas, solicitud y envío automático de cotizaciones por email a proveedores registrados, trazabilidad del estado de compras.
4. **FU-04 — Gestión de Préstamos:** Préstamo presencial (escaneo código de barras/QR de equipo + credencial docente), solicitud remota (pre-reserva con fecha/hora/asignatura), devoluciones, cálculo de atrasos, historial.
5. **FU-05 — Mantenimiento y Fichas Técnicas:** Registro de fallas (informe de novedades), historial de reparaciones, especificaciones técnicas por equipo, estado operativo (disponible, en préstamo, en reparación, dado de baja).
6. **FU-06 — Reportes y Dashboards:** Métricas de rotación de inventario, equipos más solicitados, docentes con préstamos activos, productos bajo stock mínimo, exportación PDF/Excel.

---

## 4. Requisitos no funcionales clave

- **REQ-NF-01 / REQ-NF-02 — Seguridad:** OWASP Top 10 para API. Autenticación robusta, validación estricta de payloads, hashing seguro de contraseñas, prevención de inyección SQL (uso de Eloquent ORM), rate limiting.
- **REQ-NF-03 — Accesibilidad:** Endpoints consistentes con códigos de estado HTTP estándar (200, 201, 204, 400, 401, 403, 404, 422, 500) y respuestas de error normalizadas en formato JSON: `{"message": "...", "errors": {...}}`.
- **REQ-NF-04 — Rendimiento:** Tiempos de respuesta menores a 2 segundos en el 90% de las consultas bajo carga concurrente normal. Paginación obligatoria en todos los listados (`per_page` por defecto: 15, configurable hasta 100). Eager loading para evitar problemas de N+1 queries.

---

## 5. Arquitectura

- **Framework:** Laravel 11.x (PHP 8.2+).
- **Base de datos:** PostgreSQL 16 (desplegado en AWS RDS en producción).
- **Almacenamiento de archivos (facturas, imágenes de productos, PDFs):** AWS S3 (o compatible: MinIO en local).
- **Cola de trabajos / tareas asíncronas:** Redis + Laravel Horizon (envío de emails de cotización, procesamiento de imágenes OCR, alertas).
- **Estándar de API:** RESTful JSON. Cada recurso con su FormRequest para validación y su API Resource para serialización.

---

## 5.1 Autenticación y CORS (SPA web + mobile)

Este backend debe servir de forma consistente a **dos clientes distintos**: el panel web (React/Vite, corre en el navegador, sujeto a CORS) y la app móvil (React Native/Expo, no es un navegador, no aplica CORS). La estrategia elegida evita tener dos mecanismos de auth distintos para no duplicar lógica ni casos borde.

**Paquete: Laravel Sanctum.**

Se descarta Passport (OAuth2 completo) porque no hay necesidad de emitir tokens a terceros ni flujos `authorization_code`/`client_credentials`; Sanctum cubre exactamente lo que se necesita (tokens simples tipo API) con mucho menos overhead de configuración y mantenimiento.

**Modo de uso: tokens Bearer para ambos clientes, no autenticación por cookies de Sanctum ("SPA authentication").**

Aunque Sanctum ofrece un modo especial de cookies/CSRF para SPAs en el mismo dominio (`stateful` domains), aquí se usa el modo de **personal access tokens** (Bearer) para ambos clientes por estas razones:
- El panel web y la API probablemente se despliegan en subdominios/dominios distintos dentro de AWS (o incluso separados por ahora), lo que complica el flujo de cookies + CSRF.
- Mobile no puede usar cookies de sesión de forma práctica; necesita Bearer tokens de todas formas.
- Usar el mismo mecanismo (Bearer) en ambos clientes evita mantener dos flujos de auth en paralelo y simplifica el `api-client` compartido del monorepo frontend.

### Reglas para la implementación:
- [x] Instalar y configurar Sanctum solo para **API tokens** (no usar el middleware `EnsureFrontendRequestsAreStateful`, ya que no habrá flujo de cookies/CSRF).
- [x] En `POST /api/login`, validar credenciales y emitir el token con `$user->createToken($deviceName)->plainTextToken`, donde `$deviceName` identifica el cliente (`web`, `mobile`) para poder listar/revocar sesiones por dispositivo si se requiere.
- [x] Definir expiración de tokens en `config/sanctum.php` (`expiration`); considerar tokens más largos para mobile (el docente no debería re-loguearse constantemente) y más cortos para web.
- [x] `POST /api/logout` → `$request->user()->currentAccessToken()->delete()`.
- [x] (Opcional) Endpoint para que un usuario liste y revoque sus tokens activos por dispositivo (`GET /api/tokens`, `DELETE /api/tokens/{tokenId}`).
- [x] Configurar `config/cors.php`:
  - [x] `paths` → `['api/*']`.
  - [x] `allowed_origins` → el origen del panel web (ej. `http://localhost:5173` en local, dominio real en prod vía variable de entorno `CORS_ALLOWED_ORIGINS`). Nunca `*` en producción.
  - [x] `allowed_methods` → `['*']` o explícitos `['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS']`.
  - [x] `allowed_headers` → incluir `['Authorization', 'Content-Type', 'Accept', 'X-Requested-With']`.
  - [x] `supports_credentials` → `false` (no se usan cookies para auth, así que no hace falta habilitar credenciales cross-origin, lo que simplifica la configuración).
- [x] Mobile (React Native/Expo) no pasa por el navegador en producción, por lo que **no está sujeto a CORS**; solo puede aplicar en desarrollo si se prueba la app en modo web de Expo — en ese caso agregar también ese origen de desarrollo a `allowed_origins` (idealmente solo en el entorno local, no en producción).

---

## 6. Tareas de backend desglosadas por requisito

Cada requisito (`REQ-XX`) del documento de formulación se traduce aquí en tareas concretas de API. Un agente puede tomar un `REQ` como unidad de trabajo/PR.

### REQ-01 — Autenticación por rol (FU-01)
- [x] Endpoint `POST /api/login` (correo + contraseña) que devuelva token Bearer vía Sanctum (ver sección 5.1).
- [x] Middleware `auth:sanctum` en todas las rutas protegidas; rechazar usuarios desactivados (chequeo adicional en el `User` model o en un middleware propio, Sanctum no lo hace por defecto).
- [x] Endpoint `POST /api/logout` que revoque el token actual.
- [x] Devolver en la respuesta de login el rol del usuario para que el cliente adapte su UI/navegación.

### REQ-02 — Administración de usuarios (FU-01, solo AD-01)
- [x] CRUD `api/users` (nombre completo, correo, rol, contraseña, área).
- [x] Endpoint `PATCH /api/users/{id}/status` para activar/desactivar sin eliminar de la BD.
- [x] Policy que restrinja este CRUD únicamente al rol AD-01.
- [x] Validación: correo único, contraseña con reglas mínimas de seguridad (hash con bcrypt/argon).

### REQ-03 — Alta/edición de productos vía escaneo de facturas (FU-02, DIR-01)
- [ ] Endpoint que reciba imagen/documento de factura y devuelva un borrador de producto(s) extraído(s) (nombre, cantidad, proveedor) para confirmación posterior. (El OCR/extracción puede ser un servicio externo invocado desde la API; definir en Tecnologías.)
- [ ] Endpoint `POST /api/products` para confirmar el borrador y crear el producto (nombre, cantidad, proveedor, área, foto opcional).
- [ ] Endpoint `PATCH /api/products/{id}` para editar y `PATCH /api/products/{id}/status` para activar/desactivar.

### REQ-04 — Alta/edición de productos por Pañol (FU-02, PAN-01)
- [ ] Mismo CRUD de REQ-03 pero accesible también a PAN-01.
- [ ] Generación automática de **código de barras único** al crear el producto (servicio interno, ej. Code128/EAN).
- [ ] Validación: nombre sin caracteres especiales, cantidad positiva, proveedor existente.

### REQ-05 — Ubicación física de productos (FU-02)
- [ ] Campos `sala` y `cajón` (o modelo `Location`) asociados a cada producto/ítem.
- [ ] Endpoint `GET /api/products/{id}/location` y `PATCH /api/products/{id}/location` para consultar y actualizar ubicación física.

### REQ-06 — Alertas de stock crítico (FU-02)
- [ ] Campo `stock_minimo` por producto.
- [ ] Job programado / trigger en evento de rebaja de stock que detecte si `stock_actual <= stock_minimo`.
- [ ] Notificación vía email (y registro en BD para panel web) al Director de Carrera (DIR-01) y Pañolero (PAN-01).
- [ ] Endpoint `GET /api/alerts/critical-stock` para el panel web / mobile.

### REQ-07 — Envío de cotizaciones automáticas (FU-03, DIR-01)
- [ ] CRUD de proveedores (`api/suppliers`): nombre empresa, contacto, email, teléfono, rubro/categoría de insumos.
- [ ] Endpoint `POST /api/quotations` que reciba lista de productos requeridos + selección de proveedores destinatarios.
- [ ] Job en cola (`SendQuotationEmailJob`) con plantilla Mailable en Laravel que envíe el correo formal con el detalle de los insumos solicitados.
- [ ] Registro en BD del historial de cotizaciones enviadas con fecha, usuario emisor y proveedores contactados.

### REQ-08 — Estado de compra (FU-03, DIR-01)
- [ ] Modelo `PurchaseOrder` con estados: `pendiente`, `en_cotizacion`, `aprobada`, `rechazada`, `recibida`.
- [ ] Endpoint `GET /api/purchases` y `PATCH /api/purchases/{id}/status` para cambiar estado (con registro de timestamp y usuario que autorizó).
- [ ] Al pasar a `recibida`, gatillar alta automática o notificación al pañolero para ingreso físico.

### REQ-09 — Solicitud de préstamo remoto (FU-04, PRO-01)
- [ ] Endpoint `POST /api/loans/requests` para que el docente solicite ítems: fecha y bloque horario de la clase, asignatura, lista de ítems solicitados.
- [ ] Validación: no permitir reservar ítems cuyo estado no sea `disponible` en ese bloque horario (evitar colisiones).
- [ ] Notificación al pañolero del préstamo solicitado.
- [ ] Endpoint `GET /api/loans/my-requests` para que el docente vea el estado de sus reservas (`pendiente`, `preparado`, `entregado`, `rechazado`).

### REQ-10 — Procesamiento de préstamos presenciales/remotos (FU-04, PAN-01)
- [ ] Endpoint `POST /api/loans/checkout` (entrega presencial): recibe ID de docente (escaneo de credencial o selección) + escaneo de código(s) de barra de ítem(s).
- [ ] Cambia estado de los ítems a `en_prestamo` de forma atómica (transacción DB).
- [ ] Endpoint `POST /api/loans/checkin` (devolución): escaneo de ítems devueltos. Cambia estado a `disponible` (o `en_reparacion` si se reporta daño).
- [ ] Detección automática de préstamos atrasados mediante comando `loans:check-overdue` (ejecutado por Scheduler cada 1 hora).

### REQ-11 — Historial y listado de préstamos (FU-04, PAN-01)
- [ ] Endpoint `GET /api/loans` con filtros por: fecha, docente, estado (`activo`, `devuelto`, `atrasado`), asignatura.
- [ ] Exportación a PDF/Excel de préstamos de un período.

### REQ-12 — Fichas técnicas de equipos (FU-05, DIR-01)
- [ ] Endpoint `GET /api/equipment/{id}/specs` y `PUT /api/equipment/{id}/specs`: especificaciones técnicas, manual de usuario (PDF almacenado en S3), fecha de adquisición, vida útil estimada, historial de mantenciones.

### REQ-13 — Solicitud de reposición mediante informe de novedades (FU-05, PRO-01)
- [ ] Endpoint `POST /api/incident-reports`: el docente reporta un equipo dañado/faltante al devolverlo o durante clase (descripción, foto adjunta, gravedad: leve/media/crítica).
- [ ] Notificación automática al pañolero y director de carrera.
- [ ] Cambio de estado del ítem a `en_revision` o `en_reparacion`.

### REQ-14 — Dashboards (FU-06, DIR-01)
- [ ] Endpoint `GET /api/dashboard/stats`:
  - Total de productos y desglose por estado (disponible, prestado, en reparación, baja).
  - Cantidad de préstamos activos y préstamos atrasados.
  - Alertas de stock crítico activas.
  - Tasa de rotación mensual de los 10 ítems más solicitados.
- [ ] Endpoint `GET /api/dashboard/loans-by-teacher`: préstamos agrupados por docente/asignatura para análisis de uso de recursos.

### REQ-NF-01 / REQ-NF-02 — Seguridad OWASP (Web + API)
- [x] Middleware `auth:sanctum` en todas las rutas protegidas.
- [x] Rate limiting en rutas sensibles (ej. `POST /api/login` máx. 5 intentos/minuto por IP/email).
- [x] Validación de payloads mediante `FormRequest` dedicados (nunca validar directamente en controladores).
- [x] Protección de asignación masiva mediante `$fillable` explícito en todos los modelos Eloquent.
- [x] Las contraseñas se almacenan exclusivamente con `Hash::make()` (bcrypt / argon2id).
- [ ] Logging de eventos críticos (creación/eliminación de usuarios, cambios de rol, bajas de inventario, aprobación de compras) en tabla de auditoría `audit_logs`.

### REQ-NF-03 — Accesibilidad
- [x] Estructura de respuesta de error normalizada: `{ "message": "...", "errors": { ... } }`.
- [x] Respuestas exitosas con el código HTTP semántico correspondiente (200 para lecturas/actualizaciones, 201 para creaciones, 204 para eliminaciones vacías).

### REQ-NF-04 — Rendimiento (<2s)
- [x] Paginación en todas las rutas de listado (`/api/users`, `/api/products`, `/api/loans`, etc.) vía `paginate($perPage)`.
- [ ] Eager loading explícito (`with(['relation1', 'relation2'])`) en endpoints que retornen datos relacionados para evitar problema N+1 queries.
- [ ] Índices en base de datos para columnas de búsqueda frecuente: `users.email`, `products.codigo_barra`, `products.estado`, `loans.estado`, `loans.fecha_prestamo`.

---

## 7. Convenciones de código sugeridas para agentes

- **Controladores delgados, servicios desacoplados:** Los controladores solo orquestan: reciben el `FormRequest`, llaman al servicio/modelo correspondiente y retornan un `JsonResource`.
- **FormRequests obligatorios:** Toda validación de entrada va en `app/Http/Requests/{NombreAccion}Request.php`.
- **Resources obligatorios:** Toda serialización de respuesta va en `app/Http/Resources/{NombreModelo}Resource.php`. Nunca retornar modelos Eloquent directamente.
- **Nombres de rutas en plural:** `/api/products`, `/api/loans`, `/api/suppliers`, `/api/quotations`.
- **Políticas de autorización:** Usar Laravel Policies (`app/Policies/`) para verificar si el usuario tiene el rol necesario antes de ejecutar una acción, mapeando directamente a la matriz de roles de la sección 2.
- **Migraciones irreversibles prohibidas:** Toda migración debe tener su método `down()` correctamente implementado.

---

## 8. Tecnologías

| Componente | Tecnología |
|---|---|
| Lenguaje | PHP 8.2+ |
| Framework | Laravel 11.x |
| ORM | Eloquent |
| Autenticación | Laravel Sanctum (Tokens Bearer) |
| Base de datos | PostgreSQL 16 |
| Cache / Colas | Redis |
| Storage | AWS S3 (o MinIO en desarrollo local) |
| Pruebas | PHPUnit / Pest |
| Generación de códigos de barra | `picqer/php-barcode-generator` o similar |
| OCR de facturas | AWS Textract o Tesseract OCR (vía microservicio o worker) |
| Servidor web / Container | Nginx + PHP-FPM / Docker |

---

## 9. Referencias del documento fuente
- Documento: *Informe de Diseño de Arquitectura de Software — SGIA* (INACAP Sede Temuco, 2026).
- Requisitos funcionales: FU-01 a FU-06.
- Requisitos no funcionales: REQ-NF-01 a REQ-NF-04.
