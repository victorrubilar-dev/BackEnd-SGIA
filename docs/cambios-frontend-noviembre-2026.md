# Cambios del backend para el frontend — noviembre 2026

Guía unificada de lo que hay que implementar en el panel web y en la app móvil. Cubre cinco cambios: **informe de novedades del docente**, **cotizaciones**, **compras**, **facturas** y **préstamos**.

Convenciones que no cambian: todas las rutas son `/api/...` con `Authorization: Bearer <token>`; los errores de validación son `422` con `message` y `errors`; los archivos se descargan con endpoints autenticados (no hay URLs públicas).

## Resumen y prioridad

| # | Cambio | ¿Rompe el frontend actual? | Quién lo ve |
|---|---|---|---|
| 1 | Informe de novedades: formulario nuevo del docente | **Sí** (se retiró `POST /equipment/{id}/reports`) | `PRO-01` crea; `PAN-01`, `AD-01`, `DIR-01` atienden |
| 2 | Cotizaciones: solo el director cambia el estado | **Sí** para `AD-01` (recibe `403`) | `DIR-01` |
| 3 | Compras: parten de una cotización, mínimo 3 proveedores | **Sí** (`quotation_id` obligatorio) | `DIR-01`, `AD-01` |
| 4 | Facturas: carrera detectada y presupuesto al confirmar | **Sí** (confirmar sin presupuesto da `422`) | `DIR-01`, `AD-01` |
| 5 | Préstamos: carrera obligatoria | **Sí** (`career_id` obligatorio) | `PRO-01`, `PAN-01`, `AD-01` |

Despliegue: ejecutar `php artisan migrate` (3 migraciones nuevas) y, para que el PDF del informe incluya firma y foto, instalar la extensión PHP `gd` (`apt install php-gd`).

---

## 1. Informe de novedades del docente

### 1.1 Qué cambió

- **Solo `PRO-01` crea informes.** `PAN-01`, `AD-01` y `DIR-01` reciben `403` (ocultar el botón "Nuevo informe" para ellos).
- **Se retiró `POST /api/equipment/{id}/reports`.** El informe ya no se crea desde un equipo: es un formulario por laboratorio, y el equipo es opcional.
- Los informes anteriores se conservan (`is_legacy: true`) con sus campos y estados de siempre.

### 1.2 Formulario

Campos de la pantalla, en el orden del formulario en papel:

| Campo en pantalla | Campo de la API | Obligatorio | Notas |
|---|---|---|---|
| Nombre docente | — | — | **No se envía**: lo pone el backend desde el usuario autenticado. Mostrarlo de solo lectura. |
| Nombre asignatura | `subject` | Sí | Texto libre, máx. 100. |
| Fecha | `report_date` | No | `YYYY-MM-DD`. Si no se envía es hoy. No puede ser futura (`422`). Pasadas sí. |
| Laboratorio | `location_id` | Sí | Selector con `GET /api/locations` (salas registradas). |
| Problema (casillas) | `problems` | Sí, mínimo 1 | Ver 1.3. |
| Descripción o sugerencia | `suggestion` | No | Máx. 2000. |
| Firma de profesor | `signature` | Sí | PNG en base64. Ver 1.4. |
| Foto | `evidence_photo` | No | jpg, png o webp, máx. 10 MB. |
| Equipo (buscar por código) | `equipment_code` | No | Ver 1.5. |

### 1.3 Casillas de problema

Cada casilla marcada se envía como un elemento de `problems`, con su descripción obligatoria. Las casillas sin marcar **no se envían**.

| Casilla en pantalla | `type` |
|---|---|
| Falla de equipos / instrumento / software | `falla_equipo` |
| Falta de equipamiento | `falta_equipamiento` |
| Falta de insumo | `falta_insumo` |
| Otros | `otros` |

Reglas: se pueden marcar varias a la vez; no se repite un `type`; al menos una; `description` obligatoria (máx. 1000). Al desmarcar una casilla, quitar su descripción del envío.

### 1.4 Firma

Dibujar en un `<canvas>` (web) o componente de firma (móvil) y enviar `canvas.toDataURL('image/png')` en `signature`. Se acepta con o sin el prefijo `data:image/png;base64,`. Debe ser PNG real y pesar menos de 512 KB (`422` en `signature` si no). Evitar canvas enormes: 600×200 px sobra.

### 1.5 Asociar un equipo por código (opcional)

1. El usuario escribe o escanea el código.
2. Para **mostrar** el equipo antes de enviar, llamar a `GET /api/equipment-units/by-code/{code}` (existente, lo puede usar cualquier usuario autenticado). Si responde `404`, puede ser un insumo: en ese caso no hay vista previa y se envía igual.
3. Enviar ese mismo texto en `equipment_code`. El backend resuelve:
   - código de **unidad** (equipos y maquetas) → guarda `equipment_unit_id` y `product_id`;
   - código de barras de un **insumo** → guarda `product_id`.
4. `422` en `equipment_code` si no existe, o si es el código del *modelo* de un equipo/maqueta (hay que usar el de la unidad).

### 1.6 Crear el informe

`POST /api/incident-reports`

- Rol: `PRO-01`. Límite: 10 por minuto.
- `Content-Type: application/json` si no hay foto; `multipart/form-data` si hay foto. En multipart, enviar `problems` como `problems[0][type]`, `problems[0][description]`, `problems[1][type]`, …

```json
{
  "subject": "Redes de Computadores",
  "report_date": "2026-11-05",
  "location_id": 12,
  "problems": [
    { "type": "falla_equipo", "description": "El switch del rack 2 no enciende." },
    { "type": "falta_insumo", "description": "Faltan 20 cables UTP." }
  ],
  "suggestion": "Reponer antes de la prueba del lunes.",
  "signature": "data:image/png;base64,iVBORw0KGgo...",
  "equipment_code": "EQ-IC-000123"
}
```

Respuesta `201`:

```json
{
  "message": "Informe de novedades registrado correctamente.",
  "data": {
    "id": 41, "code": "NV-2026-A1B2C3", "status": "reportado",
    "allowed_transitions": ["en_revision", "resuelto", "descartado"],
    "is_legacy": false,
    "teacher_name": "Prof. Soto", "subject": "Redes de Computadores",
    "report_date": "2026-11-05", "location_id": 12, "location": { "id": 12, "nombre": "Laboratorio 3" },
    "problems": [
      { "type": "falla_equipo", "description": "...", "label": "Falla de equipos / instrumento / software" }
    ],
    "suggestion": "Reponer antes de la prueba del lunes.",
    "has_signature": true, "signature_url": "https://.../api/incident-reports/41/signature", "signed_at": "2026-11-05T14:02:11-03:00",
    "has_evidence_photo": false,
    "pdf_url": "https://.../api/incident-reports/41/pdf",
    "product_id": null, "equipment_unit_id": null
  }
}
```

### 1.7 Pantallas y rutas

**Docente (`PRO-01`)**

| Pantalla | Ruta |
|---|---|
| Nuevo informe | `POST /api/incident-reports` |
| Mis informes (seguimiento) | `GET /api/incident-reports/mine` — filtros `status`, `per_page` |
| Detalle | `GET /api/incident-reports/{id}` |
| Descargar PDF | `GET /api/incident-reports/{id}/pdf` |
| Ver firma | `GET /api/incident-reports/{id}/signature` |
| Ver foto | `GET /api/incident-reports/{id}/evidence-photo` |

El docente solo accede a **sus** informes; el de otro docente responde `403`.

**Personal (`PAN-01`, `AD-01`, `DIR-01`)**

| Pantalla | Ruta |
|---|---|
| Bandeja | `GET /api/incident-reports` — filtros `status`, `product_id`, `equipment_unit_id`, `search`, `per_page` |
| Detalle, PDF, firma, foto | mismas rutas de arriba (acceso a cualquier informe) |
| Cambiar estado | `PATCH /api/incident-reports/{id}/status` con `{ "status": "..." }` |

Los archivos (`signature_url`, `pdf_url`, foto) **se piden con el token**: descargar con `fetch` + `Authorization` y mostrar como `blob`; una `<img src>` directa dará `401`.

### 1.8 Estados

Usar siempre `allowed_transitions` para dibujar los botones; no codificar las reglas.

| Tipo de informe | Flujo |
|---|---|
| Nuevo (`is_legacy: false`) | `reportado` → `en_revision` → `resuelto` \| `descartado`. Desde `reportado` también directo a `resuelto` o `descartado`. Si se abre una mantención desde el informe pasa a `en_reparacion` y luego a `resuelto`/`descartado`. |
| Anterior (`is_legacy: true`) | `reportado` → `en_revision` → `en_reparacion` → `reparado` \| `dado_de_baja` (como siempre). |

Valores nuevos de `status` que el frontend debe reconocer (etiqueta y color): `resuelto`, `descartado`. Un estado final no admite cambios (`422`).

### 1.9 Notificaciones

- Al crearse un informe: aviso a `PAN-01`, `AD-01` y `DIR-01` (canal `broadcast` y correo).
- Al cambiar el estado: aviso al docente que lo reportó.
- Payload de la notificación: `incident_report_id`, `code`, `status`, `message`.

### 1.10 Informes anteriores (compatibilidad)

Traen `is_legacy: true` y `teacher_name`, `subject`, `report_date`, `location_id`, `problems`, `suggestion`, `signature_url`, `pdf_url` en `null`. Conservan `severity`, `severity_label`, `request_type`, `attachment_url`. Mostrar la vista antigua cuando `is_legacy` sea `true`. El PDF (`pdf_url`) solo existe para los informes nuevos.

### 1.11 Límite conocido del PDF

El PDF incluye firma y foto solo si el servidor tiene la extensión PHP `gd`. Sin ella se genera igual, sin imágenes, indicando que la firma está registrada. No es un error del frontend.

---

## 2. Cotizaciones

### 2.1 Quién cambia el estado

`PATCH /api/quotations/{id}/status` es **solo de `DIR-01`**. `AD-01` recibe `403`: ocultar o deshabilitar "Aceptar", "Rechazar" y "Convertir" para el administrador.

El resto no cambia para `AD-01` y `DIR-01`: crear (`POST /api/quotations`), listar, ver detalle, reenviar correos y registrar respuestas de proveedores (`PATCH /api/quotations/{id}/responses`).

Flujo de estados (sin cambios): `pendiente` → `aceptada` \| `rechazada`; `aceptada` → `convertida` \| `rechazada`. Usar `allowed_transitions`.

### 2.2 Campos nuevos en la cotización

`QuotationResource` (listado, detalle y la respuesta del `PATCH .../status`):

| Campo | Significado |
|---|---|
| `responded_suppliers` | Proveedores que ya respondieron (aceptaron o rechazaron). |
| `min_quotations` | Mínimo recomendado (hoy `3`; viene del backend, no fijarlo en el cliente). |
| `meets_min_quotations` | `true` si `responded_suppliers >= min_quotations`. |

Mostrar un aviso visible (por ejemplo una insignia "2 de 3 respuestas") cuando `meets_min_quotations` sea `false`, sobre todo antes de aceptar o de generar la compra.

### 2.3 Notificaciones

**Cada cambio de estado** de una cotización avisa a `AD-01` y `DIR-01` (canal `broadcast` y correo). Esto incluye los cambios manuales del director, los automáticos cuando llegan las respuestas de los proveedores y la conversión a compra.

Payload: `quotation_id`, `code`, `from_status`, `status`, `responded_suppliers`, `min_suppliers`, `meets_min_quotations`, `message`. Si respondieron menos proveedores que el mínimo, el `message` lo indica.

---

## 3. Compras

### 3.1 Toda compra parte de una cotización aceptada

`POST /api/purchases`: **`quotation_id` es obligatorio** (`422` si falta). Ya no se crean compras "desde cero". `supplier_id` e `items` pasan a ser **opcionales**: si no se envían, el backend toma el proveedor y los ítems de la cotización (el precio sale de la oferta del proveedor).

Quitar de la pantalla de compra la opción de armar una compra sin cotización: el flujo es *elegir cotización aceptada → confirmar proveedor/ítems → presupuesto*.

### 3.2 Mínimo de 3 proveedores: recomendación con motivo

Se cuentan los proveedores que **respondieron** la cotización. No bloquea la compra, pero:

- Si respondieron **3 o más**: no se pide nada más.
- Si respondieron **menos de 3**: enviar `quotation_justification` (texto, máx. 1000), obligatorio. Sin él, `422` en `quotation_justification` con el mensaje "Respondieron menos de 3 proveedores: indica el motivo…".

Flujo de pantalla sugerido: al elegir la cotización, mostrar `responded_suppliers` y `min_quotations`; si `meets_min_quotations` es `false`, mostrar un aviso y un campo "Motivo de comprar con menos de 3 cotizaciones" obligatorio.

```json
{
  "annual_budget_id": 7,
  "quotation_id": 15,
  "quotation_justification": "Solo hay dos proveedores de este equipo en la región."
}
```

### 3.3 Campos nuevos en la compra

`PurchaseResource`:

| Campo | Cuándo viene |
|---|---|
| `quotation_justification` | Siempre (`null` si no hizo falta). |
| `min_quotations` | Siempre. |
| `responded_suppliers`, `meets_min_quotations` | Solo en el detalle (`GET /api/purchases/{id}` y la respuesta de crear), donde se carga la cotización. No vienen en el listado. |

En el detalle de la compra, mostrar el aviso y el motivo cuando `meets_min_quotations` sea `false`.

---

## 4. Facturas (ingreso con OCR)

### 4.1 Qué cambió

- El OCR intenta **detectar la carrera** en el texto de la factura: por el nombre ("Ingeniería en Ciberseguridad") o por la sigla (`ISD`, `II`, `IC`, `AP`).
- Si detecta **una sola** carrera, el borrador se crea con el **presupuesto de esa carrera del año en curso**.
- Si no detecta ninguna, detecta varias distintas, o la carrera no tiene presupuesto ese año, el borrador queda **sin presupuesto** y hay que elegirlo.
- **Ya no se puede confirmar una factura sin presupuesto**: antes ingresaba stock sin descontar; ahora responde `422` en `annual_budget_id`.

### 4.2 Campos nuevos

`InvoiceIntakeResource` (`POST/GET /api/invoice-intakes`):

| Campo | Significado |
|---|---|
| `detected_career_id` | Carrera detectada por el OCR (`null` si no hubo). |
| `needs_budget` | `true` si el borrador no tiene presupuesto: hay que pedirlo antes de confirmar. |

`POST /api/invoices/scan` devuelve además `career_id` (o `null`).

### 4.3 Flujo de pantalla

1. Subir la factura (`POST /api/invoice-intakes`, `annual_budget_id` sigue siendo opcional).
2. Si `needs_budget` es `false`: mostrar el presupuesto asignado (con la carrera detectada) y permitir cambiarlo.
3. Si `needs_budget` es `true`: mostrar un selector de presupuesto obligatorio ("No se detectó la carrera en el documento, elige el presupuesto"). Cargarlo con `GET /api/budgets` (`DIR-01` y `AD-01` ven todos los del Área de Informática).
4. Confirmar: `POST /api/invoice-intakes/{id}/confirm` con `annual_budget_id` además de lo de siempre.

```json
{
  "document_number": "4521",
  "annual_budget_id": 7,
  "items": [{ "product_id": 3, "quantity": 5, "unit_price": 250000 }]
}
```

Reglas: si el borrador ya tenía presupuesto y se envía otro `annual_budget_id`, **reemplaza** al del borrador. Si la factura está ligada a una compra, el presupuesto debe coincidir con el de la compra (`422` si no). Si el total supera lo disponible, sigue siendo `422` en `items`.

---

## 5. Préstamos

> La solicitud remota del profesor se amplió después con horario, laboratorio y pañol. Ver `docs/solicitud-prestamo-profesor.md` y la sección "Solicitud remota ampliada del profesor" de `TASKS/front-end.md`.

`career_id` pasa a ser **obligatorio** al pedir un préstamo, tanto en la solicitud remota del docente (`POST /api/loans/requests`) como en el préstamo presencial del pañol (`POST /api/loans/checkout`). Se elige en un selector con `GET /api/careers`. La asignatura (`subject`) sigue siendo texto libre.

- `LoanResource` trae `career_id` y `career` (`id`, `name`, `code`, `level`…) junto a `subject`. Mostrar **asignatura y carrera** en el listado, el detalle y los comprobantes.
- `GET /api/loans` y su exportación aceptan el filtro `career_id`.
- Los préstamos anteriores tienen `career_id: null` (mostrar "—").
- Los clientes que hoy no envían `career_id` reciben `422`: es el cambio más fácil de olvidar en la app móvil.

---

## 6. Resumen de rutas

### Nuevas

| Método | Ruta | Roles |
|---|---|---|
| `POST` | `/api/incident-reports` | `PRO-01` |
| `GET` | `/api/incident-reports/mine` | `PRO-01` |
| `GET` | `/api/incident-reports/{id}` | `AD-01`, `DIR-01`, `PAN-01`, `PRO-01` (si es suyo) |
| `GET` | `/api/incident-reports/{id}/pdf` | igual que el detalle |
| `GET` | `/api/incident-reports/{id}/signature` | igual que el detalle |

### Retiradas

| Método | Ruta | Reemplazo |
|---|---|---|
| `POST` | `/api/equipment/{id}/reports` | `POST /api/incident-reports` |

### Con cambios de contrato

| Ruta | Cambio |
|---|---|
| `PATCH /api/quotations/{id}/status` | Solo `DIR-01`. |
| `POST /api/purchases` | `quotation_id` obligatorio; `quotation_justification` si respondieron menos de 3. |
| `POST /api/invoice-intakes/{id}/confirm` | `annual_budget_id` obligatorio si `needs_budget`. |
| `POST /api/loans/requests`, `POST /api/loans/checkout` | `career_id` obligatorio. |
| `GET /api/incident-reports/{id}/attachment`, `.../evidence-photo` | También accesibles para el docente dueño. |
| `PATCH /api/incident-reports/{id}/status` | Nuevos estados `resuelto` y `descartado` para los informes nuevos. |

---

## 7. Checklist de implementación

**Informe de novedades**
- [ ] Pantalla "Nuevo informe" solo para `PRO-01` con los campos de 1.2, casillas con descripción, canvas de firma y foto opcional.
- [ ] Buscador de equipo por código (`by-code`) y envío de `equipment_code`.
- [ ] Pantalla "Mis informes" (`/mine`) con estado y descarga del PDF.
- [ ] Bandeja del personal con filtros y cambio de estado según `allowed_transitions`.
- [ ] Etiquetas y colores para `resuelto` y `descartado`; vista antigua para `is_legacy: true`.
- [ ] Descarga de firma, foto y PDF con el token.
- [ ] Quitar el botón de crear informe a `PAN-01`, `AD-01` y `DIR-01`, y cualquier llamada a `POST /api/equipment/{id}/reports`.

**Cotizaciones**
- [ ] Botones de cambio de estado solo para `DIR-01`.
- [ ] Mostrar `responded_suppliers` / `min_quotations` y aviso cuando `meets_min_quotations` sea `false`.
- [ ] Mostrar notificaciones de cambio de estado a `AD-01` y `DIR-01`.

**Compras**
- [ ] Crear compra solo desde una cotización aceptada.
- [ ] Campo "Motivo" obligatorio cuando `meets_min_quotations` es `false`.
- [ ] Mostrar el motivo y el aviso en el detalle de la compra.

**Facturas**
- [ ] Mostrar el presupuesto asignado y la carrera detectada.
- [ ] Selector de presupuesto obligatorio cuando `needs_budget` es `true`; enviar `annual_budget_id` al confirmar.

**Préstamos**
- [ ] Selector de carrera obligatorio en la solicitud y en el préstamo presencial.
- [ ] Mostrar asignatura y carrera en listado, detalle y comprobantes; filtro por `career_id`.

---

## 8. Referencias

- Catálogo completo de endpoints: `ENDPOINTS.md`.
- Historial de cambios de contrato: `TASKS/front-end.md`.
- Requisitos y pruebas: `TASKS/Informe de novedades.md`, `TASKS/Cotizaciones.md`, `TASKS/Compra.md`, `TASKS/Prestamos.md`.
