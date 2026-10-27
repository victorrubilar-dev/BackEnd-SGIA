# AGENTS.md — SGIA Backend API

> ⚠️ **REGLA ESTRICTA DE GOBERNANZA PARA AGENTES:**
> **ESTE ARCHIVO ES DE SOLO LECTURA PARA AGENTES DE IA.**
> **Queda terminantemente prohibido modificar, sobreescribir, alterar o truncar este archivo (`AGENTS.md`) sin la autorización previa, explícita y directa del usuario.** Ningún subagente o proceso automatizado puede editar este documento bajo ninguna circunstancia.

---

## 0. Alcance de este repositorio

**Este repositorio contiene únicamente la API REST backend (Laravel) del sistema SGIA.**

- No contiene el frontend web administrativo ni la app móvil Flutter: esos son repositorios/proyectos separados que consumen esta API.
- Toda funcionalidad se expone como endpoints REST (JSON), no hay vistas Blade orientadas al usuario final ni lógica de presentación.
- Responsabilidades de este repo: autenticación/autorización, reglas de negocio, persistencia (base de datos), generación de PDFs, envío de correos (cotizaciones, notificaciones), generación de códigos de barras, endpoints GRAPHQL para dashboards/reportes.
- Fuera de alcance de este repo: renderizado de UI, escaneo físico de códigos de barras (eso ocurre en el cliente móvil/web, que envía el código leído a la API), diseño de pantallas.
- Cualquier tarea de agente debe asumir que "el usuario" es un cliente HTTP (web o móvil) consumiendo esta API, no una persona interactuando con una pantalla de este repositorio.

---

## 1. Contexto del proyecto

**Nombre:** SGIA – Sistema de Gestión de Inventario y Activos (API Backend)  
**Cliente:** Área de Informática y Ciberseguridad, INACAP Sede Temuco  
**Tipo de proyecto:** Proyecto de Título (TIHI84). Este repo es el componente backend de una solución compuesta por: API Laravel (este repo), app web administrativa (consumidor externo) y app móvil Flutter (consumidor externo).

### Problema que resuelve
El área gestiona hoy su pañol (bodega) e inventario de equipos/insumos de forma manual: planillas Excel, hojas de vida en Word y papel para facturas/guías de despacho. Esto genera:
- Falta de trazabilidad de equipos e insumos (no se sabe ubicación exacta ni stock real).
- Préstamos presenciales lentos (~20–25 min) y sin registro digital, solo se deja un carnet como garantía.
- Cotizaciones a proveedores redactadas manualmente por correo, sin historial ni seguimiento de estado.
- Pérdida de tiempo y sobrecarga administrativa para Directora de Carrera, Coordinadores y Pañolero.

### Objetivo del sistema
Reemplazar Excel/Word/papel por una plataforma centralizada que digitalice el ciclo de vida completo de equipos e insumos: alta por escaneo de facturas, ubicación física, préstamos (presenciales y remotos vía app), cotizaciones automatizadas por correo, fichas técnicas/mantenimiento, y dashboards de uso.

---

## 2. Roles de usuario (importante para permisos y RBAC)

| Código | Rol | Qué hace |
|--------|-----|----------|
| **AD-01** | Administrador de usuarios | Crea, activa/desactiva y edita usuarios y roles. Acceso de auditoría total. |
| **DIR-01** | Director de área / Coordinador | Sube facturas/fotos para alta de productos, gestiona cotizaciones, ve fichas técnicas y dashboards. |
| **PAN-01** | Pañol (encargado de bodega) | Gestión física del inventario, procesa préstamos presenciales, ve ubicación y stock. |
| **PRO-01** | Profesor / Docente | Solicita préstamos remotos desde app móvil, llena formularios de justificación/reposición de equipos. |

*Nota: los estudiantes son beneficiarios finales pero no tienen usuario propio en el sistema (se gestionan de forma presencial vía el Pañolero).*

---

## 3. Módulos funcionales (alto nivel)

| Código | Módulo | Resumen |
|--------|--------|---------|
| **FU-01** | Autenticación y administración de usuarios | Login por rol, CRUD de usuarios (nombre, correo, rol, contraseña, área), activar/desactivar sin borrar. |
| **FU-02** | Inventario, stock y ubicación | Alta/edición de productos (nombre, cantidad, proveedor, área, foto opcional, código de barras generado por el sistema), ubicación física (sala/cajón), alertas de stock crítico (aviso a 5 unidades del mínimo y al llegar al mínimo). |
| **FU-03** | Automatización de cotizaciones | Selección de productos a cotizar → envío automático de correo solicitando cotización a proveedores (mínimo 3 cotizaciones antes de comprar), estados de compra (pendiente/en camino/completa). |
| **FU-04** | Préstamos (remotos y presenciales) | App móvil: profesor solicita insumo/cantidad/asignatura/sala/fecha. Web: Pañolero procesa vía escaneo, acepta/rechaza (con motivo), notifica al solicitante, historial de préstamos. |
| **FU-05** | Mantención y reposición de equipos | Ficha técnica descargable en PDF por equipo, formulario de informe de novedades/fallas, justificación de reemplazo. |
| **FU-06** | Dashboards | Gráficos de productos/insumos más solicitados, distribución por carrera, productos menos demandados, profesores con más préstamos. |

---

## 4. Requisitos no funcionales clave

- **Seguridad:** Controles según OWASP Top 10 (web) y OWASP API Security Top 10.
- **Rendimiento:** Tiempos de carga/respuesta menores a 2 segundos con conexión estable.
- **Accesibilidad:** Estructura normalizada de errores y respuestas para que el cliente adapte interfaces accesibles.
- **Disponibilidad (SLA):** L–V 08:00–22:00, sábado 08:00–15:00. Recuperación ante fallo de instancia principal ≤ 15 min. Respaldo diario de base de datos (pérdida máxima 24h).

---

## 5. Arquitectura

Arquitectura cliente-servidor de 3 capas, desplegada en la nube (AWS). Este repositorio implementa la **capa de lógica de negocio (API REST)**:

1. **Presentación** *(fuera de este repo)*: Aplicación web administrativa + app móvil para docentes (incluye escaneo de código de barras con la cámara del dispositivo). Ambas consumen esta API vía HTTPS/JSON.
2. **Lógica de negocio** *(este repo)*: API REST en Laravel. Valida reglas de negocio, controla acceso por rol (RBAC), procesa cotizaciones, préstamos, alertas de stock, genera PDFs y expone datos para dashboards.
3. **Datos** *(este repo gestiona el acceso, la infraestructura de BD puede ser un servicio externo/gestionado)*: Base de datos relacional (PostgreSQL 16).

Restricciones importantes:
- Sistema independiente: **no se integra** con ERPs centrales de INACAP (Banner, Intranet, sistemas financieros).
- Acotado exclusivamente al Área de Informática y Ciberseguridad de la Sede Temuco.
- Los clientes (web/móvil) dependen de hardware físico (pistola lectora, impresora de códigos), pero esa integración de hardware no vive en este repositorio: la API solo recibe el valor del código escaneado.

---

## 6. Tareas de backend desglosadas por requisito

### REQ-01 — Autenticación por rol (FU-01)
- [x] Endpoint `POST /api/login` (correo + contraseña) que devuelva token Bearer vía Laravel Sanctum.
- [x] Middleware `active` que valide token en cada request y rechace usuarios desactivados.
- [x] Endpoint `POST /api/logout` y `POST /api/logout-all` para invalidar tokens activos.
- [x] Devolver en la respuesta de login el rol del usuario para que el cliente adapte su UI.

### REQ-02 — Administración de usuarios (FU-01, solo AD-01)
- [x] CRUD `api/users` (nombre completo, correo, rol, contraseña, área).
- [x] Endpoint `PATCH /api/users/{id}/status` para activar/desactivar sin eliminar de la BD.
- [x] Policy que restrinja este CRUD únicamente al rol AD-01.
- [x] Validación: correo único, contraseña con reglas mínimas de seguridad (hash con bcrypt/argon).

### REQ-03 — Alta/edición de productos vía escaneo de facturas (FU-02, DIR-01)
- [x] Endpoint que reciba imagen/documento de factura y devuelva un borrador de producto(s) extraído(s) (nombre, cantidad, proveedor) para confirmación posterior.
- [x] Endpoint `POST /api/products` para confirmar el borrador y crear el producto (nombre, cantidad, proveedor, área, foto opcional).
- [x] Endpoint `PATCH /api/products/{id}` para editar y `PATCH /api/products/{id}/status` para activar/desactivar.

### REQ-04 — Alta/edición de productos por Pañol (FU-02, PAN-01)
- [x] Mismo CRUD de REQ-03 pero accesible también a PAN-01.
- [x] Generación automática de **código de barras único** al crear el producto (servicio interno, ej. Code128/EAN).
- [x] Validación: nombre sin caracteres especiales, cantidad positiva, proveedor existente.

### REQ-05 — Ubicación física de productos (FU-02)
- [x] Campos `sala` y `cajón` (o entidades `Location` y `Cajon`) asociados a cada producto/ítem.
- [x] Endpoint `GET /api/products/{id}/location` y `PATCH /api/products/{id}/location` para consultar y actualizar ubicación física.

### REQ-06 — Alertas de stock crítico (FU-02)
- [x] Campo `stock_minimo` por producto.
- [x] Job programado / trigger en evento de rebaja de stock que detecte si `stock_actual <= stock_minimo`.
- [x] Notificación vía email (y registro en BD para panel web) al Director de Carrera (DIR-01) y Pañolero (PAN-01).
- [x] Endpoint `GET /api/alerts/critical-stock` para el panel web / mobile.

### REQ-07 — Envío de cotizaciones automáticas (FU-03, DIR-01)
- [x] CRUD de proveedores (`api/suppliers`): nombre empresa, contacto, email, teléfono, rubro/categoría de insumos.
- [x] Endpoint `POST /api/quotations` que reciba lista de productos requeridos + selección de proveedores destinatarios (mínimo 3).
- [x] Registro en BD del historial de cotizaciones enviadas con fecha, usuario emisor y proveedores contactados.

### REQ-08 — Estado de compra (FU-03, DIR-01)
- [x] Máquina de estados para compras: `pendiente` → `en_camino` → `completa`.
- [x] Endpoint `GET /api/purchases` y `PATCH /api/purchases/{id}/status` para cambiar estado (con registro de timestamp y usuario que autorizó).
- [x] Endpoint `POST /api/purchases/{id}/arrival-scan` para marcar orden como `completa` al escanear guía/factura.

### REQ-09 — Solicitud de préstamo remoto (FU-04, PRO-01)
- [x] Endpoint `POST /api/loans/requests` para que el docente solicite ítems: fecha y bloque horario de la clase, asignatura, lista de ítems solicitados.
- [x] Endpoint `GET /api/loans/requests` y `GET /api/loans/my-requests` para listar solicitudes propias del docente.
- [x] Respuesta debe confirmar "solicitud enviada" y devolver el registro creado.

### REQ-10 — Procesamiento de préstamos presenciales/remotos (FU-04, PAN-01)
- [x] Endpoint `GET /api/loans/pending` (incluye cantidad solicitada, disponible y ubicación).
- [x] Endpoint `POST /api/loans/{id}/approve` → descuenta stock y notifica al solicitante.
- [x] Endpoint `POST /api/loans/{id}/reject` (requiere motivo) → notifica al solicitante.
- [x] Endpoint `POST /api/loans/checkout` para registrar préstamo presencial directo.
- [x] Endpoint `POST /api/loans/{id}/deliver` para entrega material.

### REQ-11 — Historial y listado de préstamos (FU-04, PAN-01)
- [x] Endpoint `GET /api/loans` con filtros por: fecha, docente, estado, sala, asignatura.
- [x] Diferenciar en la respuesta préstamos `procesados` vs `en_proceso` (campo de estado explícito para que el cliente aplique el estilo visual).

### REQ-12 — Fichas técnicas de equipos (FU-05, DIR-01)
- [x] Endpoint `GET /api/equipment/{id}/technical-sheet` que genera y descarga PDF de la ficha técnica.
- [x] Endpoint `GET /api/equipment/{id}/reports` para listar informes de novedades asociados (también descargables en PDF con `?format=pdf`).

### REQ-13 — Solicitud de reposición mediante informe de novedades (FU-05, PRO-01)
- [x] Endpoint `POST /api/equipment/{id}/reports` que acepta formulario + adjuntos opcionales (documento/imagen).
- [x] Asociar el informe al equipo para trazabilidad en su hoja de vida.

### REQ-14 — Dashboards (FU-06, DIR-01)
- [x] Endpoints REST de demanda implementados (`top-products`, `top-supplies`, `careers-distribution`, `least-demanded`, `top-teachers`).
- [ ] Implementación de GraphQL en `POST /api/metrics` según especificación de `TASKS/Dashboards.md`.

---

## 7. Especificaciones de Generación de Códigos Únicos
los codigos debe generarse mediante el estandar de codigo code-128 usando la siguiente estructura: 
PREFIJO-CARRERA-CODIGO

MAQ-AP-000001 -> maquetas
INS-ISD-000002 -> insumos
PRO-IC-000003 -> productos

carreras {
ISD => Telecomunicaciones
II => Ingenieria en informatica
IC => Ingenieria en ciberseguridad
AP => Analista programador
}

---

## 8. Convenciones de código sugeridas para agentes

- Seguir las convenciones estándar de Laravel (PSR-12, Eloquent para el ORM, Form Requests para validación, Policies/Gates para RBAC por rol).
- Nombrar recursos/controladores en base a los módulos FU-01 a FU-06 para mantener trazabilidad con los requisitos.
- Los controladores solo orquestan: reciben el `FormRequest`, llaman al servicio/modelo correspondiente y retornan un `JsonResource`. Nunca retornar modelos Eloquent directamente.
- Paginación obligatoria en todas las rutas de listado vía `paginate($perPage)`.
- Toda migración debe tener su método `down()` correctamente implementado.

---

## 9. Tecnologías

- **Backend:** PHP 8.2+ / Laravel 11.x
- **Autenticación:** Laravel Sanctum (Tokens Bearer)
- **Base de datos:** PostgreSQL 16
- **Generación de PDFs:** Dompdf (`barryvdh/laravel-dompdf`)
- **Testing:** PHPUnit 11
- **Contenedores:** Docker / Docker Compose

---

## 10. Referencias del documento fuente

Este archivo se basa en "Formulación del Proyecto de Título - SGIA (Sistema Gestión de Inventario y Activos)", INACAP Sede Temuco, sección TIHI84.
