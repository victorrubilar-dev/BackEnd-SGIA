# Requisitos del backend

Fuente: *Informe de Diseño de Arquitectura de Software — SGIA* (INACAP Sede Temuco, 2026). Roles y convenciones: `CLAUDE.md`. Plan de correcciones: `rework.md`.

## Módulos funcionales

| FU | Módulo | Archivos |
|---|---|---|
| FU-01 | Usuarios y accesos | `Autenticación.md` (REQ-01), `Administración de usuarios.md` (REQ-02) |
| FU-02 | Stock e inventario | `Productos.md` (REQ-03 a REQ-06) |
| FU-03 | Compras y adquisiciones | `Cotizaciones.md` (REQ-07), `Compra.md` (REQ-08) |
| FU-04 | Préstamos | `Prestamos.md` (REQ-09 a REQ-11) |
| FU-05 | Mantenimiento y fichas técnicas | `Fichas tecnicas.md` (REQ-12), `Informe de novedades.md` (REQ-13) |
| FU-06 | Reportes y dashboards | `Dashboards.md` (REQ-14) |
| — | Cambios para el frontend | `front-end.md` |
| — | Requisitos no funcionales | `Requisitos no funcionales.md` (REQ-NF-01 a 04) |

## Plantilla

Cada archivo agrupa REQ de un módulo con el encabezado `### REQ-xx — Título (FU-xx, rol)` y una lista de casillas.

## Regla para marcar `[x]`

Una tarea se marca solo si está implementada **y** cubierta por un test en `tests/Feature`. Si lo implementado es una simulación o cubre solo parte de la tarea, se deja `[ ]` y se explica debajo.
