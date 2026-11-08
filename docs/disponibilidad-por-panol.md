# Disponibilidad de productos por pañol

**Estado: planificado, sin implementar.** Este documento es el plan y el contrato común entre backend y frontend. Seguimiento en `TASKS/Prestamos.md`.

## Problema

El panel de préstamos del profesor muestra un stock que puede no estar disponible en el pañol elegido:

| Dato | Qué cuenta | Dónde |
|---|---|---|
| Lo que muestra el panel | `quantity`: unidades `disponible` del producto en **todos** los pañoles | `GET /api/products` |
| Lo que valida la solicitud | Unidades `disponible` guardadas en el **pañol elegido** | `LoanService::assertItemsAvailable` |

Caso real: el `Switch Cisco Catalyst 2960` muestra 8 existencias, todas en el Pañol de Redes y Telecomunicaciones. Si el profesor solicita desde otro pañol, recibe `422` con "hay 0 disponibles".

La validación es correcta: el pañol elegido es el que prepara y entrega el préstamo. Lo que falta es que el panel reciba la misma cifra que se valida.

## Contrato

### `GET /api/products?storeroom_id={id}`

Roles: los mismos del listado (todos los autenticados).

- `storeroom_id` es opcional y se combina con los filtros actuales.
- No excluye productos: un producto sin disponibilidad en ese pañol sigue en la lista con `available_in_storeroom: 0`, para que el panel pueda mostrarlo deshabilitado.
- Con `storeroom_id`, cada producto suma el campo `available_in_storeroom` (entero).
- Sin `storeroom_id`, la respuesta queda exactamente como hoy, sin esa clave.
- `quantity` no cambia en ningún caso: sigue siendo el total del producto.

```json
{
  "data": [
    {
      "id": 5,
      "name": "Switch Cisco Catalyst 2960",
      "type": "equipo",
      "quantity": 8,
      "available_in_storeroom": 0
    }
  ]
}
```

| Tipo de producto | `available_in_storeroom` |
|---|---|
| `equipo`, `maqueta` | Unidades en estado `disponible` cuyo cajón está en una ubicación de ese pañol. |
| `insumo` | `quantity`, porque los insumos no se controlan por pañol y la solicitud los valida contra el total. |

### Errores

| Caso | Respuesta |
|---|---|
| `storeroom_id` no es un entero o no existe | `422` con `errors.storeroom_id`, en español |
| Sin token | `401`, como el resto del listado |

### Límites

- El dato es informativo y no reserva nada. Otra operación puede tomar la unidad antes de enviar o aprobar la solicitud, así que `POST /api/loans/requests` y `approve` siguen validando y pueden responder `422`.
- Tampoco considera otras solicitudes pendientes ni el rango horario, igual que la validación actual.
- `GET /api/products/{id}` no cambia: el panel trabaja con el listado.

## Plan de implementación

### Fase 1: backend

1. **Regla única:** sacar a un solo lugar el criterio "unidad disponible en un pañol" y usarlo tanto en `assertItemsAvailable` como en el listado, para que las dos cifras no puedan diverger.
2. **Validación:** crear un FormRequest para `ProductController::index` que valide `storeroom_id` (`nullable`, `integer`, `exists:storerooms,id`).
3. **Consulta:** cuando venga `storeroom_id`, agregar un conteo con `withCount` en la misma consulta del listado, sin una consulta por producto.
4. **Resource:** exponer `available_in_storeroom` en `ProductResource` solo cuando se haya pedido; en insumos devuelve `quantity`.

### Fase 2: tests

En `tests/Feature/ProductStoreroomAvailabilityTest.php`:

- Equipo con unidades en un solo pañol: devuelve la cantidad real en ese pañol y `0` en otro, con `quantity` sin cambios.
- Solo cuentan las unidades `disponible`: quedan fuera `prestado`, `en_reparacion` y `dado_de_baja`.
- Maqueta: mismo comportamiento que un equipo.
- Insumo: `available_in_storeroom` igual a `quantity`.
- Sin `storeroom_id`: la clave no aparece.
- `PRO-01` puede consultarlo (caso feliz); no hay caso `403`, porque el listado es de todos los roles.
- `422` con `storeroom_id` inexistente y con uno no numérico.
- Coherencia: pedir más de `available_in_storeroom` en `POST /api/loans/requests` da `422`, y pedir esa cantidad exacta se acepta.
- Rendimiento: `ListQueryPerformanceTest` también cubre el listado con `storeroom_id`, sin N+1.
- `ResourceContractTest`: las claves de `ProductResource` no cambian sin el parámetro, y se agrega la clave nueva cuando se pide.

### Fase 3: cierre de documentación

- Pasar `GET /api/products?storeroom_id=` en `ENDPOINTS.md` de pendientes a implementado.
- Marcar `[x]` en `TASKS/Prestamos.md`.
- Pasar la sección de `TASKS/front-end.md` a "ya hecho".
- Cambiar el estado de este documento.

### Fase 4: panel web (repositorio `sgia-frontend`)

1. Al elegir el pañol, volver a pedir los productos con `storeroom_id`.
2. Mostrar `available_in_storeroom` como existencias y usarlo como máximo de la cantidad.
3. Deshabilitar el producto si es `0`, con un aviso como "Sin existencias en este pañol".
4. Si cambia el pañol con productos ya agregados, revalidar las cantidades y avisar cuáles dejaron de alcanzar.
5. Mientras no haya pañol elegido, no ofrecer cantidades como disponibles.
6. Mantener el manejo del `422` de la solicitud, porque el dato puede quedar desactualizado.

Si la app móvil llega a mostrar stock al solicitar préstamos, debe seguir la misma regla.

## Criterio de término del backend

`php artisan test` en verde, `vendor/bin/pint --dirty` sin cambios pendientes y `composer analyse` sin errores nuevos.

## Fuera de alcance

- Controlar el stock de insumos por pañol.
- Reservar unidades o considerar horarios.
- Mover unidades entre pañoles o sugerir otro pañol con stock.
