# SGIA Backend

API REST (Laravel 13, PHP ^8.3) del *Sistema de Gestión de Inventario y Activos* para el Área de Informática y Ciberseguridad, INACAP Sede Temuco. Solo backend: el panel web (React/Vite) y la app móvil (React Native/Expo) viven en otro repositorio y consumen esta API en JSON.

Stack: Laravel + Sanctum (tokens Bearer), PostgreSQL 16 (Docker), DomPDF (PDFs), picqer/php-barcode-generator, PHPUnit 12, Pint.

## Comandos

```sh
docker compose up -d db                 # PostgreSQL (db sgia / sgia_testing, user sgia)
php artisan migrate --seed              # admin@sgia.cl + ubicaciones de prueba
php artisan serve                       # http://localhost:8000/api
php artisan test                        # requiere la BD sgia_testing arriba
php artisan test --filter=NombreTest
vendor/bin/pint --dirty                 # formatear solo lo que tocaste
```

Antes de dar una tarea por terminada: `php artisan test` en verde y `pint --dirty` sin cambios pendientes.

## Roles (`App\Models\User::ROLE_*`)

| Código | Rol | Puede |
|---|---|---|
| `AD-01` | Administrador | Todo, incluida la gestión de usuarios |
| `DIR-01` | Director de Carrera | Facturas, cotizaciones, compras, dashboards |
| `PAN-01` | Pañolero | Productos, ubicaciones, préstamos, informes |
| `PRO-01` | Docente | Solicitar préstamos, informes de novedades |

Los permisos se aplican en dos capas: middleware `role:...` en `routes/api.php` (grueso) y Policies en `app/Policies` (por recurso). `EnsureUserIsActive` (`active`) bloquea tokens de usuarios desactivados.

## Arquitectura

- `routes/api.php`: todas las rutas bajo `auth:sanctum` + `active`; cada grupo comentado con su REQ. Las rutas estáticas (`/loans/pending`) van antes de las `{loan}`.
- `app/Http/Controllers/Api`: delgados; validan con un FormRequest (`app/Http/Requests`), delegan en un Service y responden con un Resource (`app/Http/Resources`).
- `app/Services`: lógica de negocio. Los flujos con estado usan una máquina de estados (`LoanStateMachine`, `PurchaseStateMachine`, `QuotationStateMachine`) que lanza `app/Exceptions/Invalid*StatusTransition`.
- Stock: `StockAlertService` / `CheckStockAlertJob` generan alertas al llegar a `stock_minimo + 5` y a `stock_minimo`.
- Vistas Blade solo para PDFs (`resources/views/equipment`) y el correo de cotización.
- Tests de integración en `tests/Feature` (uno por módulo, contra PostgreSQL real; casi todos con `DatabaseTransactions`).

## Convenciones

- Código en inglés; textos al usuario (mensajes de error, validación) en español. `APP_LOCALE=es`.
- Auditoría en entidades de dominio: `created_by` / `updated_by`.
- Toda ruta nueva: middleware de rol, FormRequest, Resource y un test Feature (caso feliz, 403 por rol, 422 por validación).
- No crear alias de rutas nuevos (hoy existen `/me`+`/user`, `/password`+`/change-password`; ver `TASKS/rework.md`).
- Archivos subidos: siempre al disco privado `local` y descarga por endpoint autenticado; nunca al disco `public`.
- No tocar `vendor/`, `storage/` ni `bootstrap/cache/` a mano.
- Pint define el estilo; no mezclar reformateos masivos con cambios funcionales.

## Documentación del proyecto

- `ENDPOINTS.md`: catálogo de endpoints. Actualizarlo en el mismo cambio que modifique una ruta.
- `TASKS/`: requisitos (REQ-xx) por módulo con checkboxes; `TASKS/README.md` tiene el índice FU → archivo y la regla: marcar `[x]` solo si está hecho **y** tiene test.
- `docs/autenticacion.md`: guía técnica de Sanctum y CORS.
- `TASKS/rework.md`: plan de correcciones por fases tras el trabajo previo con opencode. Consultarlo antes de empezar trabajo estructural.
- `README.md`: puesta en marcha para humanos.

## Estado conocido (leer antes de confiar en el código)

- `InvoiceOcrService` es un **stub**: devuelve datos fijos; no hay OCR real.
- Las notificaciones solo usan el canal `broadcast`; no hay correo ni push real para alertas.
- Dashboards: el requisito original pedía GraphQL (`/api/metrics`); lo implementado es REST (`/api/dashboard/*`) y sin KPIs ni filtro por período.
