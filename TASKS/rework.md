# Rework — plan de correcciones por fases

Origen: el backend fue construido antes con opencode. La suite pasa (175 tests, 885 aserciones), pero hay deuda de repositorio, documentación y funcionalidad simulada. Las fases van de menor a mayor riesgo; cada una se puede cerrar y commitear por separado.

Marcar `[x]` al terminar cada punto (con su test cuando aplique).

---

## Fase 0 — Higiene del repositorio

Sin cambios de comportamiento.

- [x] **`vendor/` está versionado** (8.838 archivos en git) aunque `.gitignore` lo excluye. Des-versionado con `git rm -r --cached vendor` (pendiente commitear aparte). Hoy `git status` muestra `vendor/composer/*` modificado.
- [x] Revisar los `.gitignore` de `storage/` y `bootstrap/cache/` que aparecen modificados: era solo el modo de archivo (644→755); restaurado a 644.
- [x] Crear `.env.example` (el README lo pide con `cp .env.example .env` y no existe). Incluir `DB_*`, `CORS_ALLOWED_ORIGINS`, `SANCTUM_*`, `MAIL_*`.
- [x] `.env.testing` está versionado: revisado: solo `APP_KEY` de prueba y la contraseña de desarrollo de Docker; se mantiene versionado.
- [x] Quitar `.phpunit.result.cache` del repo (no estaba versionado; ya está en `.gitignore`).
- [x] Corregir el README: dice PHP 8.4+, `composer.json` exige ^8.3; la sección "Rutas principales" lista 5 rutas de ~80; apunta a `AGENTS.md` como fuente de contexto de negocio y ese archivo ya no lo es (apuntar a `CLAUDE.md`).

## Fase 1 — Estilo y calidad estática

Cambios mecánicos; hacerlos en un commit propio para no mezclar con lógica.

- [x] `vendor/bin/pint` falla en ~90 archivos (`concat_space`, `not_operator_with_successor_space`, imports sin usar, migraciones antiguas). Decidir el estilo (Pint por defecto de Laravel) y ejecutar `pint` una sola vez.
- [x] Añadir un `pint.json` solo si se desvía del preset `laravel` (no hizo falta: se usa el preset por defecto).
- [ ] Opcional: Larastan nivel 5 como `require-dev` y baseline para lo existente.
- [x] CI mínima (GitHub Actions): PostgreSQL service, `composer install`, `pint --test`, `php artisan test`.

## Fase 2 — Documentación y requisitos coherentes

- [x] `TASKS/` tenía contradicciones. REQ-06 quedó definido como lo implementado: alerta a `AD-01` y `PAN-01` en `stock_minimo + 5` y `stock_minimo`; el envío por correo/push quedó como tarea pendiente. (La versión original pedía correo a DIR-01 y PAN-01: si es lo que se quiere, cambiarlo en fase 5.)
- [x] Faltan en `TASKS/` los requisitos REQ-01 y los no funcionales (REQ-NF-01/02) citados por `Autenticación.md`; los módulos FU-01…FU-06 ya no están descritos en ningún sitio. Recuperados desde el `AGENTS.md` original (git) en `TASKS/README.md`, `TASKS/Autenticación.md` y `TASKS/Requisitos no funcionales.md`.
- [x] Un archivo `TASKS/*.md` por módulo, con la misma plantilla: `REQ-xx — título (FU, rol)` + checklist. `Autenticación.md` es una guía de implementación, no una lista de requisitos: separado: la guía pasó a `docs/autenticacion.md`.
- [x] Marcar como `[x]` solo lo que tiene test; hoy `Dashboards.md` marca REST como hecho pero describe GraphQL (ver fase 5).
- [x] Reconciliar `ENDPOINTS.md` con `php artisan route:list --path=api`; REQ-12, 13 y 14 figuraban como pendientes y ya existen; los pendientes reales quedaron en una sola tabla.

## Fase 3 — Seguridad y configuración

- [ ] `DatabaseSeeder` crea `admin@sgia.cl` con contraseña fija `Admin1234!`; el README usa otra (`Secret-1234`). Leer la contraseña de `env` y no sembrar el admin en producción.
- [ ] `docker-compose.yml` y `phpunit.xml` llevan `sgia_secret` en claro: pasar a variables de entorno (`${DB_PASSWORD}`).
- [ ] Revisar que `CORS_ALLOWED_ORIGINS` no tenga valores por defecto de desarrollo en producción.
- [ ] Subida de archivos (factura, adjunto de novedades): validar MIME real, tamaño máximo y almacenar en disco privado; no servir desde `public/`.
- [ ] Auditar `Policy` vs. `role:` middleware por endpoint: matriz rol × ruta (tests parametrizados para 401/403).
- [ ] Revisar límites de rate-limit en el resto de endpoints sensibles (`/password`, escaneo de facturas).

## Fase 4 — API consistente

- [ ] Aliases duplicados: `/me` + `/user`, `/password` + `/change-password`, `/logout-all` + `/tokens/revoke-all`. Definir el canónico, marcar el resto como deprecado y retirarlo cuando el frontend migre (coordinar con `sgia-frontend`).
- [ ] Unificar el formato de error (`message` + `errors`) y de paginación en todos los controladores.
- [ ] Controladores gruesos (`ProductController` 340 líneas, `LoanController` 283, `PurchaseController` 268): mover la lógica restante a Services.
- [ ] Los filtros de listados están implementados a mano en cada controlador; extraer un patrón común si se repite más de dos veces.
- [ ] Índices en BD para los filtros usados (`loans.estado`, `purchases.status`, `products.area`/`cajon_id`); revisar N+1 con `Model::preventLazyLoading()` en entornos no productivos.
- [ ] Revisar zona horaria: `APP_TIMEZONE=UTC` en testing; definir la de producción (America/Santiago) y cómo se serializan las fechas.

## Fase 5 — Funcionalidad simulada o incompleta

Esto es lo que más se aleja de lo que dicen los checkboxes.

- [ ] **OCR de facturas (REQ-03):** `InvoiceOcrService` devuelve productos fijos ("Osciloscopio…", "ElectroChile S.A.") salvo que el archivo sea un JSON de prueba. Integrar un proveedor real (decisión del equipo: servicio externo vs. Tesseract) detrás de una interfaz y dejar el stub solo para tests.
- [ ] **Notificaciones (REQ-06, REQ-10):** `StockThresholdNotification` usa solo `broadcast`. Añadir `mail` y definir el canal push móvil (FCM/Expo); persistir en `database` para el panel web.
- [ ] **Dashboards (REQ-14):** el requisito pide GraphQL en `/api/metrics`, con KPIs (activos disponibles/en préstamo/en taller/baja, préstamos del día, tasa de atrasos, alertas sin resolver, rotación) y filtro por período. Hoy hay 5 endpoints REST sin KPIs, sin filtro de semestre y sin concepto de atraso/devolución. Decidir con el equipo si se mantiene REST (y se corrige el requisito) o se implementa GraphQL.
- [ ] **Devoluciones y atrasos de préstamos:** no existe flujo de devolución ni fecha de vencimiento; el KPI de tasa de atrasos y "devoluciones" del rol PAN-01 dependen de esto.
- [ ] **Estado "en taller" / "dado de baja"** de activos: no está modelado en `products`.
- [ ] **Cotizaciones:** el envío de correo a ≥3 proveedores usa `Mail`; verificar con cola real (`QUEUE_CONNECTION` es `sync` en tests) y manejo de reintentos/fallos por proveedor.

## Fase 6 — Pruebas

- [ ] Hay un solo `tests/Unit/ExampleTest.php` y `ExampleTest` en Feature: borrarlos o reemplazarlos.
- [ ] Tests unitarios para las máquinas de estado y `StockAlertService` (hoy solo se cubren vía HTTP).
- [ ] Cobertura (`php artisan test --coverage`) con umbral mínimo en CI una vez definida la fase 1.
- [ ] Tests de matriz de roles (ver fase 3) y de contrato de respuesta de los Resources.

---

## Orden recomendado

1. Fase 0 y 1 primero y en commits separados (limpian el diff para todo lo demás).
2. Fase 2 antes de tocar funcionalidad, para saber qué es requisito real.
3. Fases 3 y 4 en paralelo; la 4 requiere coordinar con el frontend.
4. Fase 5 depende de decisiones del equipo (OCR, push, GraphQL vs. REST): resolver esas tres antes de planificar.
5. Fase 6 se va haciendo con cada fase, no al final.
