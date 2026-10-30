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
- Tests unitarios en `tests/Unit` (máquinas de estado, `StockAlertService`). `ResourceContractTest` fija las claves de cada Resource: si lo tocas, actualiza `TASKS/front-end.md`.
- Tests de integración en `tests/Feature` (uno por módulo, contra PostgreSQL real; casi todos con `DatabaseTransactions`).

## Convenciones

- Código en inglés; textos al usuario (mensajes de error, validación) en español. `APP_LOCALE=es`.
- Auditoría en entidades de dominio: `created_by` / `updated_by`.
- Toda ruta nueva: middleware de rol, FormRequest, Resource y un test Feature (caso feliz, 403 por rol, 422 por validación).
- Una sola ruta por operación: no crear alias. Las eliminaciones responden `204` sin cuerpo.
- Archivos subidos: siempre al disco privado `local` y descarga por endpoint autenticado; nunca al disco `public`.
- No tocar `vendor/`, `storage/` ni `bootstrap/cache/` a mano.
- Pint define el estilo; no mezclar reformateos masivos con cambios funcionales.

## Documentación del proyecto

- `ENDPOINTS.md`: catálogo de endpoints. Actualizarlo en el mismo cambio que modifique una ruta.
- `TASKS/`: requisitos (REQ-xx) por módulo con checkboxes; `TASKS/README.md` tiene el índice FU → archivo y la regla: marcar `[x]` solo si está hecho **y** tiene test.
- `docs/autenticacion.md`: guía técnica de Sanctum y CORS.
- `TASKS/rework.md`: plan de correcciones por fases tras el trabajo previo con opencode. Consultarlo antes de empezar trabajo estructural.
- `TASKS/front-end.md`: cambios del backend que afectan al frontend. Actualizarlo cuando un cambio rompa o modifique el contrato de la API.
- `README.md`: puesta en marcha para humanos.

## Estado conocido (leer antes de confiar en el código)

- `InvoiceOcrService` lee facturas con AWS Textract `AnalyzeExpense` (`TextractExpenseReader`, `OCR_DRIVER=textract`, credenciales `AWS_ACCESS_KEY_ID`/`AWS_SECRET_ACCESS_KEY`/`AWS_SESSION_TOKEN`/`AWS_DEFAULT_REGION`). Si Textract falla (token temporal vencido, PDF multipágina, WEBP, red) cae al lector local: `pdftotext` (PDF digital) y Tesseract (imágenes y PDF escaneado, vía `pdftoppm`), que requiere `poppler-utils` y `tesseract-ocr` + `tesseract-ocr-spa`. `OCR_DRIVER=local` salta Textract (los tests lo fuerzan en `.env.testing`). El parseo local es heurístico; ni Textract ni Tesseract se probaron aún con facturas reales.
- Las notificaciones (alertas de stock y estado de préstamos) van por `broadcast`, correo y push de Expo, en cola: en producción hace falta `php artisan queue:work`. Las alertas de stock van a `AD-01`, `DIR-01` y `PAN-01`.
- Dashboards: GraphQL en `POST /api/metrics` (`app/GraphQL`, `MetricsService`) y REST en `/api/dashboard/*`. `products.type` (`equipo`/`insumo`) separa `EQUIPMENT` de `SUPPLIES`; el nivel de carrera vive en `careers` y se une por nombre con `users.area` (texto libre: si no coincide, cae en "Sin nivel").
