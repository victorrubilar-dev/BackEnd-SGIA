# Mantenciones: carreras de un vistazo y bandeja filtrable por carrera

**Estado: implementado (noviembre 2026).** Rama `feat/mantenciones-por-carrera` en ambos repos. Pantalla `/mantenciones` (`PAN-01`, `DIR-01`). REQ-18.

## Problema

| Hoy | Problema |
|---|---|
| «Mantenciones por carrera» es una tabla con columnas por estado, «Unidades» y una barra gris del total. | La barra no dice cuánto está en curso y cuánto terminó; «Unidades» y «Total» casi repiten el mismo número. En el teléfono la tabla se corta con desplazamiento lateral y los nombres se truncan. |
| Las carreras y la bandeja de abajo no se conectan. | Para ver las mantenciones de una carrera hay que buscarlas a ojo: `GET /api/maintenance-records` no filtra por carrera. |
| Cuatro tarjetas de cifras propias, aviso de vencidas en ámbar escrito a mano, tablas en 12 px y pesos 600. | Fuera del sistema «Trazo» (`Metric`, `CellGrid`, tokens `warn`/`ok`, `TableFrame`, 13–14 px, pesos 400/500). La bandeja también se corta en el teléfono. |

## Decisiones

| # | Decisión |
|---|---|
| 1 | **Backend:** `GET /api/maintenance-records` suma `career_id` (carrera del producto de la unidad) y `sin_carrera=1` (productos sin carrera). `career_id` no numérico → `422`. Aditivo: sin el parámetro la respuesta no cambia. |
| 2 | **Cifras** en un `CellGrid` con `Metric`: «En curso» (`warn`), «Vencidas sin enviar» (unidades con mantención vencida, `warn` si hay), «Finalizadas» (`ok`) y «Unidades atendidas». |
| 3 | **Una celda por carrera** (`CellGrid`, 1/2/4 columnas): código y nombre completo (sin truncar), total grande, **barra apilada por estado** (en curso `warn`, finalizadas `ok`, anuladas neutro) cuyo largo es proporcional a la carrera con más mantenciones, y la línea «N en curso · N finalizadas · N unidades». Leyenda única arriba. Las carreras sin registros van en una línea al pie. |
| 4 | **Tocar una carrera filtra la bandeja** (botón con `aria-pressed`, borde y fondo burdeo suave al estar elegida); volver a tocarla o «Quitar» en la bandeja lo quita. La bandeja también tiene un selector «Carrera» (teclado y teléfono). |
| 5 | **Vencidas:** aviso con los tokens `warn` (sin `amber-*`), chips de código y el texto de cómo enviarlas desde Inventario. |
| 6 | **Bandeja** en `TableFrame`: barra con estado (botones), tipo y carrera; tabla en 13–14 px (`TH_CLASS`, `TD_CLASS`, `ROW_CLASS`); en teléfono, filas `MobileRow` (unidad, producto, tipo, inicio y estado) sin desplazamiento lateral. Paginación al pie. |
| 7 | Sin cambios en el checklist, en los permisos ni en `GET /api/maintenance-records/summary`. |

## Contrato

`GET /api/maintenance-records?career_id={id}` y `GET /api/maintenance-records?sin_carrera=1` (`DIR-01`, `PAN-01`). Combinables con los filtros actuales. `422` (`errors.career_id`) si `career_id` no es un entero.

## Fases

1. [x] Plan y documentos (este archivo, `CLAUDE.md`, `TASKS/Fichas tecnicas.md`, `TASKS/front-end.md`, `ENDPOINTS.md` en ambos repos, `TASKS/REQ-12` del frontend).
2. [x] Backend: filtro `career_id` / `sin_carrera` con test en `MaintenanceRecordTest`.
3. [x] Frontend: tipos y cliente (`career_id`, `sin_carrera`), página rediseñada, tests.
4. [x] Prueba en Chromium contra la API de desarrollo como `PAN-01` y `DIR-01` (1440 y 390 px, claro y oscuro): cifras contra la base, filtro por carrera, sin desplazamiento lateral.

## Notas de implementación

- Backend: `MaintenanceRecordService::listQuery` filtra por `products.career_id` (ya estaba unido) y `sin_carrera` (`whereNull`); validación en el controlador (`career_id` entero, `sin_carrera` booleano). Test `test_listing_filters_by_career_of_the_product`.
- Frontend: `features/mantenciones/components/maintenance-metrics.tsx`, `maintenance-due-notice.tsx`, `maintenance-career-grid.tsx`, `maintenance-inbox.tsx` y `lib/career-filter.ts`; se quitó `maintenance-career-summary.tsx`. En teléfonos las cifras van en 2 × 2, la bandeja usa `MobileRow` y tocar una carrera lleva la bandeja a la vista.
- Prueba real (28/28, PAN-01 y DIR-01): las cuatro celdas coinciden con Postgres (AP 4 = 1 en curso + 3 finalizadas, IC 3, II 2, ISD 5 = 1 + 4; unidades iguales); ISD en curso → solo `EQP-ISD-000004`, ISD «Todas» → sus 5, IC → sus 3, volver a tocar quita el filtro (14); 1440 claro y oscuro, 390 px sin desplazamiento lateral; teclado: Enter en una carrera filtra y en una fila abre el checklist. Sin errores en la consola.

## Revisión compacta (2026-11-24)

**Estado: implementado.** Rama `feat/mantenciones-compacto` (frontend). El usuario no quedó conforme con las tarjetas por carrera: pidió «algo más compacto y que siga la paleta de colores actual», sin el verde y el amarillo de las barras y cifras.

| # | Decisión (reemplaza la 2, la 3 y la 5; el contrato no cambia) |
|---|---|
| 8 | **Paleta:** solo neutros y burdeo. Barras: en curso en burdeo (`bg-accent`), finalizadas en gris (`zinc-400`), anuladas en gris claro (`zinc-300`). Cifras sin color de estado. Los `Badge` de estado de la bandeja quedan como en el resto de la app. |
| 9 | **Maestro-detalle:** desde 1024 px, columna «Por carrera» (≈ 320 px, fija al desplazar) a la izquierda y la bandeja a la derecha; bajo 1024 px, la lista arriba de la bandeja. |
| 10 | **Una fila por carrera** (dos líneas, ~52 px): código, nombre y total; debajo, la barra delgada y «N en curso» en burdeo. Primera fila «Todas las carreras». Las carreras sin mantenciones también figuran, atenuadas. La fila elegida lleva fondo burdeo suave y barra lateral, como el menú. La lista reemplaza al selector de carrera de la bandeja. |
| 11 | **Cifras** en una franja de 4 `Metric` sin pistas ni color (2 × 2 en el teléfono). **Vencidas** en una franja neutra de una línea (ícono, texto y chips), sin fondo amarillo. |

Implementación de la revisión: `maintenance-career-list.tsx` reemplaza a `maintenance-career-grid.tsx`; la bandeja pierde el selector de carrera y junta «Tipo y ejecución» en una columna (tipo arriba; modalidad y responsable o proveedor debajo) para caber junto a la lista a 1280 × 720 con el menú abierto. Prueba real 32/32 (PAN-01 y DIR-01): filas iguales a Postgres, filtros por carrera, sin `bg-/text-warn|ok` en la lista ni en las cifras, tabla sin desplazamiento lateral a 1280 px, 390 px en oscuro y teclado.

## Fuera de alcance

- Filtrar por carrera las unidades vencidas (`GET /api/equipment-units?maintenance_due=1` no filtra por carrera).
- Gráficos por mes o por tipo (eso va en dashboards, por GraphQL).
