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

- [x] `DatabaseSeeder`: la contraseña viene de `SEED_ADMIN_PASSWORD` (`config/sgia.php`); en producción sin ella no crea nada, y los datos de demostración no se siembran en producción. README alineado.
- [x] `docker-compose.yml` toma usuario y contraseña de `.env` (`DB_PASSWORD` obligatoria); `phpunit.xml` ya no las trae (salen de `.env.testing`, que sigue con la clave de desarrollo).
- [x] `CORS_ALLOWED_ORIGINS` ya no tiene valor por defecto: sin la variable no se permite ningún origen.
- [x] Subida de archivos: adjuntos de novedades y documentos de llegada pasaron al disco privado con descarga autenticada. MIME real (`mimes`) y tamaño máximo (10 MB) ya se validaban. Archivos ya guardados en `storage/app/public` hay que moverlos a `storage/app/private` a mano. La factura escaneada no se guarda.
- [x] Auditoría de acceso: `/alerts/*` no tenía control de rol (cualquier docente podía listar y resolver alertas); ahora `AD/DIR/PAN` leen y `AD/PAN` resuelven. `RouteProtectionTest` exige que toda ruta nueva declare `role:` o esté en la lista de compartidas, y que invitados reciban 401.
- [x] Límite `throttle:sensitive` (10/min por usuario) en cambio de contraseña, escaneo de facturas, escaneo de llegada e informes con adjunto.

## Fase 4 — API consistente

- [x] Aliases duplicados (el frontend ya usa los canónicos, sin riesgo de ruptura): `/me` + `/user`, `/password` + `/change-password`, `/logout-all` + `/tokens/revoke-all`. Canónicos: `/me`, `/password`, `/logout-all`. Retirar `/user`, `/change-password` y `/tokens/revoke-all`, y actualizar los tests y `ENDPOINTS.md`.
- [x] Unificar el formato de error (`message` + `errors`) y de paginación en todos los controladores. El frontend solo lee `message` y `errors`; `alert` y `purchase` son claves extra sin uso. Hecho: 401, 403 y 404 en español y sin exponer clases internas (`ErrorFormatTest`); paginación con `perPage()` en el controlador base.
- [x] Eliminaciones a 204 (el frontend ya lo tolera) y tests actualizados.
- [x] Controladores gruesos (`ProductController` 340 líneas, `LoanController` 283, `PurchaseController` 268): lógica movida a `ProductService` (filtros y resolución de ubicación), `LoanService` (filtros del historial) y `PurchaseService` (creación y filtros). Los controladores quedaron en 181-223 líneas.
- [x] Los filtros de listados están implementados a mano en cada controlador; los de productos, préstamos y compras (los largos) pasaron a sus Services; el resto tiene 1-3 filtros simples y se dejó. El cálculo de `per_page` estaba repetido 12 veces y ahora es `Controller::perPage()`.
- [x] Índices en BD para los filtros usados (`loans.estado`, `purchases.status`, `products.area`/`cajon_id`); migración `2026_10_28_000000_add_foreign_key_indexes` (claves foráneas; `status`, `type`, `area` y `loan_date` ya estaban indexados) y `Model::preventLazyLoading()` fuera de producción, con `ListQueryPerformanceTest`.
- [x] Revisar zona horaria: `APP_TIMEZONE=UTC` en testing; decisión: la API y la BD quedan en UTC (cambiarla desplazaría los datos guardados); las fechas-hora salen en ISO 8601 y los clientes las muestran en America/Santiago. Documentado en `ENDPOINTS.md`.

## Fase 5 — Funcionalidad simulada o incompleta

**Decisiones tomadas:** (1) dashboards en **GraphQL** (`/api/metrics`), como pide el requisito y como ya está construida la UI; (2) push móvil con **Expo**; (3) la alerta de stock llega a `AD-01`, `DIR-01` y `PAN-01` (hecho). Pendiente decidir: proveedor de OCR.

Esto es lo que más se aleja de lo que dicen los checkboxes.

- [ ] **OCR de facturas (REQ-03):** `InvoiceOcrService` devuelve productos fijos ("Osciloscopio…", "ElectroChile S.A.") salvo que el archivo sea un JSON de prueba. Integrar un proveedor real (decisión del equipo: servicio externo vs. Tesseract) detrás de una interfaz y dejar el stub solo para tests.
- [x] **Notificaciones (REQ-06, REQ-10):** alertas de stock y estado de préstamos salen por `broadcast`, correo y push de Expo (`ExpoPushChannel`, tabla `device_tokens`, `POST /api/devices` y `DELETE /api/devices/{id}`). Un fallo de Expo se registra y no interrumpe el flujo. Las notificaciones son `ShouldQueue`: en producción con `QUEUE_CONNECTION=database` hay que correr `php artisan queue:work`. No se añadió canal `database`: el panel web lee las alertas de la tabla `stock_alerts`.
- [ ] **Dashboards (REQ-14):** el requisito pide GraphQL en `/api/metrics`, con KPIs (activos disponibles/en préstamo/en taller/baja, préstamos del día, tasa de atrasos, alertas sin resolver, rotación) y filtro por período. Hoy hay 5 endpoints REST sin KPIs, sin filtro de semestre y sin concepto de atraso/devolución. El frontend tiene la pantalla hecha para GraphQL (`/api/metrics`, forma `MetricResult`, mockeada) y servicios REST sin usar que envían `since` (el backend lo ignora). **Decidido: GraphQL.** Elegir librería (p. ej. `nuwave/lighthouse` o `rebing/graphql-laravel`), exponer un único endpoint autenticado `/api/metrics` restringido a `DIR-01` y `AD-01`, reproducir la forma `MetricResult` del frontend y soportar rango de período (semestre, año, personalizado). Reutilizar `DashboardService`. Los endpoints REST `/api/dashboard/*` se mantienen hasta que el frontend migre y luego se retiran.
- [x] **Devoluciones y atrasos de préstamos:** `due_date`, `returned_at`, `POST /api/loans/checkin` (total o parcial, con condición por ítem), `DELETE /api/loans/requests/{id}`, `GET /api/loans/export` (CSV/PDF) y filtros `atrasado` / `devuelto`. Pendiente: notificar vencimientos.
- [x] **Estado "en taller" / "dado de baja"** de activos: `quantity_in_repair`, `quantity_written_off`, `estado` y `PATCH /api/products/{id}/condition`. "En préstamo" se calcula desde los ítems entregados y no devueltos (para los KPIs).
- [ ] **Cotizaciones:** el envío de correo a ≥3 proveedores usa `Mail`; verificar con cola real (`QUEUE_CONNECTION` es `sync` en tests) y manejo de reintentos/fallos por proveedor.

## Fase 6 — Pruebas

- [ ] Hay un solo `tests/Unit/ExampleTest.php` y `ExampleTest` en Feature: borrarlos o reemplazarlos.
- [x] Verificación de cobertura de la fase 2: se añadieron `RouteProtectionTest`, `DatabaseSeederTest` y un test de auditoría de productos.
- [ ] Tests unitarios para las máquinas de estado y `StockAlertService` (hoy solo se cubren vía HTTP).
- [ ] Cobertura (`php artisan test --coverage`) con umbral mínimo en CI una vez definida la fase 1.
- [ ] Tests de matriz de roles (ver fase 3) y de contrato de respuesta de los Resources.

---

## Orden recomendado

1. Fase 0 y 1 primero y en commits separados (limpian el diff para todo lo demás).
2. Fase 2 antes de tocar funcionalidad, para saber qué es requisito real.
3. Fases 3 y 4 en paralelo; la 4 requiere coordinar con el frontend.
4. Fase 5: ya están decididos GraphQL, Expo y destinatarios de la alerta; falta elegir el OCR.
5. Fase 6 se va haciendo con cada fase, no al final.
