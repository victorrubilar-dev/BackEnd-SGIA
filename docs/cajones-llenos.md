# Cajones llenos

**Estado: implementado (noviembre 2026) en backend y frontend, rama `feat/cajones-llenos`.** Plan y contrato común entre backend y frontend. Seguimiento en `TASKS/Productos.md` (REQ-05/REQ-22) y cambios de contrato en `TASKS/front-end.md`. Relacionado: `docs/mapa-salas.md` (estantes y celdas de cajón), `docs/codigos-ubicacion.md` (códigos de cajón).

## Problema

| Hoy | Problema |
|---|---|
| Un cajón (`cajones`) solo tiene código, descripción, sala y estante. | El pañolero no puede dejar registrado que un cajón ya no admite más cosas. |
| En el mapa las celdas de un estante son líneas sin estado. | Al buscar dónde guardar algo hay que abrir cada estante para ver qué tan ocupado está. |

## Decisiones (noviembre 2026)

| # | Decisión |
|---|---|
| 1 | Nuevo estado **`lleno`** (booleano, `false` por defecto) en `cajones`. Lo marca una persona; no se calcula a partir del contenido (no hay capacidad por cajón). |
| 2 | Se marca y desmarca con el `PATCH /api/cajones/{id}` existente (`{"lleno": true}`); mismos roles que editar un cajón: `DIR-01` y `PAN-01`. `AD-01` y `PRO-01` solo lo ven. |
| 3 | Es **informativo**: no bloquea asignar productos ni unidades al cajón, ni la devolución con escaneo. |
| 4 | El mapa pinta la celda del cajón lleno en **azul suave** (token `info`: `--info-bg` de fondo y `--info` en el borde), el mismo azul que «prestado» en los badges. El burdeo queda para la búsqueda y el rojo para el stock crítico. |
| 5 | Se marca **desde la lista de cajones** de Ubicaciones (botón por cajón) y **desde el mapa** (pop-up del estante, botón por cajón). |
| 6 | Quién y cuándo: `updated_by`/`updated_at` del cajón (no se agrega historial propio). |

## Contrato

### Migración

```
cajones + lleno (boolean, default false, not null)
```

### Endpoints (todo aditivo, no rompe contrato)

| Método | Ruta | Roles | Cambio |
|---|---|---|---|
| `PATCH`/`PUT` | `/api/cajones/{id}` | `DIR-01`, `PAN-01` | Acepta `lleno` (`boolean`, opcional). Otro tipo → `422` en `lleno`. |
| `POST` | `/api/cajones` | `DIR-01`, `PAN-01` | Acepta `lleno` opcional (por defecto `false`). |
| `GET` | `/api/cajones`, `/api/cajones/{id}`, `/api/cajones/by-code/{code}` | Todos | `CajonResource` suma `lleno: boolean`. |
| `GET` | `/api/locations/{id}/map`, `/api/estantes`, `/api/estantes/{id}` | Todos | Cada `estante.cajones[]` suma `lleno: boolean`. |
| `GET` | `/api/estantes/{id}/contents` | Todos | Cada `cajones[]` suma `lleno: boolean`. |

Ejemplo de `estante.cajones[]`:

```json
{ "id": 3, "codigo": "R1", "barcode": "L206-R1", "products_count": 4, "units_count": 2, "lleno": true }
```

### Tests (backend)

- `LocationAndCajonCrudTest`: `PATCH lleno` por `PAN-01` y `DIR-01` (200, persiste, `updated_by`), sin tocar código, `barcode` ni estante; `AD-01`/`PRO-01` → `403`, `lleno: "quizas"` → `422`, `POST` sin `lleno` → `false`.
- `RoomMapTest`: `lleno` en `/map` y en `/contents`.
- `ResourceContractTest`: `lleno` en `CajonResource`.

## Frontend (`FrondEnd-SGIA`)

- `packages/types`: `lleno` en `CajonEntity`, `EstanteCajonResumen`, `EstanteContenidoCajon` y en `UpdateCajonPayload`/`CreateCajonPayload`.
- `packages/api-client`: `useUpdateCajon` invalida también el contenido de estantes (`['estantes']`) para que el pop-up se refresque; el mapa ya cuelga de `['locations']`.
- **Mapa** (`estante-shape.tsx`): celda del cajón lleno con `fill-info-bg` y borde `stroke-info`; el `aria-label` del estante suma «N cajones llenos». Leyenda corta bajo el mapa si hay alguno.
- **Pop-up del estante** (`estante-contenido-dialog.tsx`): badge «Lleno» (tono `loaned`, azul) por cajón y, para editores, botón «Marcar lleno» / «Quitar lleno».
- **Ubicaciones** (`ubicaciones-page.tsx`): badge «Lleno» y botón de ícono (junto a imprimir y eliminar) para alternar en cada cajón de la lista.
- El botón es `CajonLlenoButton` (`features/mapa-salas/components/`), con permiso `markCajonFull` (`DIR-01`, `PAN-01`) en `features/auth/types/roles.ts`.
- Errores con `apiErrorToMessage`; aviso con el toast existente.

## Fases

1. [x] Plan y documentos (este archivo, `CLAUDE.md`, `TASKS/*`, `ENDPOINTS.md` en ambos repos).
2. [x] Backend: migración, modelo, requests, `CajonResource`, `EstanteResource`, `/contents` y tests.
3. [x] Frontend: tipos, hook, mapa, pop-up y lista de Ubicaciones con tests.
4. [x] Prueba contra la API real (Chromium por CDP): marcar desde la lista y desde el mapa, celda azul, desmarcar.

## Fuera de alcance

- Capacidad por cajón o «lleno» automático según el contenido.
- Bloquear o advertir al reubicar productos en un cajón lleno.
- Filtro `?lleno=` en `GET /api/cajones` y estado en la app móvil (solo `PRO-01`).
