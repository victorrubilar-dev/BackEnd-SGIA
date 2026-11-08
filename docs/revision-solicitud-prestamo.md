# Revisión de la solicitud remota y elección de unidades

Especificación del paso de revisión que hace el pañol (`PAN-01`, `AD-01`) antes de aprobar una solicitud remota. Es el contrato común entre backend y frontend. Complementa `docs/ubicacion-unidades-prestamo.md`, que describe la ubicación de las unidades ya asignadas.

## Flujo

| # | Paso | Dónde |
|---|---|---|
| 1 | El profesor envía la solicitud con producto y cantidad. | `POST /api/loans/requests` (ya existía) |
| 2 | La solicitud llega a la cola del pañol elegido. | `GET /api/loans/pending` (ya existía) |
| 3 | El pañol pulsa **Revisar** y se despliegan los detalles. | `GET /api/loans/{id}/review` (nuevo) |
| 4 | El sistema propone unidades disponibles del pañol. | `suggested_units` en la revisión (nuevo) |
| 5 | Si lo desea, el pañol pulsa **Cambiar elementos** en un producto y escanea las unidades que entrega, tantas como la cantidad solicitada. | Pantalla de revisión + `POST /api/loans/{id}/approve` con `items` (nuevo) |
| 6 | Cada unidad muestra su ubicación: sala y cajón. | `location` y `cajon` en cada unidad (nuevo en la revisión) |

La revisión no reserva nada: las unidades pasan a `prestado` recién al aprobar. Por eso otra operación puede tomar una unidad propuesta entre la revisión y la aprobación; en ese caso `approve` responde `422` y el frontend vuelve a pedir la revisión.

## `GET /api/loans/{id}/review`

Roles y autorización: los mismos de `POST /api/loans/{id}/approve` (`LoanPolicy::approve`): `AD-01` y el `PAN-01` del pañol del préstamo; un `PAN-01` de otro pañol recibe `403`. Solo para préstamos en estado `pendiente`; en otro estado responde igual que `approve` ante una transición inválida: `422` con `errors.status`, `current_status`, `attempted_status` y `allowed_transitions`.

```json
{
  "data": {
    "loan_id": 12,
    "code": "PRS-00012",
    "status": "pendiente",
    "storeroom": { "id": 1, "name": "Pañol Temuco" },
    "can_approve": true,
    "items": [
      {
        "item_id": 81,
        "product_id": 45,
        "name": "Router Cisco 1941",
        "quantity": 2,
        "has_units": true,
        "available_quantity": 3,
        "shortage": 0,
        "location": null,
        "cajon": null,
        "suggested_units": [
          {
            "id": 301,
            "code": "EQP-IC-000014",
            "serial_number": "FTX1840ABCD",
            "status": "disponible",
            "cajon": { "id": 7, "codigo": "C-02", "descripcion": "Routers" },
            "location": { "id": 12, "nombre": "Laboratorio de Redes", "tipo": "sala", "storeroom_id": 1 }
          }
        ],
        "available_units": []
      },
      {
        "item_id": 82,
        "product_id": 50,
        "name": "Cable UTP",
        "quantity": 5,
        "has_units": false,
        "available_quantity": 40,
        "shortage": 0,
        "location": { "id": 12, "nombre": "Laboratorio de Redes", "tipo": "sala", "sala": "Laboratorio de Redes", "cajon": "C-02", "descripcion": null },
        "cajon": { "id": 7, "codigo": "C-02", "descripcion": "Routers" },
        "suggested_units": [],
        "available_units": []
      }
    ]
  }
}
```

| Clave | Contenido |
|---|---|
| `can_approve` | `true` si ningún ítem tiene `shortage > 0`. |
| `items[].has_units` | `true` en equipos y maquetas. Los insumos no eligen unidades. |
| `items[].available_quantity` | Equipos y maquetas: unidades `disponible` del pañol del préstamo. Insumos: `quantity` del producto. |
| `items[].shortage` | `max(0, quantity - available_quantity)`. |
| `items[].location`, `items[].cajon` | Ubicación del producto, en la misma forma de `LoanItemResource`. Solo insumos; `null` en productos con unidades, cuya ubicación viene por unidad. Pueden ser `null` si el producto no tiene ubicación. La `location` del insumo trae `id`, `nombre`, `tipo`, `sala`, `cajon` y `descripcion`; la de una unidad trae `id`, `nombre`, `tipo` y `storeroom_id`. |
| `items[].suggested_units` | Las primeras `quantity` unidades disponibles del pañol, por `id`: las mismas que tomaría la asignación automática. |
| `items[].available_units` | **Todas** las unidades disponibles del pañol para ese producto, incluidas las propuestas, ordenadas por sala, cajón y código. El frontend resuelve con esta lista el código que escribe o escanea el pañol. |

El ejemplo deja `available_units` vacío solo por brevedad.

## `POST /api/loans/{id}/approve`

Cuerpo opcional, retrocompatible:

```json
{ "items": [{ "item_id": 81, "unit_ids": [301, 305] }] }
```

- Sin `items`, con `items: []`, o para un ítem no incluido: asignación automática, como hasta ahora.
- Con `items`: se asignan exactamente esas unidades. Las reglas son las mismas de la entrega.

| Caso | Respuesta |
|---|---|
| Ítem ajeno al préstamo, insumo o repetido en `items` | `422` en `items.{i}.item_id` |
| Número de unidades distinto de `quantity` | `422` en `items.{i}.unit_ids` |
| Unidad inexistente, de otro producto, no `disponible` o de otro pañol | `422` en `items.{i}.unit_ids` |
| Unidad repetida, también entre ítems distintos | `422` en `items.{i}.unit_ids.{j}` |
| Stock insuficiente en la asignación automática | `422` en `items`, como hasta ahora |

Todo ocurre en una transacción: ante un `422` el préstamo sigue `pendiente` y ninguna unidad cambia de estado. Las unidades elegidas se reservan antes de la asignación automática de los demás ítems. La respuesta conserva su forma: `{ message, allowed_transitions, loan }`.

La entrega (`POST /api/loans/{id}/deliver` con `items[].unit_ids`) no cambia y sigue permitiendo reemplazar una unidad que no se encontró.

## Frontend

- La tarjeta de la cola cambia **Aprobar / Preparar** por **Revisar**. Aprobar solo es posible desde la revisión. **Rechazar** sigue disponible.
- Revisar despliega los datos de la solicitud y, por ítem, una fila por unidad con código, número de serie, sala y cajón. Cada ítem con unidades tiene el botón **Cambiar elementos**. Es opcional: si no se usa, se aprueba la propuesta del sistema.
- **Cambiar elementos** abre un campo de escaneo con el avance (`1 de 2`). Sirve para pistola lectora y teclado: Enter confirma cada código, el campo se limpia y conserva el foco. El código se busca en `available_units`, sin distinguir mayúsculas y sin espacios en los extremos.
- Se escanea **exactamente** `quantity`: los elementos se reemplazan al completar la cantidad. Para mantener una unidad propuesta, se escanea también. **Cancelar** conserva la selección anterior, y **Aprobar** queda deshabilitado mientras el escaneo está abierto. Sin otras unidades disponibles (`available_quantity <= quantity`) el botón queda deshabilitado.
- Código que no está en `available_units`, ya escaneado o elegido en otro ítem: error en línea, por ejemplo "El código X no está disponible en este pañol para este producto". No cuenta para el avance.
- Al completar el escaneo, las filas muestran de inmediato la ubicación de las unidades nuevas.
- Las unidades cambiadas se marcan, y se puede restablecer la propuesta del sistema.
- Filas ordenadas por sala y cajón, para que el retiro sea un solo recorrido.
- Los insumos muestran cantidad y ubicación del producto, sin **Cambiar elementos**.
- Con `can_approve: false`: se indica qué falta (`shortage`), **Aprobar** queda deshabilitado y se puede rechazar.
- Al aprobar se envía `items` con **todas** las unidades visibles de los ítems `has_units`, cambiadas o no, para asignar justo lo que el pañol vio.
- Ante un `422` en la aprobación: se muestra el mensaje, se vuelve a pedir la revisión y se avisa que la propuesta pudo cambiar.

## Fuera de alcance

- Reservar unidades durante la revisión o un estado intermedio "reservado".
- Cambios en la app móvil, que no tiene pantallas de pañol.
- Elegir lote o ubicación de los insumos: se gestionan por cantidad.
