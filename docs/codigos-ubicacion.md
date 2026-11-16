# Códigos de ubicación y devolución con escaneo de cajón

**Estado: plan (sin implementar).** Este documento es el plan y el contrato común entre backend y frontend. Seguimiento en `TASKS/Productos.md` (REQ-05) y `TASKS/Prestamos.md` (devoluciones); cambios de contrato para el frontend en `TASKS/front-end.md`.

## Problema

| Hoy | Problema |
|---|---|
| La sala (`locations`) no tiene código. | No hay forma corta de identificarla ni de imprimirla en una etiqueta. |
| El cajón o estante (`cajones`) tiene un `barcode` aleatorio `EST-97608059`. | No dice dónde está, nadie lo escanea y no tiene etiqueta. |
| La devolución (`POST /api/loans/checkin`) marca la condición por producto y devuelve todas las unidades pendientes. | No se escanea nada: no se verifica qué volvió ni se registra en qué cajón quedó. |

## Decisiones (noviembre 2026)

| # | Decisión |
|---|---|
| 1 | Cada sala tiene un **código corto** (`L206`, `PCENTRAL`), no su nombre completo: cabe en la etiqueta de 50x30 mm y se escanea sin problemas de espacios, tildes ni guiones. |
| 2 | El código del cajón es `{código sala}-{código cajón}` (`L206-RACK-R1`) y **reemplaza** al `EST-…` aleatorio en `cajones.barcode`. |
| 3 | El código de una sala **no se puede editar** una vez creado (las etiquetas impresas de sus cajones dependen de él). |
| 4 | Los cajones tienen **etiqueta imprimible** de 50x30 mm, igual que las unidades. |
| 5 | Al **devolver un préstamo** se escanea el producto (o cada unidad) y el cajón donde se guarda. |
| 6 | **Insumo:** el cajón escaneado debe ser el cajón registrado del producto; si el producto no tenía cajón, queda ese. Para guardarlo en otro lado se usa «Reubicar». |
| 7 | **Equipo o maqueta:** se escanea cada unidad y su cajón; la unidad queda registrada en ese cajón (puede ser otro que el anterior). Solo se devuelven las unidades escaneadas (devolución parcial). |
| 8 | **Dañado** se escanea como cualquier devolución (queda en reparación en ese cajón). **Extraviado** se marca a mano, sin escanear. |

## Contrato

### Código de sala (`locations.code`)

- `string(10)`, único en todo el sistema, solo `[A-Z0-9]`, de 2 a 10 caracteres.
- `POST /api/locations`: `code` opcional. Si viene, se pasa a mayúsculas y se valida formato y unicidad (`422` en `code`). Si no viene, el backend lo sugiere a partir del nombre y garantiza que sea único (sufijo numérico: `PREDES2`).
- `PUT/PATCH /api/locations/{id}`: si llega un `code` distinto al actual → `422` en `code`: «El código de la sala no se puede cambiar.». Igual al actual: se acepta y se ignora.
- `LocationResource` suma `code`.

**Regla de sugerencia** (la misma en backend y en el formulario del frontend, para mostrarla antes de guardar):

1. Quitar tildes y pasar a mayúsculas.
2. Si el nombre tiene un número de sala (1 o 2 letras, guion o espacio opcional y 2 a 4 dígitos: `L-206`, `E 301`, `T102`), el código es letras + dígitos: `L206`.
3. Si no, quitar palabras vacías (de, del, la, el, los, las, y). Con una palabra: sus primeras 8 letras. Con varias: la inicial de la primera y el resto de palabras pegadas, hasta 10 caracteres (`Pañol Central` → `PCENTRAL`).

### Código del cajón (`cajones.barcode`)

- Valor: `{locations.code}-{codigo normalizado}`. Normalización del `codigo`: sin tildes, mayúsculas, todo lo que no sea `[A-Z0-9]` pasa a `-`, sin guiones repetidos ni en los extremos (`Mesa 1 Cajón` → `MESA-1-CAJON`).
- El primer guion siempre separa sala y cajón, porque el código de sala no lleva guiones. Para escanear se busca el código completo; no hace falta separarlo.
- Se calcula al crear el cajón y se recalcula si cambia su `codigo` o su sala.
- El `codigo` normalizado tiene como máximo **12 caracteres** (código completo ≤ 23), para que el Code128 quepa en la etiqueta de 50 mm. `422` en `codigo` si se pasa.
- Dos cajones de la misma sala no pueden quedar con el mismo código normalizado (`Rack R1` y `Rack-R1`): `422` en `codigo`: «Ya existe un cajón con el código {CODIGO} en esta sala.».
- `CajonResource` mantiene la clave `barcode` con el valor nuevo e incluye `location.code` cuando la sala viene cargada.

### Rutas nuevas

| Método | Ruta | Roles | Descripción |
|---|---|---|---|
| `GET` | `/api/cajones/by-code/{code}` | `AD-01`, `DIR-01`, `PAN-01` | Búsqueda exacta, sin distinguir mayúsculas, para el escaneo. `CajonResource` con `location` (`id`, `code`, `nombre`, `storeroom_id`). `404`: «No existe un cajón con el código {code}.» Ruta estática, antes de `/cajones/{cajon}`. |
| `GET` | `/api/cajones/{id}/label` | `AD-01`, `DIR-01`, `PAN-01` | PDF de etiqueta 50x30 mm (código de barras + código), igual que `GET /api/equipment-units/{id}/label`. Archivo `etiqueta-{barcode}.pdf`. |

### Devolución: `POST /api/loans/checkin` (rompe contrato)

Se mantienen `loan_id`, `general_notes`, `items[].item_id` o `product_id`, `condition` (`bueno`, `optimo`, `regular`, `dañado`/`danado`, `extraviado`) y `damaged`. Cambia lo siguiente:

| Ítem | Campo | Regla |
|---|---|---|
| Equipo o maqueta | `units: [{id, cajon_id}]` | **Reemplaza a `unit_ids`**, que se elimina. Obligatorio, con al menos una unidad. Solo se devuelven las enviadas. `cajon_id` obligatorio salvo `extraviado` (`422` en `items.{i}.units.{j}.cajon_id`: «Escanea el cajón donde se guarda la unidad {code}.»). La unidad queda en ese cajón; una extraviada no cambia de cajón. |
| Insumo | `cajon_id` | Obligatorio salvo `extraviado` (`422` en `items.{i}.cajon_id`: «Escanea el cajón donde se guarda {producto}.»). Si el producto tiene cajón y el escaneado es otro: `422`: «{producto} se guarda en el cajón {CODIGO}; escanea ese cajón o reubica el producto.». Si no tenía, queda registrado el escaneado (`products.cajon_id` y `location_id`). |
| Todos | — | El cajón debe pertenecer a una sala del pañol del préstamo (`locations.storeroom_id = loans.storeroom_id`; si el préstamo no tiene pañol, no se aplica). Si no: `422`: «El cajón {CODIGO} no pertenece al pañol del préstamo.» |

- Varias filas pueden apuntar al mismo ítem de equipo con distinta condición (una unidad buena y otra dañada). Una unidad no puede aparecer dos veces.
- Estados de la unidad, sin cambios: `bueno`/`regular` → `disponible`, `dañado` → `en_reparacion`, `extraviado` → `dado_de_baja`.
- La liberación automática de préstamos vencidos (`sgia:release-expired-loans`) no pasa por este request y sigue sin cajón.
- La respuesta mantiene su forma; las unidades devueltas traen su `cajon` actualizado.

```json
{
  "loan_id": 164,
  "items": [
    { "item_id": 501, "condition": "bueno", "units": [{ "id": 31, "cajon_id": 9 }, { "id": 32, "cajon_id": 9 }] },
    { "item_id": 501, "condition": "dañado", "units": [{ "id": 33, "cajon_id": 12 }] },
    { "item_id": 502, "condition": "extraviado", "units": [{ "id": 40 }] },
    { "item_id": 503, "condition": "bueno", "cajon_id": 1 }
  ]
}
```

## Datos existentes (migración)

1. Agregar `locations.code` y rellenarlo. Como el código no se podrá editar, **las salas existentes usan la tabla de abajo, aprobada antes de migrar**; las que no estén en ella usan la regla de sugerencia.
2. Recalcular `cajones.barcode` de todos los cajones con el formato nuevo. Los `EST-…` dejan de existir; no hay etiquetas impresas con ellos.

**Códigos propuestos para las salas de la base de desarrollo (revisar antes de implementar):**

| Sala | Regla | Propuesta |
|---|---|---|
| Pañol Central | `PCENTRAL` | `PCENTRAL` |
| Laboratorio E-301 | `E301` | `E301` |
| Taller T-102 | `T102` | `T102` |
| Pañol de Redes | `PREDES` | `PREDES` |
| Bodega Edificio E | `BEDIFICIOE` | **`BODEGAE`** (la regla da un código poco legible) |
| Laboratorio de Redes L-204 | `L204` | `L204` |
| Laboratorio de Ciberseguridad L-206 | `L206` | `L206` |
| Laboratorio de Programación E-205 | `E205` | `E205` |

Ejemplos de cajones resultantes: `PCENTRAL-CAJON-A1`, `PCENTRAL-GAVETA-01`, `E301-MESA-1-CAJON`, `E301-ARMARIO-LAB`, `PREDES-RACK-R1`, `PREDES-ESTANTE-S1`.

## Frontend

### Ubicaciones (`/ubicaciones`)

- Modal de sala: campo «Código de sala» con la sugerencia ya escrita mientras se escribe el nombre (editable solo al crear; al editar se muestra en solo lectura con la nota «No se puede cambiar»).
- Modal de cajón: vista previa del código final mientras se escribe (`L206-RACK-R1`), con el aviso del límite de 12 caracteres.
- Códigos visibles en la lista de salas y en las tarjetas de cajón, en `font-mono`.
- Botón «Imprimir etiqueta» por cajón (`GET /api/cajones/{id}/label`, con `getBlob` y la misma impresión que la etiqueta de unidades).

### Devolución con escaneo (`/prestamos/mostrador`, pestaña Devolución)

1. El pañolero elige el préstamo (como hoy).
2. Un campo de escaneo con foco permanente recibe códigos de la pistola, en pares:
   - **Producto o unidad**: un código de unidad del préstamo (equipo/maqueta) o el código de barras de un insumo del préstamo. Si no pertenece al préstamo o ya se escaneó: aviso y sonido de error.
   - **Cajón**: el siguiente código escaneado se busca con `GET /api/cajones/by-code/{code}`. Para un insumo se compara con su cajón registrado antes de enviar (el backend vuelve a validarlo).
3. Cada fila muestra el estado: «Esperando cajón» → «Guardado en L206-RACK-R1». Por fila se puede marcar «Dañado» (con detalle) o deshacer el escaneo.
4. Las unidades que no aparecen se pueden marcar «Extraviada» a mano, sin escanear.
5. «Completar devolución» envía solo lo escaneado o marcado. Lo pendiente queda para una devolución posterior (parcial).

## Fases

### Fase 1 — Backend: códigos de sala y cajón
- [ ] Migración `locations.code` con la tabla aprobada y recálculo de `cajones.barcode`.
- [ ] `Location::suggestCode()`, inmutabilidad del código y `code` en `LocationResource`.
- [ ] Código del cajón calculado y recalculado; normalización, límite de 12 caracteres y choque en la misma sala.
- [ ] `GET /api/cajones/by-code/{code}` y `GET /api/cajones/{id}/label`.
- [ ] Tests Feature (generación, unicidad, inmutabilidad, normalización, recálculo, by-code 200/404/403, label 200/403), `ResourceContractTest`, `ENDPOINTS.md`, `TASKS/front-end.md`.

### Fase 2 — Backend: devolución con escaneo
- [ ] `units: [{id, cajon_id}]` en lugar de `unit_ids`; `cajon_id` en insumos; reglas de extraviado, cajón registrado y pañol.
- [ ] La unidad devuelta queda en el cajón escaneado; el insumo sin cajón lo registra.
- [ ] Tests (unidad con y sin cajón, extraviada, parcial, insumo correcto/distinto/sin cajón, cajón de otro pañol) y actualización de `LoanReturnTest` y `LoanUnitsTest`.

### Fase 3 — Frontend: Ubicaciones
- [ ] Tipos y cliente: `code` en sala, `fetchCajonByCode`, descarga de etiqueta.
- [ ] Campo de código con sugerencia, vista previa del código del cajón, códigos visibles e «Imprimir etiqueta».
- [ ] Tests de componentes.

### Fase 4 — Frontend: devolución con escaneo
- [ ] Flujo de escaneo por pares en el mostrador, estados por fila, dañado y extraviado, envío del nuevo `LoanCheckinPayload`.
- [ ] Tests de componentes (escaneo correcto, código ajeno, cajón equivocado de insumo, extraviada, envío parcial).

### Fase 5 — Verificación
- [ ] Recorrido en el navegador contra la API local (alta de sala y cajón, etiqueta, devolución completa y parcial).
- [ ] Documentación final (`ENDPOINTS.md` de ambos repos, `TASKS`, changelog del frontend).

Las fases 2 y 4 cambian el contrato de `checkin` y deben publicarse juntas.

## Fuera de alcance

- Editar el código de una sala (decisión 3).
- Escanear en la entrega (`deliver`, `checkout`): sigue como hoy.
- La app móvil (solo `PRO-01`) no participa en devoluciones.
