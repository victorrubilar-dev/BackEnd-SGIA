# Ubicación de las unidades asignadas a un préstamo

Especificación para mostrarle al pañol (`PAN-01`) dónde está físicamente cada unidad de equipo o maqueta asignada a un préstamo, para que la retire del lugar correcto, y para que esas unidades salgan solo del pañol al que se le hizo la solicitud. Estado: **pendiente de implementar**. Seguimiento en `TASKS/Prestamos.md`; cambios de contrato para el frontend en `TASKS/front-end.md`.

## Contexto

Las unidades de una solicitud remota no las elige nadie: el sistema las asigna solo al aprobar.

| Paso | Ruta | Qué pasa con las unidades |
|---|---|---|
| Solicitud del profesor | `POST /api/loans/requests` | Solo producto y cantidad. No se asigna ni se reserva ninguna unidad. |
| Aprobación | `POST /api/loans/{id}/approve` | Se asignan las primeras N unidades `disponible` del producto (por `id`), pasan a `prestado` y se vinculan en `loan_item_units`. |
| Entrega | `POST /api/loans/{id}/deliver` | El pañolero puede reemplazar las unidades asignadas con `items[].unit_ids`; las que suelta vuelven a `disponible`. |

Hoy `items[].units[]` solo trae `id`, `code`, `serial_number`, `status`, `returned_at` y `return_condition`. La ubicación física de una unidad vive en la unidad (`equipment_units.cajon_id` → cajón → sala), no en el producto, así que la ubicación que ya viene en `items[].location` / `items[].cajon` (la del producto) no sirve para encontrar una unidad concreta.

## Alcance

- Solo productos con unidades (`equipo` y `maqueta`). Los insumos ya traen la ubicación del producto en `items[].location` / `items[].cajon` y no cambian.
- La ubicación se ve **desde que el préstamo queda `en_proceso`**, porque recién ahí tiene unidades. En `pendiente`, `units` viene vacío.
- Como es el mismo Resource, los préstamos presenciales (`POST /api/loans/checkout`) y los ya procesados también traen la ubicación de sus unidades.

## Unidades solo del pañol solicitado

Una unidad pertenece al pañol de la sala de su cajón (`equipment_units.cajon_id` → `cajones.location_id` → `locations.storeroom_id`; los tres son obligatorios). Hoy la asignación automática toma cualquier unidad `disponible` del producto, sin mirar el pañol, así que una solicitud del pañol A puede llevarse una unidad guardada en el pañol B. Desde este cambio, todo el flujo usa solo unidades del pañol del préstamo (`loans.storeroom_id`):

| Momento | Regla |
|---|---|
| Solicitud del profesor (`POST /api/loans/requests`) | La disponibilidad de equipos y maquetas cuenta solo las unidades `disponible` del `storeroom_id` pedido (clientes antiguos sin `storeroom_id`: el pañol por defecto). Si no alcanzan, `422` en `items.{i}.quantity`, aunque haya unidades en otro pañol. |
| Aprobación (`POST /api/loans/{id}/approve`) | La asignación automática toma solo unidades del pañol del préstamo. Si no alcanzan, `422` en `items` y la solicitud sigue `pendiente`. |
| Préstamo presencial (`POST /api/loans/checkout`) | La disponibilidad y las `unit_ids` indicadas deben ser del pañol del préstamo (`storeroom_id`, o el del pañolero, o el por defecto). Una unidad de otro pañol responde `422`. |
| Entrega (`POST /api/loans/{id}/deliver`) | Las unidades de reemplazo en `items[].unit_ids` deben ser del pañol del préstamo. Una de otro pañol responde `422` en `items.{i}.unit_ids`. |

Los insumos no cambian: se siguen validando por la cantidad del producto.

Los mensajes de error nombran el pañol, por ejemplo: `Stock insuficiente para "Router Cisco 1941" en Pañol Temuco: se solicitaron 2 y hay 1 disponibles.`

## Contrato de la API

Cada elemento de `items[].units[]` agrega dos claves, siempre presentes:

| Clave | Contenido |
|---|---|
| `cajon` | `{id, codigo, descripcion}` |
| `location` | `{id, nombre, tipo, storeroom_id}`: sala o taller del cajón. |

Toda unidad tiene cajón (`equipment_units.cajon_id` es obligatorio), así que ninguna de las dos viene en `null`.

```json
{
  "id": 81,
  "product_id": 45,
  "name": "Router Cisco 1941",
  "quantity": 2,
  "delivered_quantity": 0,
  "units": [
    {
      "id": 301,
      "code": "PRO-IC-000014",
      "serial_number": "FTX1840ABCD",
      "status": "prestado",
      "returned_at": null,
      "return_condition": null,
      "cajon": { "id": 7, "codigo": "C-02", "descripcion": "Routers" },
      "location": { "id": 12, "nombre": "Laboratorio de Redes", "tipo": "sala", "storeroom_id": 1 }
    },
    {
      "id": 305,
      "code": "PRO-IC-000018",
      "serial_number": null,
      "status": "prestado",
      "returned_at": null,
      "return_condition": null,
      "cajon": { "id": 9, "codigo": "C-04", "descripcion": "Equipos de respaldo" },
      "location": { "id": 14, "nombre": "Bodega de Redes", "tipo": "sala", "storeroom_id": 1 }
    }
  ]
}
```

### Endpoints donde viene

| Ruta | Uso |
|---|---|
| `POST /api/loans/{id}/approve` | Respuesta (`loan`): el pañolero ve de inmediato qué unidades se asignaron y dónde están. |
| `GET /api/loans/{id}` | Detalle del préstamo aprobado, para preparar el retiro. |
| `GET /api/loans?estado=en_proceso` | Lista de préstamos aprobados por entregar. |
| `POST /api/loans/{id}/deliver` | Respuesta, con las unidades finalmente entregadas. |
| `POST /api/loans/checkout` | Respuesta del préstamo presencial. |
| `GET /api/loans/requests` y `GET /api/students/{id}/loans` | Vienen por consistencia; el profesor no las necesita. |

## Casos borde

- **Hay unidades disponibles, pero en otro pañol**: no cuentan. La solicitud o la aprobación responden `422` por stock insuficiente en el pañol pedido.
- **La unidad no está donde dice el sistema**: el pañolero toma otra unidad disponible del mismo producto **y del mismo pañol** y la informa al entregar (`POST /api/loans/{id}/deliver` con `items[].unit_ids`). La asignada vuelve a `disponible`. La ubicación desactualizada se corrige con `PATCH /api/equipment-units/{id}` (`cajon_id`).
- **Una unidad se movió a otro pañol después de aprobar**: sigue asignada al préstamo; la regla solo se aplica al asignar. Si el pañolero no la tiene, la reemplaza en la entrega por una de su pañol.
- **Préstamo ya procesado**: la ubicación es la última registrada de la unidad, es decir, de dónde salió. Sirve para devolverla al mismo lugar en el `checkin`.

## Frontend (panel del pañol)

- En el préstamo `en_proceso`, por cada ítem con unidades, una fila por unidad: código, número de serie, sala y cajón.
- Ordenar o agrupar las unidades por sala y cajón para que el retiro sea un solo recorrido.
- Mostrar la ubicación también en la respuesta de la aprobación, sin obligar a recargar el detalle.
- Si alguna unidad no se encontró, la pantalla de entrega permite cambiarla por otra del mismo pañol. Los candidatos se listan con `GET /api/products/{id}/units?status=disponible&storeroom_id={loan.storeroom_id}` (filtro existente).
- Insumos: sin cambios, siguen mostrando `items[].location` / `items[].cajon`.

## Backend (cuando se implemente)

- `EquipmentUnit`: scope `inStoreroom($storeroomId)` (unidades de un cajón de alguna sala del pañol). El filtro `?storeroom_id=` de `EquipmentUnitService::applyFilters()` puede reutilizarlo.
- `LoanService`: aplicar el scope en `assertItemsAvailable()` (equipos y maquetas, con el pañol del préstamo), en `assignUnits()` (aprobación y presencial) y en `replaceUnits()` (entrega). Los mensajes de `422` nombran el pañol.
- `LoanItemResource`: agregar `cajon` y `location` a cada unidad de `units`.
- `LoanController` y `StudentController`: donde hoy se precarga `items.units`, precargar `items.units.cajon.location` (sin N+1).
- Pruebas:
  - `LoanUnitsTest`: la aprobación ignora unidades de otro pañol aunque tengan `id` menor, y responde `422` si las del pañol no alcanzan.
  - `LoanUnitsTest`: el presencial y la entrega rechazan `unit_ids` de otro pañol; un presencial con `storeroom_id` de ese otro pañol sí las toma.
  - `LoanRemoteRequestTest`: la solicitud cuenta solo las unidades del pañol pedido.
  - `LoanUnitsTest`: la respuesta de `approve` y `GET /api/loans/{id}` traen `cajon` y `location` correctos en cada unidad.
- Mismo cambio:
  - `ENDPOINTS.md`.
  - `docs/solicitud-prestamo-profesor.md`, sección "Disponibilidad de stock".
  - `TASKS/front-end.md`: contrato nuevo de `items[].units[]` y nuevos `422` por pañol.
  - `TASKS/Prestamos.md`: checkboxes en "Préstamos con unidades".

## Fuera de alcance

- Elegir unidades al aprobar: la aprobación sigue asignándolas solas.
- Escanear el código de barras en la entrega para confirmar la unidad real. Es el siguiente paso natural y la API ya lo permite (`GET /api/equipment-units/by-code/{code}` + `deliver` con `unit_ids`).
- Mostrar en la cola `pendiente` cuántas unidades disponibles hay por ubicación.
- Un estado intermedio "reservado" o asignar las unidades recién en la entrega (hoy quedan `prestado` desde la aprobación).
