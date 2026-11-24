# Mantenciones: carreras de un vistazo y bandeja filtrable por carrera

**Estado: plan, sin implementar.** Rama `feat/mantenciones-por-carrera` en ambos repos. Pantalla `/mantenciones` (`PAN-01`, `DIR-01`). REQ-18.

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

1. [ ] Plan y documentos (este archivo, `CLAUDE.md`, `TASKS/Fichas tecnicas.md`, `TASKS/front-end.md`, `ENDPOINTS.md` en ambos repos, `TASKS/REQ-12` del frontend).
2. [ ] Backend: filtro `career_id` / `sin_carrera` con test en `MaintenanceRecordTest`.
3. [ ] Frontend: tipos y cliente (`career_id`, `sin_carrera`), página rediseñada, tests.
4. [ ] Prueba en Chromium contra la API de desarrollo como `PAN-01` y `DIR-01` (1440 y 390 px, claro y oscuro): cifras contra la base, filtro por carrera, sin desplazamiento lateral.

## Fuera de alcance

- Filtrar por carrera las unidades vencidas (`GET /api/equipment-units?maintenance_due=1` no filtra por carrera).
- Gráficos por mes o por tipo (eso va en dashboards, por GraphQL).
