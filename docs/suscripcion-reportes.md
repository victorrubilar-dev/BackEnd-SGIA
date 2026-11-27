# Suscripción de reportes por correo

**Estado: backend implementado (fases 1 a 5); frontend y prueba real pendientes.** Rama `feat/reportes-excel` de ambos repos. Amplía `docs/centro-reportes.md` (catálogo, filtros, vista previa, auditoría) y `docs/reportes-rankings.md`. Pedido: «suscripción de reportes donde se pueden marcar los reportes que queremos que nos lleguen al correo».

## Problema

Para tener el reporte del mes hay que acordarse de entrar a `/reportes`, elegir el período y descargarlo. No hay forma de recibir el informe del período o el historial de préstamos solo, cada semana o cada mes.

## Decisiones

Las marcadas con (usuario) las eligió el usuario el 2026-11-26; el resto sale de ellas o de cómo ya funciona el centro de reportes.

| # | Decisión |
|---|---|
| 1 | **Se marca en `/reportes`** (usuario). Junto a «Descargar Excel», el botón «Recibir por correo» abre un diálogo: frecuencia y los filtros que se guardan (los que están elegidos en ese momento). «Mis suscripciones» (junto a «Mis descargas») lista las del usuario con pausar, «Enviar ahora» y quitar. Roles: los del centro de reportes (`DIR-01`, `PAN-01`, `AD-01`), cada uno solo con los reportes de su rol. |
| 2 | **Frecuencia elegible por suscripción** (usuario): **semanal** (lunes, con la semana anterior, lunes a domingo), **mensual** (día 1, con el mes anterior) o **semestral** (1 de enero y 1 de julio, con el semestre que terminó: `2026-1` = enero–junio, la regla de `DashboardPeriod`). Se envían a las 07:00 de Chile (`America/Santiago`), antes de los recordatorios de las 08:00. |
| 3 | **El período no se guarda: se calcula en cada envío** (`from`/`to` según la frecuencia). Los reportes «foto» (inventario, ubicaciones, proveedores…) mandan el estado del momento del envío. En presupuestos, `year` toma el año del período enviado. El resto de los filtros (carrera, pañol, estado, `top`…) se guarda tal cual. |
| 4 | **El correo trae el Excel adjunto y un resumen** (usuario): asunto «SGIA · Historial de préstamos · noviembre 2026», el período con fechas, los filtros legibles, las filas por hoja y un enlace a `/reportes?reporte=…` con el reporte. Si el `.xlsx` pasa de **8 MB**, va solo el enlace. |
| 5 | **Datos personales nunca adjuntos.** Los reportes con `audited() = true` (estudiantes, usuarios, auditoría, monitoreo de accesos) van solo con el resumen y el enlace: el archivo se descarga con sesión iniciada. Así un correo reenviado no filtra RUN, correos ni IP. |
| 6 | **Sin filas, sin adjunto**: si todas las hojas de datos vienen vacías, el correo dice «Sin movimientos en el período» y no adjunta nada. |
| 7 | **Lo que llega es lo que se descarga.** El envío hace una **subpetición interna** a la misma ruta `/export` (o `/reports/…`) con los parámetros guardados y el usuario suscrito como autenticado (`Auth::guard('web')->setUser`, que Sanctum acepta). Pasa por el mismo middleware de rol, la misma validación y el mismo `ReportExporter`: no hay un segundo camino que pueda dar otras cifras. Primero con `preview=1` (filas por hoja para el resumen), después el `.xlsx`. |
| 8 | **Solo rutas de reporte.** `path` se valida contra una lista cerrada (`ReportRoutes::ALLOWED`, las 19 rutas del catálogo con sus parámetros fijos, como `format=excel`); cualquier otra da `422`. Al crear la suscripción se hace la subpetición con `preview=1`: si el rol no puede (`403`) o los filtros no valen (`422`), se responde ese error y no se guarda. |
| 9 | **Si el usuario pierde el permiso, la suscripción se pausa.** Un `403`/`422` al enviar, o un usuario inactivo, pausa la suscripción con el motivo (`paused_reason`), que se ve en «Mis suscripciones». Un error de servidor se reintenta 3 veces (cola) y queda `last_status = failed`. |
| 10 | **Una vez por período.** Cada suscripción guarda `last_period` (`2026-W47`, `2026-11`, `2026-2`): si el programador corre dos veces, no se repite el correo. «Enviar ahora» manda el último período completo y no toca `last_period`. |
| 11 | **Auditoría**: cada envío queda en `audit_logs` con la acción nueva `report.email` (reporte, filtros, período, filas, adjunto o enlace, correo de destino). No aparece en «Mis descargas» (que filtra `report.export`). La subpetición marca el canal con un atributo interno de la solicitud (`report_channel = email`), no con un parámetro ni una cabecera, así no se puede falsear desde fuera. |
| 12 | **Límite de 20 suscripciones por usuario** y sin duplicados (mismo reporte, mismos filtros y misma frecuencia → `422`). «Enviar ahora» con `throttle:sensitive`. |
| 13 | **Servicio de correo: se decide al desplegar** (usuario). El envío usa el mailer de Laravel (`MAIL_MAILER`); en desarrollo, **Mailpit** en `docker-compose.yml` (SMTP `:1025`, bandeja web `:8025`) para ver los correos. Hoy el despliegue AWS usa `MAIL_MAILER=log`: hasta elegir SES o SMTP, los correos quedan en el log y la interfaz lo avisa («El envío por correo no está configurado en este servidor») con `GET /api/me/report-subscriptions` → `mail_enabled: false`. |

## Contrato

```
GET    /api/me/report-subscriptions                 DIR-01, PAN-01, AD-01
       → { data: [Suscripción], meta: { mail_enabled: bool, limit: 20 } }
POST   /api/me/report-subscriptions                 { report_id, title, path, params, frequency }
       → 201 Suscripción | 422 (ruta no permitida, filtros, duplicada, límite) | 403 (el rol no puede ese reporte)
PATCH  /api/me/report-subscriptions/{id}            { frequency?, is_active? }   → Suscripción (reactivar limpia paused_reason)
DELETE /api/me/report-subscriptions/{id}            → 204
POST   /api/me/report-subscriptions/{id}/send       → 202 (en cola) | 429
```

Suscripción:

```json
{
  "id": 7,
  "report_id": "prestamos",
  "title": "Historial de préstamos",
  "path": "/loans/export?format=excel",
  "params": { "career_id": 3, "status": "procesado", "devuelto": "0" },
  "filters": { "Carrera": "Ingeniería en Informática", "Estado": "Procesado" },
  "frequency": "monthly",
  "is_active": true,
  "paused_reason": null,
  "next_send_at": "2026-12-01T07:00:00-03:00",
  "last_sent_at": "2026-11-01T07:00:12-03:00",
  "last_status": "sent",
  "created_at": "2026-11-26T21:40:00-03:00"
}
```

- `frequency`: `weekly` | `monthly` | `semester`. `params` sin `from`, `to` ni `preview` (se descartan si llegan).
- `report_id` es el `id` del catálogo del frontend; `title` se guarda para el correo y la lista.
- Solo se ven y se cambian las suscripciones propias (`404` en las ajenas).

## Migración

`report_subscriptions`: `id`, `user_id` (FK, borra en cascada), `report_id` (string 60), `title` (string 120), `path` (string 120), `params` (jsonb), `params_hash` (string 64, para el único), `filters` (jsonb: filtros legibles sin el período, para «Mis suscripciones»), `frequency` (string 10), `is_active` (bool), `paused_reason` (string 200, nullable), `last_period` (string 10, nullable), `last_sent_at`, `last_status` (`sent` | `empty` | `failed`, nullable), `timestamps`. Único (`user_id`, `report_id`, `params_hash`, `frequency`). Índice (`is_active`, `frequency`).

## Backend

- `ReportSubscription` (modelo), `ReportSubscriptionController`, `StoreReportSubscriptionRequest`, `ReportSubscriptionResource`, policy por dueño.
- `ReportRoutes`: lista cerrada de rutas y si son «foto» o «período» (la misma clasificación del catálogo).
- `ReportSubscriptionPeriod`: período anterior completo según la frecuencia y la fecha (`2026-W47` → 16-11 a 22-11), `next_send_at`.
- `ReportSubscriptionRunner`: arma la subpetición interna (preview + `.xlsx`), decide adjunto o enlace (decisiones 4 a 6) y devuelve el resultado; la subpetición lleva como atributo `report_channel` un `ReportEmailChannel`: `ReportExporter` anota en él título, filtros y datos personales y no registra `report.export`; el runner registra `report.email` cuando el correo ya salió. `report_id` y `path` deben corresponderse.
- `SendReportSubscriptionJob` (cola `database`, 3 intentos) y `ReportSubscriptionMail` (Markdown, en español, con el adjunto).
- Comando `sgia:send-report-subscriptions` en `routes/console.php`, `dailyAt('07:00')` en `America/Santiago`: toma las activas que tocan ese día y encola un trabajo por cada una. El despliegue AWS ya tiene `queue:work` y `schedule:run`.
- `docker-compose.yml`: servicio `mailpit`; `.env.example` con `MAIL_*` de Mailpit comentados y los de SES/SMTP como referencia.

## Frontend

- `packages/types`: `ReportSubscription`, `ReportSubscriptionFrequency`. `packages/api-client`: servicio y hooks (`useReportSubscriptions`, crear, cambiar, quitar, enviar ahora).
- `/reportes`: botón «Recibir por correo» (icono de sobre) junto a «Descargar Excel», deshabilitado si el reporte está bloqueado por el período. Diálogo con la frecuencia (tres opciones con lo que manda cada una: «Cada lunes, la semana anterior»), los filtros que se guardan en texto y una nota: «El período se calcula en cada envío». Si ya hay una suscripción igual, el botón dice «Suscrito · mensual» y abre «Mis suscripciones».
- «Mis suscripciones» (panel lateral como «Mis descargas»): título, frecuencia, filtros, próximo y último envío con su estado, motivo de la pausa, y las acciones pausar/reanudar, «Enviar ahora» y quitar (con confirmación). Aviso fijo si `mail_enabled` es falso.
- Reportes con datos personales: el diálogo avisa «Llega solo el resumen y un enlace; el archivo se descarga en SGIA».

## Fases

- [x] 1. Plan y documentos (este archivo, `CLAUDE.md`, `ENDPOINTS.md` de ambos repos, `TASKS`).
- [x] 2. Migración, modelo, `ReportRoutes`, CRUD `/me/report-subscriptions` con validación por subpetición `preview=1` + tests (dueño, lista cerrada, duplicada, límite, `403`/`422`, roles).
- [x] 3. `ReportSubscriptionPeriod` + `ReportSubscriptionRunner` + `ReportSubscriptionMail` + auditoría `report.email` + tests (`Mail::fake`: adjunto = descarga, enlace si > 8 MB, datos personales sin adjunto, sin filas, período por frecuencia).
- [x] 4. Comando programado, trabajo en cola, una vez por período, pausa por `403`/`422`/usuario inactivo, reintentos + tests.
- [x] 5. Mailpit en `docker-compose.yml`, `.env.example`, nota de despliegue (`MAIL_*`, cola y cron ya existentes).
- [ ] 6. Frontend: tipos, cliente, botón y diálogo, «Mis suscripciones», aviso sin correo + tests.
- [ ] 7. Prueba contra la API real con Mailpit: los tres roles, «Enviar ahora», adjunto que abre en LibreOffice y cuadra con la descarga, reporte con datos personales solo con enlace.

## Fuera de alcance

- Enviar a otras personas o a listas de correo (solo al correo del propio usuario).
- Elegir el día o la hora del envío.
- Desuscribirse desde el correo sin iniciar sesión (enlace firmado de un clic).
- Avisos por push o en la campana.
- Elegir el proveedor de correo (SES o SMTP institucional): se decide al desplegar.
