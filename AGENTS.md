# AGENTS.md — SGIA Backend API

Este archivo entrega contexto de negocio y técnico para cualquier agente de IA (Claude Code u otro) que trabaje sobre este repositorio. Léelo antes de generar o modificar código.

## 0. Alcance de este repositorio

**Este repositorio contiene únicamente la API REST backend (Laravel) del sistema SGIA.**

- No contiene el frontend web ni la app móvil: ambos viven en un monorepo aparte (`sgia-frontend`, React + React Native) que consume esta API.
- Toda funcionalidad se expone como endpoints REST (JSON), no hay vistas Blade orientadas al usuario final ni lógica de presentación.
- Responsabilidades de este repo: autenticación/autorización, reglas de negocio, persistencia (base de datos), generación de PDFs, envío de correos (cotizaciones, notificaciones), generación de códigos de barras, endpoints para dashboards/reportes.
- Fuera de alcance de este repo: renderizado de UI, escaneo físico de códigos de barras (eso ocurre en el cliente móvil/web, que envía el código leído a la API), diseño de pantallas.
- Cualquier tarea de agente debe asumir que "el usuario" es un cliente HTTP (web o móvil) consumiendo esta API, no una persona interactuando con una pantalla de este repositorio.

## 1. Contexto del proyecto

**Nombre:** SGIA – Sistema de Gestión de Inventario y Activos (API Backend)
**Cliente:** Área de Informática y Ciberseguridad, INACAP Sede Temuco
**Tipo de proyecto:** Proyecto de Título (TIHI84). Este repo es el componente backend de una solución compuesta por: API Laravel (este repo) y el monorepo `sgia-frontend` (consumidor externo), que contiene el panel web en React (Vite) y la app móvil en React Native (Expo).

### Problema que resuelve
El área gestiona hoy su pañol (bodega) e inventario de equipos/insumos de forma manual: planillas Excel, hojas de vida en Word y papel para facturas/guías de despacho. Esto genera:
- Falta de trazabilidad de equipos e insumos (no se sabe ubicación exacta ni stock real).
- Préstamos presenciales lentos (~20–25 min) y sin registro digital, solo se deja un carnet como garantía.
- Cotizaciones a proveedores redactadas manualmente por correo, sin historial ni seguimiento de estado.
- Pérdida de tiempo y sobrecarga administrativa para Directora de Carrera, Coordinadores y Pañolero.

### Objetivo del sistema
Reemplazar Excel/Word/papel por una plataforma centralizada que digitalice el ciclo de vida completo de equipos e insumos: alta por escaneo de facturas, ubicación física, préstamos (presenciales y remotos vía app), cotizaciones automatizadas por correo, fichas técnicas/mantenimiento, y dashboards de uso.

## 2. Roles de usuario (importante para permisos y RBAC)

El sistema de control de acceso se realizar mediante un Sistema de Control de Acceso Basado en Roles, el usuario debe tener un campo donde tenga uno de los siguientes roles descritos.

| Código | Rol | Qué hace |
|--------|-----|----------|
| AD-01 | Administrador de usuarios | Crea, activa/desactiva y edita usuarios y roles. |
| DIR-01 | Director de área / Coordinador | Sube facturas/fotos para alta de productos, gestiona cotizaciones, ve fichas técnicas y dashboards. |
| PAN-01 | Pañol (encargado de bodega) | Gestión física del inventario, procesa préstamos presenciales, ve ubicación y stock. |
| PRO-01 | Profesor / Docente | Solicita préstamos remotos desde app móvil, llena formularios de justificación/reposición de equipos. |

Nota: los estudiantes son beneficiarios finales pero no tienen usuario propio en el sistema (se gestionan de forma presencial vía el Pañolero).

## 3. Módulos funcionales (alto nivel)

| Código | Módulo | Resumen |
|--------|--------|---------|
| FU-01 | Autenticación y administración de usuarios | Login por rol, CRUD de usuarios (nombre, correo, rol, contraseña, área), activar/desactivar sin borrar. |
| FU-02 | Inventario, stock y ubicación | Alta/edición de productos (nombre, cantidad, proveedor, área, foto opcional, código de barras generado por el sistema), ubicación física (sala/cajón), alertas de stock crítico (aviso a 5 unidades del mínimo y al llegar al mínimo). |
| FU-03 | Automatización de cotizaciones | Selección de productos a cotizar → envío automático de correo solicitando cotización a proveedores (mínimo 3 cotizaciones antes de comprar), estados de compra (pendiente/en camino/completa). |
| FU-04 | Préstamos (remotos y presenciales) | App móvil: profesor solicita insumo/cantidad/asignatura/sala/fecha. Web: Pañolero procesa vía escaneo, acepta/rechaza (con motivo), notifica al solicitante, historial de préstamos. |
| FU-05 | Mantención y reposición de equipos | Ficha técnica descargable en PDF por equipo, formulario de informe de novedades/fallas, justificación de reemplazo. |
| FU-06 | Dashboards | Gráficos de productos/insumos más solicitados, distribución por carrera, productos menos demandados, profesores con más préstamos. |

## 4. Requisitos no funcionales clave

- **Seguridad:** controles según OWASP Top 10 (web) y OWASP API Security Top 10.
- **Rendimiento:** tiempos de carga/respuesta menores a 2 segundos con conexión estable.
- **Accesibilidad:** contraste de texto/color adecuado para todos los usuarios.
- **Disponibilidad (SLA):** L–V 08:00–22:00, sábado 08:00–15:00. Recuperación ante fallo de instancia principal ≤ 15 min. Respaldo diario de base de datos (pérdida máxima 24h).

## 5. Arquitectura

Arquitectura cliente-servidor de 3 capas, desplegada en la nube (AWS). Este repositorio implementa la **capa de lógica de negocio (API REST)**:

1. **Presentación** *(fuera de este repo)*: aplicación web administrativa + app móvil para docentes (incluye escaneo de código de barras con la cámara del dispositivo). Ambas consumen esta API vía HTTPS/JSON.
2. **Lógica de negocio** *(este repo)*: API REST en Laravel. Valida reglas de negocio, controla acceso por rol (RBAC), procesa cotizaciones, préstamos, alertas de stock, genera PDFs y expone datos para dashboards.
3. **Datos** *(este repo gestiona el acceso, la infraestructura de BD puede ser un servicio externo/gestionado)*: base de datos relacional (ver sección de tecnologías).

Restricciones importantes:
- Sistema independiente: **no se integra** con ERPs centrales de INACAP (Banner, Intranet, sistemas financieros).
- Acotado exclusivamente al Área de Informática y Ciberseguridad de la Sede Temuco.
- Los clientes (web/móvil) dependen de hardware físico (pistola lectora, impresora de códigos), pero esa integración de hardware no vive en este repositorio: la API solo recibe el valor del código escaneado.

## 5.1 Autenticación y CORS (SPA web + mobile)

Este backend debe servir de forma consistente a **dos clientes distintos**: el panel web (React/Vite, corre en el navegador, sujeto a CORS) y la app móvil (React Native/Expo, no es un navegador, no aplica CORS). La estrategia elegida evita tener dos mecanismos de auth distintos para no duplicar lógica ni casos borde.

**Paquete: Laravel Sanctum.**

Se descarta Passport (OAuth2 completo) porque no hay necesidad de emitir tokens a terceros ni flujos `authorization_code`/`client_credentials`; Sanctum cubre exactamente lo que se necesita (tokens simples tipo API) con mucho menos overhead de configuración y mantenimiento.

**Modo de uso: tokens Bearer para ambos clientes, no autenticación por cookies de Sanctum ("SPA authentication").**

Aunque Sanctum ofrece un modo especial de cookies/CSRF para SPAs en el mismo dominio (`stateful` domains), aquí se usa el modo de **personal access tokens** (Bearer) para ambos clientes por estas razones:
- El panel web y la API probablemente se despliegan en subdominios/dominios distintos dentro de AWS (o incluso separados por ahora), lo que complica el flujo de cookies + CSRF.
- Mobile no puede usar cookies de sesión de forma práctica; necesita Bearer tokens de todas formas.
- Usar el mismo mecanismo (Bearer) en ambos clientes evita mantener dos flujos de auth en paralelo y simplifica el `api-client` compartido del monorepo frontend.

Tareas concretas:
- [x] Antes de inicar las tareas el usuario debe tener como campos adicionales:
    - Ultimo inicio de sesión
    - los siguientes campos deben estar en una tabla normaizada: 
    - Navegador / movil
    - Fecha de inicio de sesión
    - IP
- [x] Crear campos necesarios para el usuario como area y rol 
- [ ] Instalar y configurar Sanctum solo para **API tokens** (no usar el middleware `EnsureFrontendRequestsAreStateful`, ya que no habrá flujo de cookies/CSRF).
- [ ] En `POST /api/login`, validar credenciales y emitir el token con `$user->createToken($deviceName)->plainTextToken`, donde `$deviceName` identifica el cliente (`web`, `mobile`) para poder listar/revocar sesiones por dispositivo si se requiere.
- [ ] Definir expiración de tokens en `config/sanctum.php` (`expiration`); considerar tokens más largos para mobile (el docente no debería re-loguearse constantemente) y más cortos para web.
- [ ] `POST /api/logout` → `$request->user()->currentAccessToken()->delete()`.
- [ ] (Opcional) Endpoint para que un usuario liste y revoque sus tokens activos por dispositivo (útil si un docente pierde el celular).

**Configuración de CORS (`config/cors.php`):**
- [ ] `paths` → `['api/*']`.
- [ ] `allowed_origins` → dominio(s) exacto(s) donde se sirva el panel web (ej. `https://sgia-web.inacap-temuco.cl`). No usar `*` en producción.
- [ ] `allowed_methods` → `['GET','POST','PATCH','PUT','DELETE','OPTIONS']`.
- [ ] `allowed_headers` → incluir `Authorization`, `Content-Type`, `Accept`.
- [ ] `supports_credentials` → `false` (no se usan cookies para auth, así que no hace falta habilitar credenciales cross-origin, lo que simplifica la configuración).
- [ ] Mobile (React Native/Expo) no pasa por el navegador en producción, por lo que **no está sujeto a CORS**; solo puede aplicar en desarrollo si se prueba la app en modo web de Expo — en ese caso agregar también ese origen de desarrollo a `allowed_origins` (idealmente solo en el entorno local, no en producción).

## 6. Tareas de backend desglosadas por requisito

Cada requisito (`REQ-XX`) del documento de formulación se traduce aquí en tareas concretas de API. Un agente puede tomar un `REQ` como unidad de trabajo/PR.

### REQ-01 — Autenticación por rol (FU-01)
- [ ] Endpoint `POST /api/login` (correo + contraseña) que devuelva token Bearer vía Sanctum (ver sección 5.1).
- [ ] Middleware `auth:sanctum` en todas las rutas protegidas; rechazar usuarios desactivados (chequeo adicional en el `User` model o en un middleware propio, Sanctum no lo hace por defecto).
- [ ] Endpoint `POST /api/logout` que revoque el token actual.
- [ ] Devolver en la respuesta de login el rol del usuario para que el cliente adapte su UI/navegación.

### REQ-02 — Administración de usuarios (FU-01, solo AD-01)
- [ ] CRUD `api/users` (nombre completo, correo, rol, contraseña, área).
- [ ] Endpoint `PATCH /api/users/{id}/status` para activar/desactivar sin eliminar de la BD.
- [ ] Policy que restrinja este CRUD únicamente al rol AD-01.
- [ ] Validación: correo único, contraseña con reglas mínimas de seguridad (hash con bcrypt/argon).

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
- [ ] Endpoint `GET /api/products/{id}/location` y filtro de listado por ubicación.

### REQ-06 — Alertas de stock crítico (FU-02)
- [ ] Definir `stock_minimo` por producto.
- [ ] Job/evento que dispare alerta cuando el stock llegue a `minimo + 5` y cuando llegue al `minimo`.
- [ ] Notificación multicanal (in-app/web + push a móvil) a usuarios AD-01 y PAN-01. (Canal de push a definir en Tecnologías.)

### REQ-07 — Envío de cotizaciones automáticas (FU-03, DIR-01)
- [ ] Endpoint `POST /api/quotations` que reciba lista de productos + cantidades.
- [ ] Servicio de envío de correo automático a **al menos 3 proveedores** por cotización.
- [ ] Persistir cada cotización con su estado y proveedores contactados.
- [ ] Validación: mínimo 1 producto, cantidad positiva, mínimo 3 proveedores.

### REQ-08 — Estado de compra (FU-03, DIR-01)
- [ ] Máquina de estados para `Quotation`/`Purchase`: `pendiente` → `en_camino` → `completa`.
- [ ] Endpoint para actualizar estado a `completa` al escanear la guía de despacho/factura de llegada.
- [ ] Endpoint `GET /api/purchases` con filtro por estado.

### REQ-09 — Solicitud de préstamo remoto (FU-04, PRO-01)
- [ ] Endpoint `POST /api/loans/requests` (insumo/producto, cantidad, asignatura, sala, fecha).
- [ ] Endpoint `GET /api/loans/requests?estado=en_proceso` para listar solicitudes propias del docente.
- [ ] Respuesta debe confirmar "solicitud enviada" y devolver el registro creado.

### REQ-10 — Procesamiento de préstamos presenciales/remotos (FU-04, PAN-01)
- [ ] Endpoint `GET /api/loans/pending` (incluye cantidad solicitada, disponible y ubicación).
- [ ] Endpoint `POST /api/loans/{id}/approve` → descuenta stock y notifica al solicitante.
- [ ] Endpoint `POST /api/loans/{id}/reject` (requiere motivo) → notifica al solicitante.
- [ ] Endpoint para registrar préstamo presencial directo (profesor/estudiante, insumo, cantidad, asignatura, sala, fecha).

### REQ-11 — Historial y listado de préstamos (FU-04, PAN-01)
- [ ] Endpoint `GET /api/loans` con filtros (insumo, profesor, sala, estado).
- [ ] Diferenciar en la respuesta préstamos `procesados` vs `en_proceso` (campo de estado explícito para que el cliente aplique el estilo visual).

### REQ-12 — Fichas técnicas de equipos (FU-05, DIR-01)
- [ ] Endpoint `GET /api/equipment/{id}/technical-sheet` que genere/devuelva PDF de la ficha técnica.
- [ ] Endpoint `GET /api/equipment/{id}/reports` para listar informes de novedades asociados (también descargables en PDF).

### REQ-13 — Solicitud de reposición mediante informe de novedades (FU-05, PRO-01)
- [ ] Endpoint `POST /api/equipment/{id}/reports` que acepte formulario + adjunto opcional (documento/imagen).
- [ ] Asociar el informe al equipo para trazabilidad en su hoja de vida.

### REQ-14 — Dashboards (FU-06, DIR-01)
- [ ] Endpoint `GET /api/dashboard/top-products` (más solicitados).
- [ ] Endpoint `GET /api/dashboard/top-supplies` (insumos más solicitados).
- [ ] Endpoint `GET /api/dashboard/careers-distribution`.
- [ ] Endpoint `GET /api/dashboard/least-demanded`.
- [ ] Endpoint `GET /api/dashboard/top-teachers`.

### REQ-NF-01 / REQ-NF-02 — Seguridad OWASP (Web + API)
- [ ] Validar inputs con Form Requests en todos los endpoints (evitar inyección SQL/NoSQL).
- [ ] Rate limiting en endpoints sensibles (login, envío de cotizaciones).
- [ ] Sanitizar/validar archivos subidos (fotos, PDFs, facturas).
- [ ] Autorización explícita (Policies/Gates) en cada endpoint, nunca solo por rol implícito en el frontend.
- [ ] Revisar cabeceras de seguridad, CORS restringido a los dominios/clientes autorizados, secretos fuera del código fuente.

### REQ-NF-03 — Accesibilidad
- No aplica directamente a este repo (es responsabilidad del frontend/app móvil). Este repo debe asegurar que las respuestas incluyan datos suficientes (ej. estados, mensajes claros) para que el cliente pueda cumplir accesibilidad.

### REQ-NF-04 — Rendimiento (<2s)
- [ ] Índices de base de datos en columnas de búsqueda/filtrado frecuente (código de barras, estado, fecha).
- [ ] Paginación obligatoria en todos los listados.
- [ ] Cachear resultados de dashboards si son costosos de calcular.
- [ ] Colas (queues) para tareas pesadas o lentas: envío de correos de cotización, generación de PDFs, notificaciones push.

## 7. Convenciones de código sugeridas para agentes

- Seguir las convenciones estándar de Laravel (PSR-12, Eloquent para el ORM, Form Requests para validación, Policies/Gates para RBAC por rol).
- Nombrar recursos/controladores en base a los módulos FU-01 a FU-06 para mantener trazabilidad con los requisitos (REQ-01 a REQ-14, REQ-NF-01 a REQ-NF-04) definidos en el documento de formulación del proyecto.
- Cualquier campo de producto/insumo debe soportar: nombre, cantidad, proveedor, área, foto (opcional), código de barras (autogenerado) y estado (activo/inactivo, nunca eliminar en duro).
- Las fichas técnicas e informes de novedades deben poder exportarse/descargarse como PDF.
- Las notificaciones (stock crítico, préstamos) deben considerar tanto web como móvil.

## 8. Tecnologías

- **Backend:** Laravel (PHP), API REST.
- **Autenticación:** Laravel Sanctum — tokens Bearer (personal access tokens) para ambos clientes (web y mobile), sin flujo de cookies/CSRF de SPA (ver sección 5.1).
- **Base de datos:** PostgreSQL.
- **Infraestructura / despliegue:** AWS EC2 (instancias para API/Laravel y para PostgreSQL, cada una con réplica de respaldo).
- **Consumidores de esta API (repos externos):**
  - Frontend web: React + Vite (monorepo `sgia-frontend`).
  - App móvil: React Native + Expo (mismo monorepo `sgia-frontend`).
- **Generación de PDFs** (fichas técnicas, informes): *(definir librería, ej. dompdf o barryvdh/laravel-dompdf, o snappy/wkhtmltopdf si se requiere mejor fidelidad de diseño)*.
- **Generación de códigos de barras:** *(definir librería, ej. picqer/php-barcode-generator o milon/barcode)*.
- **Envío de correos** (cotizaciones automáticas): mailer nativo de Laravel (Mailables + Queues) sobre el proveedor SMTP/SES que se configure.
- **Colas/jobs:** Laravel Queues (database o SQS) para envío de correos, generación de PDFs y notificaciones sin bloquear la respuesta HTTP.
- **Testing:** PHPUnit / Pest para tests de feature (endpoints) y unitarios de reglas de negocio.

## 9. Referencias del documento fuente

Este archivo se basa en "Formulación del Proyecto de Título - SGIA (Sistema Gestión de Inventario y Activos)", INACAP Sede Temuco, sección TIHI84, entregado 09-09-2026. Consultar ese documento para el detalle completo de requisitos funcionales (Tablas 6–19), no funcionales (Tablas 20–23), matriz RACI, cronograma y presupuesto.
