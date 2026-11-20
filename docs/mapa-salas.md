# Mapa de salas: zonas y estantes (REQ-22)

**Estado: implementado (20 nov 2026), fases 1 a 6.** El frontend detalla sus diferencias con el plan en `FrondEnd-SGIA/docs/mapa-salas.md` §11 (la ruta web es `/mapa-salas/:id`). Rama `mapa-salas` en ambos repositorios. Este documento es el plan y el contrato común entre backend y frontend. La pantalla (SVG, arrastre, pop-up) está en `FrondEnd-SGIA/docs/mapa-salas.md` y el requisito en `FrondEnd-SGIA/TASKS/REQ-22-MAPA-SALAS.md`. Seguimiento en `TASKS/Productos.md` (REQ-22); cambios de contrato para el frontend en `TASKS/front-end.md`.

## Problema

| Hoy | Problema |
|---|---|
| La ubicación física es **Sala (`locations`) → Cajón (`cajones`)**. | No existe el mueble (estante) ni el área de la sala (zona): no hay qué dibujar. |
| `locations` no tiene medidas y `cajones` no tiene posición. | La web no puede dibujar el plano de una sala. |
| Para saber qué guarda un mueble hay que pedir `GET /api/products?cajon_id=` y `GET /api/equipment-units?cajon_id=` por cada cajón. | El pop-up del estante necesitaría N llamadas. |
| Hasta octubre existía el alias `/api/estantes/*` para los cajones; se eliminó (`TASKS/front-end.md`, B3b). | `/api/estantes` queda libre, pero ahora es **otra entidad**: un cliente antiguo que lo usara para cajones recibirá otra cosa. |

## Decisiones (noviembre 2026)

| # | Decisión |
|---|---|
| 1 | **Nueva entidad `Estante`**: Sala → Estante → Cajón. El estante tiene posición y tamaño en el plano; el cajón se asigna a un estante con `cajones.estante_id` (opcional). Las celdas del dibujo son los cajones reales del estante. |
| 2 | **Nueva entidad `Zona`**: rectángulo con nombre dentro de la sala. Un estante pertenece a lo sumo a una zona. Las zonas pueden superponerse y contener estantes. |
| 3 | **Roles:** escriben `DIR-01` y `PAN-01` (los mismos que escriben salas y cajones); leen todos los autenticados. La web muestra el mapa a `PAN-01`, `DIR-01` y `AD-01` (solo lectura). |
| 4 | **Sin restricción por pañol**, igual que salas y cajones hoy: `PAN-01` puede editar el mapa de cualquier sala. Limitarlo a su pañol queda fuera de alcance (ver Riesgos). |
| 5 | **Centímetros enteros.** Origen (0,0) en la esquina superior izquierda de la sala; `x` crece a la derecha e `y` hacia abajo. `width` es el largo del mueble y `depth` su fondo, sin rotar. |
| 6 | **Rotación 0° o 90°.** Con `rotation = 90` el rectángulo ocupado es `(x, y, depth, width)`; `(x, y)` sigue siendo la esquina superior izquierda de lo ocupado. Todo se calcula con rectángulos alineados a los ejes. |
| 7 | **Superposición entre estantes prohibida** y fuera de los muros también (`422`). Bordes que se tocan no cuentan. |
| 8 | **El `barcode` del cajón no cambia** (`{sala}-{cajón}`). El estante tiene `codigo` propio (`EST-A1`, único por sala) solo para mostrar; no tiene etiqueta. |
| 9 | **Un pedido por pantalla:** `GET /api/locations/{id}/map` entrega todo el plano y `GET /api/estantes/{id}/contents` todo el pop-up. No es un dashboard: va por REST, no por GraphQL. |
| 10 | **Contenido = insumos + unidades.** Los insumos se cuentan por su `cajon_id`; los equipos y maquetas por sus **unidades** (`equipment_units.cajon_id`), no por el `cajon_id` del producto, para no contar dos veces. Se excluyen unidades `dado_de_baja` y productos inactivos. |

## Contrato

### Migraciones

```
locations  + map_width_cm  unsignedInteger null
           + map_height_cm unsignedInteger null
zonas      id, location_id (FK locations, cascadeOnDelete), nombre string(60),
           x, y, width, depth unsignedInteger, created_by, updated_by (FK users null, nullOnDelete),
           timestamps · unique(location_id, nombre)
estantes   id, location_id (FK locations, cascadeOnDelete), zona_id (FK zonas null, nullOnDelete),
           codigo string(12), nombre string(80) null, descripcion text null,
           x, y, width, depth unsignedInteger, rotation unsignedSmallInteger default 0,
           created_by, updated_by, timestamps · unique(location_id, codigo)
cajones    + estante_id (FK estantes null, nullOnDelete)
```

`DELETE /api/locations/{id}` ya responde `422` si la sala tiene cajones, así que el `cascadeOnDelete` solo borra zonas y estantes vacíos.

### Reglas de dominio

- **Medidas de sala:** 100 a 10 000 cm, siempre las dos juntas. Si son `null`, el mapa usa 1000 × 600 y responde `has_dimensions: false`. Achicar una sala que deja afuera un estante o una zona: `422` en `map_width_cm`: «El estante {CODIGO} quedaría fuera de la sala.» (o «La zona {NOMBRE} …»).
- **Código del estante:** se normaliza con `Cajon::normalizeCode()` (sin tildes, mayúsculas, lo que no sea `[A-Z0-9]` pasa a `-`); 1 a 12 caracteres; único por sala: «Ya existe un estante con el código {CODIGO} en esta sala.»
- **Medidas:** estante `width`/`depth` 20 a 2000; zona `width`/`depth` 50 a 10 000; `rotation` 0 o 90; `x`/`y` ≥ 0. Nombre de zona único por sala sin distinguir mayúsculas.
- **Límites:** el rectángulo ocupado debe quedar dentro de la sala (`x + ancho ≤ map_width_cm`, `y + alto ≤ map_height_cm`, con las medidas por defecto si son `null`). `422` en `x`: «El estante queda fuera de la sala.» / «La zona queda fuera de la sala.»
- **Superposición:** un estante no puede intersectar a otro de la misma sala. `422` en `x`: «Se superpone con el estante {CODIGO}.»
- **Zona del estante:** `zona_id` debe ser de la misma sala (`422` en `zona_id`: «La zona no pertenece a la sala.»). El backend no la recalcula: la web envía la zona que contiene el centro del estante al soltarlo.
- **Cajón → estante:** `estante_id` debe ser de la misma sala que el cajón (`422` en `estante_id`: «El estante no pertenece a la sala del cajón.»). Si el cajón cambia de `location_id` sin enviar `estante_id`, queda en `null`.
- **`location_id` inmutable** en estantes y zonas: `422` en `location_id`: «Un estante no se puede mover a otra sala.»
- La lógica de geometría vive en `App\Services\RoomMapService` (límites, superposición, primer hueco libre y validación al achicar la sala). Los Form Requests la usan en `after()` a través de `EstanteRules` y `ZonaRules`. El primer hueco solo prueba el borde del área y los bordes de los obstáculos llevados a la rejilla: mismo resultado que recorrer cada punto (`RoomMapServiceTest`).

### Endpoints

Todos bajo `auth:sanctum`; escritura con `role:DIR-01,PAN-01`, en el bloque REQ-05 de `routes/api.php`.

| Método | Endpoint | Roles | Contrato |
|---|---|---|---|
| `GET` | `/api/locations/{id}/map` | Todos | Plano completo (forma abajo). Sin N+1: una consulta por colección. |
| `PATCH` | `/api/locations/{id}` | `DIR-01`, `PAN-01` | Acepta además `map_width_cm` y `map_height_cm`. `LocationResource` suma ambos campos. |
| `GET` | `/api/estantes?location_id=&search=` | Todos | Paginado, `per_page` hasta 200; con cajones resumidos. |
| `GET` | `/api/estantes/{id}` | Todos | Como en `/map`. |
| `POST` | `/api/estantes` | `DIR-01`, `PAN-01` | `location_id`, `codigo`, `nombre?`, `descripcion?`, `zona_id?`, `width`, `depth`, `rotation?`, `x?`, `y?`. Sin `x`/`y`, primer hueco libre recorriendo una rejilla de 10 cm de arriba abajo y de izquierda a derecha (dentro de la zona si viene `zona_id`; si no cabe ahí, en la sala). Sin hueco: `422` en `x` «No hay espacio libre en la sala para un estante de esas medidas.» `201`. |
| `PATCH` | `/api/estantes/{id}` | `DIR-01`, `PAN-01` | Parcial: `codigo`, `nombre`, `descripcion`, `zona_id`, `width`, `depth`, `rotation`, `x`, `y`. Valida límites y superposición con los valores resultantes. Responde el estante como en `/map`. |
| `DELETE` | `/api/estantes/{id}` | `DIR-01`, `PAN-01` | `204`. `422` con `cajones_count` si tiene cajones: «El estante tiene {n} cajones; muévelos antes de eliminarlo.» |
| `GET` | `/api/estantes/{id}/contents` | Todos | Pop-up (forma abajo). |
| `GET` | `/api/zonas?location_id=` | Todos | Sin paginar (pocas por sala). |
| `POST` | `/api/zonas` | `DIR-01`, `PAN-01` | `location_id`, `nombre`, `x`, `y`, `width`, `depth`. `201`. |
| `PATCH` | `/api/zonas/{id}` | `DIR-01`, `PAN-01` | Parcial; mover y redimensionar. |
| `DELETE` | `/api/zonas/{id}` | `DIR-01`, `PAN-01` | `204`; sus estantes quedan con `zona_id: null`. |
| `POST`/`PATCH` | `/api/cajones` | `DIR-01`, `PAN-01` | Aceptan `estante_id` (nullable). `CajonResource` suma `estante_id` y `estante: {id, codigo}` si viene cargado. |
| `GET` | `/api/cajones?estante_id=` / `?sin_estante=1` | Todos | Filtros nuevos del listado (para «Asignar cajones» en la web). |

`GET /api/locations/{id}/map`:

```json
{
  "data": {
    "location": { "id": 1, "code": "L206", "nombre": "Laboratorio 206", "tipo": "sala",
                  "map_width_cm": 1000, "map_height_cm": 600, "has_dimensions": false },
    "zonas": [ { "id": 1, "location_id": 1, "nombre": "Zona A", "x": 50, "y": 50, "width": 450, "depth": 450 } ],
    "estantes": [ {
      "id": 1, "location_id": 1, "zona_id": 1, "codigo": "EST-A1", "nombre": null, "descripcion": null,
      "x": 70, "y": 120, "width": 300, "depth": 60, "rotation": 90,
      "cajones": [ { "id": 3, "codigo": "R1", "barcode": "L206-R1", "products_count": 4, "units_count": 2 } ],
      "items_count": 6, "has_critical_stock": false
    } ],
    "cajones_sin_estante_count": 0
  }
}
```

- `products_count`: insumos activos con ese `cajon_id`. `units_count`: unidades del cajón que no están `dado_de_baja`. `items_count`: la suma en todos los cajones del estante.
- `has_critical_stock`: algún insumo activo del estante con `quantity <= stock_minimo` (`Product::isCriticalStock()`).
- Cajones ordenados por `codigo`; estantes por `codigo`; zonas por `nombre`.

`GET /api/estantes/{id}/contents`:

```json
{
  "data": {
    "estante": { "...": "como en /map" },
    "zona": { "id": 1, "nombre": "Zona A" },
    "location": { "id": 1, "code": "L206", "nombre": "Laboratorio 206" },
    "cajones": [ {
      "id": 3, "codigo": "R1", "barcode": "L206-R1", "descripcion": null,
      "productos": [ { "id": 10, "name": "Guantes de nitrilo", "type": "insumo", "barcode": "…",
                       "quantity": 12, "stock_minimo": 5, "is_critical": false } ],
      "unidades": [ { "product_id": 22, "product_name": "Multímetro", "type": "equipo", "total": 3,
                      "by_status": { "disponible": 2, "prestado": 1, "en_reparacion": 0 } } ]
    } ]
  }
}
```

- `productos`: insumos activos del cajón, por `name`.
- `unidades`: unidades de equipo o maqueta del cajón agrupadas por producto, por `product_name`; `by_status` con los estados reales de `EquipmentUnit` salvo `dado_de_baja` (siempre las tres claves).

## Tests (Feature)

- `RoomMapTest`: `/map` con `has_dimensions: false` por defecto; conteos (`products_count`, `units_count`, `items_count`) sin contar dos veces equipos ni unidades dadas de baja; `has_critical_stock`; cantidad de consultas acotada (sin N+1).
- `EstanteCrudTest`: CRUD por rol (`AD-01` y `PRO-01` leen, `403` al escribir); límites; superposición con `rotation` 90 y bordes que se tocan; zona de otra sala; `location_id` inmutable; primer hueco libre y `422` sin espacio; borrar con cajones; `/contents` agrupa unidades por producto.
- `ZonaCrudTest`: CRUD, límites, borrar deja estantes sin zona.
- `RoomMapServiceTest` (unitario): rotación, bordes que se tocan, límites y primer hueco comparado con un recorrido completo de la rejilla.
- `LocationAndCajonCrudTest` (ampliado): medidas de sala y `422` al achicar; `estante_id` de otra sala `422`; cambio de sala limpia `estante_id`; el `barcode` no cambia al asignar estante; filtros `estante_id` y `sin_estante`.

## Fases

| Fase | Alcance | Repositorio |
|---|---|---|
| 0 | Este plan y documentos de ambos repos. | Ambos |
| 1 | Migraciones, modelos, `RoomMapService`, Form Requests, controladores, resources, rutas y tests de este documento. | Backend |
| 2 | Tipos, servicios y hooks (`packages/`). | Frontend |
| 3 | Mapa en modo vista y pop-up. | Frontend |
| 4 | Edición: crear estantes y zonas, arrastrar, teclado, medidas de sala, asignar cajones. | Frontend |
| 5 | Zoom, marcadores, revisión responsiva, prueba con 200 estantes y cierre. | Frontend |

Casillas de la fase 1 en `TASKS/Productos.md` (REQ-22), todas hechas. Endpoints en la sección 2.2 de `ENDPOINTS.md` (ambos repos). Las lecturas están en la lista de rutas compartidas de `RouteProtectionTest`.

## Riesgos

| # | Riesgo | Mitigación |
|---|---|---|
| R1 | Los cajones existentes quedan sin estante. | `cajones_sin_estante_count` en `/map` y filtro `sin_estante=1` para asignarlos desde la web. |
| R2 | Dos personas mueven el mismo estante a la vez. | Gana el último `PATCH`; la web refresca el mapa después de cada guardado. Sin bloqueo. |
| R3 | `PAN-01` de un pañol puede reordenar salas de otro. | Igual que salas y cajones hoy. Si se limita, se hace para las tres entidades a la vez, como en `TASKS/front-end.md` «Préstamos limitados al pañol de `PAN-01`». |
| R4 | Un cliente antiguo que todavía llame a `/api/estantes` pensando en cajones. | El alias se retiró antes de este plan y ningún cliente actual lo usa (verificado en `FrondEnd-SGIA`); se avisa en `TASKS/front-end.md`. |
| R5 | `MODELS.MD` llama `estantes` a lo que hoy es `cajones`. | `CLAUDE.md` lo aclara junto a la tabla de correspondencias: la tabla `estantes` nueva es el mueble, no el cajón. |

## Fuera de alcance

Restricción por pañol, salas no rectangulares, puertas y ventanas, rotación libre, varios pisos en un plano, etiqueta del estante, edición en tiempo real y el mapa en la app móvil.

## Datos de prueba (fase 6, implementado, 20 nov 2026)

`Database\Seeders\MapaSalasDemoSeeder`, llamado desde `DatabaseSeeder` fuera de producción y ejecutable solo con `php artisan db:seed --class=MapaSalasDemoSeeder`. Repetible: correrlo dos veces no crea nada nuevo.

Por cada ubicación (`seedLocation(Location)`):
1. **Medidas** si no tiene: sala 1000 × 600, pañol 1200 × 800, taller 1400 × 900, bodega 1600 × 1000 cm. Si ya tiene, no se tocan.
2. **Zonas** «Zona A» (mitad izquierda), «Zona B» (arriba a la derecha) y «Zona C» (abajo a la derecha), proporcionales a la sala, como la imagen de referencia. Si existe una con ese nombre, se deja.
3. **Estantes** `EST-A1`, `EST-A2`, `EST-A3` verticales en la zona A (largo: 60 % del alto de la zona) y `EST-B1`, `EST-B2`, `EST-C1`, `EST-C2` horizontales en B y C (cada uno 38 % del ancho de la zona, al 8 % y al 54 %). Si el lugar previsto choca con un estante existente (p. ej. `EST-1` de `PCENTRAL`) o se sale, se usa `RoomMapService::placeEstante` en la zona. Si el código ya existe en la sala, se deja.
4. **Cajones**: si la ubicación no tiene, crea dos por estante (`A1-1`, `A1-2`…; código completo `{sala}-A1-1`). Los cajones sin estante se reparten en orden entre los estantes de la sala. Un cajón que ya tiene estante no se mueve.

Test `MapaSalasDemoSeederTest`: ubicación vacía → 3 zonas, 7 estantes, 14 cajones asignados y ningún estante superpuesto ni fuera de la sala; ubicación con cajones y un estante propio → no lo pisa, asigna todos los cajones sueltos y no crea cajones nuevos; repetir no crea filas; medidas existentes no cambian.

