# Solicitud remota de préstamo del profesor

Especificación de `POST /api/loans/requests` y sus lecturas: el profesor (`PRO-01`) pide insumos y equipos para una actividad indicando carrera, asignatura, fecha, horario, laboratorio y pañol. Corresponde al requisito REQ-21 del panel web del profesor (no confundir con el REQ-21 de reservas de presupuesto de `TASKS/Compra.md`). Seguimiento en `TASKS/Prestamos.md`; cambios de contrato para el frontend en `TASKS/front-end.md`.

## Datos de la solicitud

| Dato | Campo de la API | Reglas |
|---|---|---|
| Profesor | (token) | Siempre el usuario autenticado con rol `PRO-01`. `user_id`, `teacher_id` y `requested_by` del cuerpo se ignoran. |
| Carrera | `career_id` | Obligatorio; debe existir en `careers`. **No** se restringe a las carreras del profesor (en una emergencia puede no tener ninguna asignada). |
| Asignatura | `subject` (alias `asignatura`) | Obligatorio, texto, máx. 255. |
| Fecha de la actividad | `loan_date` (alias `fecha`) | Obligatorio, `AAAA-MM-DD`, hoy o futura. |
| Hora de inicio | `start_time` | Obligatorio, `HH:MM` (24 h). |
| Hora de término | `end_time` | Obligatorio, `HH:MM`, estrictamente posterior a `start_time`. |
| Laboratorio | `location_id` | Obligatorio; debe existir en `locations`, ser de tipo `sala` o `taller` y pertenecer al pañol indicado. |
| Pañol | `storeroom_id` | Obligatorio; debe existir en `storerooms`. Es el pañol que prepara la solicitud. |
| Insumos | `items[].product_id`, `items[].quantity` | Mínimo 1 ítem; `product_id` único dentro de la solicitud; `quantity` entero ≥ 1. El producto debe existir, estar activo y tener stock suficiente. |
| Vencimiento | `due_date` | Opcional; no anterior a `loan_date` (por defecto, la misma fecha). |
| Notas | `notes` | Opcional, máx. 1000. |

Todas las reglas responden `422` con `message` y `errors` por campo, en español.

## Campos derivados

Para que la cola del pañol, las exportaciones y los clientes antiguos sigan funcionando, al crear la solicitud se rellenan solos:

- `time_block` = `"HH:MM - HH:MM"` (a partir de `start_time` y `end_time`).
- `room` = nombre del laboratorio (`locations.nombre`).

## Retrocompatibilidad (clientes antiguos)

Un cliente es **antiguo** cuando envía `room` y no envía `location_id` ni `storeroom_id`. En ese caso:

- `room` y `time_block` se aceptan como texto libre y se guardan tal cual.
- Si `time_block` tiene la forma `HH:MM - HH:MM` y no llegan `start_time`/`end_time`, las horas se derivan de ahí.
- Si llegan `start_time`/`end_time`, `time_block` se ignora y se regenera.
- No se guardan `location_id` ni horario si no se pueden derivar; el pañol queda en el pañol por defecto (`PAN-TEMUCO`).
- `career_id` sigue siendo obligatorio (regla vigente para todos los clientes).

## Disponibilidad de stock

La solicitud se valida contra el **stock actual**: en equipos y maquetas, las unidades `disponible` del pañol elegido; en insumos, la cantidad total del producto. **No** se consideran otras solicitudes pendientes ni el rango horario: el stock se descuenta recién cuando el pañol aprueba la solicitud (`POST /api/loans/{id}/approve`), que vuelve a validar.

Hoy el panel muestra `quantity`, que suma todos los pañoles, por lo que puede mostrar existencias que el pañol elegido no tiene. La corrección está planificada en `docs/disponibilidad-por-panol.md`.

## Autorización

| Operación | Roles |
|---|---|
| `POST /api/loans/requests` | Solo `PRO-01` (los demás reciben `403`). |
| `GET /api/loans/requests` | Solo `PRO-01`, únicamente sus propias solicitudes. |
| `DELETE /api/loans/requests/{id}` | Solo `PRO-01`, solo las propias (`403` si es ajena, `422` si ya no está `pendiente`). |
| `GET /api/loans/pending` (cola) | `PAN-01` y `AD-01`. |
| Ver y operar una solicitud (`GET /api/loans/{id}`, `/review`, `/approve`, `/reject`, `/deliver`, `POST /api/loans/checkin`) | `PAN-01` solo si es de su pañol (`403` si no); `AD-01` todas. `GET /api/loans/{id}` también permite a `DIR-01` ver todas y al profesor las suyas. |

### Cola del pañol

`PAN-01` ve únicamente las solicitudes pendientes de **su** pañol (`users.storeroom_id`; si no tiene uno asignado, las del pañol por defecto). `AD-01` ve todas y puede filtrar con `?storeroom_id=`.

El pañol que elige el profesor decide quién atiende la solicitud: un `PAN-01` de otro pañol no la ve en la cola ni en el historial, la exportación o el historial del estudiante, y tampoco puede operarla aunque conozca su id. La misma regla vale para los préstamos presenciales, que además solo puede registrar en su pañol, y para el préstamo que muestra la ficha o la hoja de vida de una unidad. Pruebas en `tests/Feature/LoanStoreroomAccessTest.php`.

## Respuesta

`POST` responde `201` con `"Solicitud enviada correctamente."` y las claves `data` y `loan` (iguales). Campos agregados al préstamo:

| Campo | Contenido |
|---|---|
| `career_id`, `career` | `career`: `{id, name, code, level, …}` |
| `storeroom_id`, `storeroom` | `storeroom`: `{id, name, code}` |
| `location_id`, `location` | `location`: `{id, nombre, tipo}` |
| `start_time`, `end_time` | `HH:MM` (o `null` en préstamos antiguos) |
| `time_block`, `room` | Texto derivado (ver arriba) |
| `teacher` | `{id, name}`: el profesor autenticado, solo lectura |
| `items[].product` | Producto (`id`, `name`, `barcode`, …) y `quantity` |

Las relaciones (`career`, `storeroom`, `location`, `teacher`) vienen en el alta, el listado propio, la cola, el historial y el detalle. Los préstamos anteriores traen `null` en los campos nuevos.

```json
{
  "career_id": 3,
  "subject": "Redes de Computadores",
  "loan_date": "2026-11-12",
  "start_time": "08:30",
  "end_time": "10:00",
  "storeroom_id": 1,
  "location_id": 12,
  "items": [{ "product_id": 45, "quantity": 3 }],
  "notes": "Traer cables UTP."
}
```

## Filtros de lectura

- `GET /api/loans/requests`: `estado` (alias `status`), `storeroom_id`, `career_id`, `loan_date`, `per_page`.
- `GET /api/loans` (historial, `PAN-01`/`DIR-01`/`AD-01`) y `GET /api/loans/export`: además `career_id`, `storeroom_id`, `location_id` y `loan_date`.
- `GET /api/locations`: `tipo`, `storeroom_id`, `search`, `per_page`. Devuelve `id`, `nombre`, `tipo`, `storeroom_id`.

## Catálogos para los selectores del profesor

| Ruta | Acceso de `PRO-01` |
|---|---|
| `GET /api/storerooms` | Lectura (todos los roles autenticados). |
| `GET /api/locations?tipo=sala\|taller&storeroom_id={id}` | Lectura (todos los roles autenticados). |
| `GET /api/careers` y `GET /api/careers/{id}` | **Lectura agregada** para `PRO-01` (solo lectura; el listado es completo, no depende de sus carreras). |
| `GET /api/products?is_active=1` | Lectura (todos los roles autenticados); `quantity` es el stock disponible total, sin separar por pañol. |

## Base de datos

Migración `2026_11_15_000000_add_schedule_and_laboratory_to_loans`:

- `loans.location_id`: FK nullable a `locations` (`nullOnDelete`), con índice.
- `loans.start_time` y `loans.end_time`: `time`, nullable.
- Backfill: las filas con `time_block` `HH:MM - HH:MM` reciben `start_time` y `end_time`.
- `loans.career_id` (migración `2026_11_13_000000`) y `loans.storeroom_id` ya existían.
- `time_block` y `room` se conservan.

## Pruebas

`tests/Feature/LoanRemoteRequestTest.php` cubre: alta completa con todos los campos, el profesor solo desde el token, profesor sin carreras asignadas, cada regla con su `422`, laboratorio de otro pañol o de tipo pañol/bodega, clientes antiguos, autorización por rol, filtros del listado propio, cola por pañol y catálogos. `LoanRequestTest` conserva los casos del flujo original y `CareerManagementTest` fija el acceso de solo lectura del docente a las carreras.
