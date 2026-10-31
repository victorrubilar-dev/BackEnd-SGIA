# Modelos faltantes y unidades de equipo — plan de implementación

Estado: **en implementación (Parte 1 completa, fases 1-5).** Fuente del esquema objetivo: `MODELS.MD` (SQL de referencia). Cada fase se cierra con `php artisan test` en verde y `vendor/bin/pint --dirty` limpio, y se commitea por separado. No mezclar fases.

- **Parte 1 (fases 1–5):** crear en BD y como Model las tablas de `MODELS.MD` que no existen, y añadir a las tablas existentes las columnas que esas relaciones necesitan. Solo esquema, modelos, relaciones, factories, seeders y tests de esquema; **sin endpoints nuevos** salvo que se indique.
- **Parte 2 (fases 6–10):** la funcionalidad de unidades de equipo (un `Product` con varias unidades, cada una con su código y ubicación), préstamos por unidad, hoja de vida y métricas.

## Decisiones tomadas (Parte 1)

1. **Nombres en inglés** para tablas, columnas y modelos (ver tabla de correspondencias).
2. **Detalle de préstamo:** `loan_items` sigue apuntando al producto (insumos y solicitudes del docente por cantidad) y los equipos entregados van en el pivote `loan_item_units`, en lugar del `prestamo_detalles.equipo_id` de `MODELS.MD`.
3. **FKs sin dato en registros existentes quedan nullable** en BD: `products.career_id`, `quotations.career_id`, `quotations.title`, `suppliers.rut`. Son opcionales en la API, salvo `products.career_id`, que pasa a ser obligatorio cuando hay que generar un código de barras (decisión 4).
4. **Códigos de barra según la especificación de `CLAUDE.md`** (sección "Codigos de barra para elementos"): `PREFIJO-CARRERA-000001`, con carrera **siempre presente**, generados por `code_sequences` + `fn_generar_codigo_sgia` tal como están en `MODELS.MD`. Ver fase 2.
5. **Nivel de carreras:** `profesional` para II, IC e ISD; `tecnico` para AP.
6. **Maquetas** (`type = maqueta`): se gestionan por unidades y tienen entidad propia en métricas (`SCALE_MODELS`).
7. **Códigos heredados:** las unidades migradas de productos sin carrera quedan con `{barcode}-{nnn}`; no se asigna carrera por defecto.
8. **`stock_minimo`** es propio de cada producto (cada tipo tiene su umbral) y vale 5 por defecto.
9. **Endpoints de los modelos nuevos** tienen su propio REQ, que se implementa después de la Parte 1 (y de la fase 6 cuando dependen de unidades): REQ-15 dashboard personalizado (`Dashboards.md`), REQ-16 presupuestos y REQ-17 ingresos de facturas (`Compra.md`), REQ-18 mantenciones y REQ-19 documentos (`Fichas tecnicas.md`), REQ-20 estudiantes (`Prestamos.md`); roles, pañoles y carreras del usuario en `Administración de usuarios.md`.

## Reglas generales para traducir `MODELS.MD`

1. **Nombres en inglés.** `CLAUDE.md` exige código en inglés y todas las tablas actuales lo están (`users`, `products`, `loans`…). Las tablas de `MODELS.MD` se crean con el nombre en inglés de la tabla de correspondencias; los valores de dominio (estados, tipos) siguen en español y en minúsculas como el resto de la API (`disponible`, no `DISPONIBLE`).
2. **Tablas que ya existen con otro nombre** no se recrean: se amplían con las columnas que les faltan (ver tabla).
3. **`ON DELETE` / `ON UPDATE`:** respetar lo de `MODELS.MD` (`restrictOnDelete`, `cascadeOnDelete`, `nullOnDelete`). `ON UPDATE CASCADE` no hace falta (los ids no cambian); omitirlo.
4. **Auditoría:** las entidades de dominio nuevas llevan `created_by` / `updated_by` (FK `users`, nullable, `nullOnDelete`) y `timestamps()`, aunque `MODELS.MD` solo traiga `created_at`. Las tablas pivote y de catálogo puro (`roles`, `career_user`) solo `timestamps()`.
5. **`NOT NULL` sobre datos existentes:** cuando `MODELS.MD` pide una FK obligatoria en una tabla con datos (ej. `productos.categoria_id`), la migración la crea **nullable**, rellena los datos existentes y, si todos quedaron con valor, la pasa a `NOT NULL` en la misma migración. Si no hay forma de rellenarla (ej. `career_id` de productos antiguos), queda nullable y la obligatoriedad, si aplica, va en el FormRequest (decisión 3).
6. **Archivos** (`*_url` de `MODELS.MD`): se guardan como ruta en el disco privado `local` (columna `*_path`) y se descargan por endpoint autenticado; nunca URL pública.
7. Cada modelo nuevo: `HasFactory`, `$fillable`, `casts()` (decimales `decimal:2`, booleanos, fechas, `array` para JSONB), relaciones tipadas y factory en `database/factories`.

## Tabla de correspondencias

| `MODELS.MD` | Tabla Laravel | Modelo | Situación | Fase |
|---|---|---|---|---|
| `roles` | `roles` | `Role` | **Nueva** | 1 |
| `panoles` | `storerooms` | `Storeroom` | **Nueva** | 1 |
| `usuarios` | `users` | `User` | Existe; añadir `role_id`, `storeroom_id` | 1 |
| `carreras` | `careers` | `Career` | Existe; añadir `code` | 1 |
| `carrera_usuario` | `career_user` | (pivote) | **Nueva** | 1 |
| `plantillas_grafico` | `chart_templates` | `ChartTemplate` | **Nueva** | 1 |
| `graficos` | `user_charts` | `UserChart` | **Nueva** | 1 |
| `salas` | `locations` | `Location` | Existe; añadir `storeroom_id`, `building`, `floor` | 2 |
| `estantes` | `cajones` | `Cajon` | Existe; añadir `barcode` | 2 |
| `categorias` | `categories` | `Category` | **Nueva** | 2 |
| `codigos` + `fn_generar_codigo_sgia` | `code_sequences` + función | `CodeSequence` | **Nueva** | 2 |
| `productos` | `products` | `Product` | Existe; añadir categoría, carrera y mantención | 2 |
| `equipos` | `equipment_units` | `EquipmentUnit` | **Nueva** | 3 |
| `presupuestos_anuales` | `annual_budgets` | `AnnualBudget` | **Nueva** | 4 |
| `ingresos_ocr` | `invoice_intakes` | `InvoiceIntake` | **Nueva** | 4 |
| `ingreso_ocr_detalles` | `invoice_intake_items` | `InvoiceIntakeItem` | **Nueva** | 4 |
| `proveedores` | `suppliers` | `Supplier` | Existe; añadir `rut`, `address` | 4 |
| `procesos_cotizacion` | `quotations` | `Quotation` | Existe; añadir `career_id`, `title` | 4 |
| `cotizaciones` | `quotation_suppliers` | `QuotationSupplier` | Existe; añadir `quotation_number`, `backup_file_path` | 4 |
| `cotizacion_items` | `quotation_offer_items` | `QuotationOfferItem` | **Nueva** (precio ofertado por proveedor) | 4 |
| `informes_novedades` | `incident_reports` | `IncidentReport` | Existe; añadir `equipment_unit_id`, `request_type`, foto | 5 |
| `registros_mantencion` | `maintenance_records` | `MaintenanceRecord` | **Nueva** | 5 |
| `documentos_mantenimiento` | `maintenance_documents` | `MaintenanceDocument` | **Nueva** | 5 |
| `estudiantes` | `students` | `Student` | **Nueva** | 5 |
| `prestamos` | `loans` | `Loan` | Existe; añadir `storeroom_id`, `student_id`, `expected_return_at` | 5 |
| `prestamo_detalles` | `loan_items` + `loan_item_units` | `LoanItem` | Existe; añadir cantidades + pivote nuevo | 5 |

Correspondencia de columnas ya existentes (no duplicar): `productos.sku` = `products.barcode`; `productos.stock_actual` = `products.quantity`; `productos.foto_url` = `products.photo_url`; `proveedores.razon_social` = `suppliers.name`, `nombre_contacto` = `contact_name`, `telefono` = `phone`, `activo` = `is_active`; `usuarios.activo` = `users.is_active`; `cotizaciones.monto_total` = `quotation_suppliers.offer_total`, `fecha_envio` = `email_sent_at`; `prestamos.despachado_por_id` = `loans.processed_by`, `recibido_por_id` = `returned_by`, `fecha_hora_prestamo` = `processed_at`, `fecha_hora_devolucion_real` = `returned_at`, `observaciones` = `notes`, `tipo_prestamo` = `type`, `profesor_id` = `requested_by`; `informes_novedades.usuario_id` = `reported_by`, `documento_adjunto_url` = `attachment`, `fecha_reporte` = `created_at`.

---

# Parte 1 — Modelos faltantes

## Fase 1 — Núcleo institucional, usuarios y analítica

- [x] `roles`: `id`, `code` (string 10, unique: `AD-01`…), `name` (string 100), timestamps. Seeder idempotente (`RoleSeeder`, llamado desde `DatabaseSeeder` también en producción) con los 4 roles de `User::ROLE_*`. Modelo `Role` con `users(): HasMany`.
- [x] `users`: `role_id` (FK `roles`, `restrictOnDelete`). Migración: crear nullable, insertar los 4 roles, rellenar `role_id` desde `users.role`, pasar a `NOT NULL`. **`users.role` se mantiene** como código que usan el middleware `role:` y `User::hasRole()`; un evento `saving` en `User` sincroniza `role_id` a partir de `role` (una sola fuente de escritura: `role`). `User::roleModel(): BelongsTo` (no `role()`, que choca con el atributo).
- [x] `storerooms` (pañoles): `id`, `name` (150), `code` (50, unique), `general_location` (255, nullable), auditoría, timestamps. Seeder con un pañol por defecto (`PAN-TEMUCO`) para rellenar FKs. Modelo `Storeroom` con `users`, `locations`, `loans`.
- [x] `users.storeroom_id` (FK `storerooms`, nullable, `nullOnDelete`). `User::storeroom()`.
- [x] `careers.code` (string 3, unique, nullable para carreras sin código; `CHECK (code IN ('ISD','II','IC','AP'))`, constante `Career::CODES`). Es el segmento `CARRERA` de los códigos de barra. `StoreCareerRequest`/`UpdateCareerRequest`: `code` opcional, único y dentro de la lista; `CareerResource` añade `code` → actualizar `ResourceContractTest` y `front-end.md`.
- [x] `CareerSeeder` idempotente (también en producción): asigna el código a la carrera existente con el mismo nombre (sin distinguir mayúsculas ni tildes) o la crea: `ISD` Telecomunicaciones, `II` Ingeniería en Informática, `IC` Ingeniería en Ciberseguridad, `AP` Analista Programador. `careers.level` es obligatorio: `profesional` para II, IC e ISD; `tecnico` para AP.
- [x] `career_user`: `id`, `user_id` (FK `cascadeOnDelete`), `career_id` (FK `cascadeOnDelete`), timestamps, `unique(user_id, career_id)`. `User::careers(): BelongsToMany`, `Career::users()`. Migración: rellenar el pivote uniendo `users.area` con `careers.name` (sin distinguir mayúsculas). `users.area` se mantiene (lo usan `MetricsService` y `DashboardService`).
- [x] `chart_templates`: `id`, `code` (50, unique), `name` (100), `visualization_type` (50), `description` (255, nullable), timestamps. Seeder con las plantillas que hoy usa el frontend en `dashboard-page.tsx` (consultar `TASKS/front-end.md`; si no hay lista, sembrar una por tipo de visualización: `bar`, `line`, `pie`, `kpi`).
- [x] `user_charts`: `id`, `user_id` (FK `cascadeOnDelete`), `chart_template_id` (FK `restrictOnDelete`), `custom_title` (150), `type` (30), `position` (int), `entity` (50), `metric` (50), `group_by` (50), `period` (50), `is_active` (bool, default true), timestamps. `unique(user_id, position)` **`DEFERRABLE INITIALLY DEFERRED`** (para reordenar en bloque, REQ-15) y `CHECK (position BETWEEN 1 AND 5)`, ambos con `DB::statement` en PostgreSQL. `entity`/`metric`/`period` deben aceptar los mismos valores que `customMetric` de `/api/metrics` (constantes de `MetricsService`/`DashboardPeriod`; validación cuando haya endpoint).
- [x] Tests `tests/Feature/CoreModelsSchemaTest`: relaciones, backfill de `role_id` y `career_user`, sincronización `role` → `role_id` al cambiar el rol, `unique(user_id, position)` y el `CHECK` (posición 6 → `QueryException`). Ajustar `DatabaseSeederTest`.

## Fase 2 — Ubicación física, catálogo y secuenciador

- [x] `locations` (salas): `storeroom_id` (FK `storerooms`, `restrictOnDelete`; nullable → rellenar con el pañol por defecto → `NOT NULL`), `building` (string 10, nullable), `floor` (int, nullable). `Location::storeroom()`, `Storeroom::locations()`. `StoreLocationRequest`/`UpdateLocationEntityRequest`: campos opcionales (`storeroom_id` por defecto el pañol por defecto); `LocationResource` añade las tres claves → `ResourceContractTest` y `front-end.md`.
- [x] `cajones` (estantes): `barcode` (string 100, unique). Migración: crear nullable, rellenar con `BarcodeService::generateUniqueCode('EST')`, pasar a `NOT NULL`. Al crear un cajón sin `barcode`, generarlo. `CajonResource` añade `barcode`. **Ojo:** `cajones.location_id` hoy es `cascadeOnDelete`; `MODELS.MD` pide `RESTRICT` (`estantes.sala_id`). Cambiarlo a `restrictOnDelete` y verificar que el borrado de ubicaciones ya responde `422` cuando tiene cajones (`LocationAndCajonCrudTest`).
- [x] `categories`: `id`, `name` (100), `description` (255, nullable), auditoría, timestamps. Modelo `Category` con `products()`.
- [x] **Formato de códigos** (especificación de `CLAUDE.md`): `PREFIJO-CARRERA-000001`, siempre con carrera (ej. `INS-ISD-000002`). Índice de 6 dígitos con ceros a la izquierda, correlativo por combinación prefijo + carrera.
  - Prefijos (constantes en `CodeSequence`): `MAQ` maquetas, `INS` insumos, `PRO` productos (equipos).
  - Qué lleva el código: en `insumo` el código va en el producto (`products.barcode`, el insumo no tiene unidades); en `equipo` y `maqueta` va en cada unidad (`equipment_units.code`), y el producto conserva su `barcode` interno actual (`BarcodeService`, `SGIA-xxxxxxxx`) como SKU.
  - Los `barcode` ya existentes de productos no se regeneran.
- [x] Nuevo tipo de producto `maqueta` (`Product::TYPE_SCALE_MODEL = 'maqueta'` en `Product::TYPES`; `StoreProductRequest`/`UpdateProductRequest` lo aceptan). Se gestiona por unidades igual que `equipo`: en todo este plan, "producto con unidades" = `equipo` o `maqueta` (`Product::hasUnits()`). En métricas tiene **entidad propia** (`SCALE_MODELS`, fase 9): `EQUIPMENT` sigue filtrando solo `type = equipo`, así que las maquetas no se mezclan.
- [x] `code_sequences` (`codigos`): `id`, `name` (20), `prefix` (string 3), `career` (string 3, NOT NULL), `current_index` (int, default 0; `indice` en `MODELS.MD`), timestamps; `unique(prefix, career)`; `CHECK (career IN ('ISD','II','IC','AP'))` con `DB::statement`.
- [x] Función PostgreSQL `fn_generar_codigo_sgia(p_prefijo VARCHAR(3), p_carrera VARCHAR(3))` creada con `DB::unprepared()` en la misma migración (`down()` la elimina). Igual a la de `MODELS.MD`, cambiando solo los nombres de tabla y columnas (`code_sequences`, `prefix`, `career`, `current_index`). El `UPDATE … RETURNING` bloquea la fila, así que es atómica con llamadas concurrentes. Lanza excepción si la combinación no existe.
- [x] `App\Services\CodeSequenceService`:
  - `next(string $prefix, string $career): string` llama a la función (`DB::selectOne('select fn_generar_codigo_sgia(?, ?) as code', …)`) y traduce la excepción de PostgreSQL a `ValidationException` en español ("No existe un secuenciador para el prefijo X y la carrera Y.").
  - `forProduct(Product $product): string` elige el prefijo por `type` (`insumo` → `INS`, `equipo` → `PRO`, `maqueta` → `MAQ`) y la carrera por `products.career_id → careers.code`. Si el producto no tiene carrera, o su carrera no tiene código → `ValidationException` en `career_id` ("El producto debe tener una carrera con código para generar el código de barras.").
- [x] `CodeSequenceSeeder` idempotente (también en producción): las 12 combinaciones `MAQ`/`INS`/`PRO` × `ISD`/`II`/`IC`/`AP`, con `name` `maquetas` / `insumos` / `productos`, sin reiniciar `current_index` si ya existen.
- [x] Alta de productos: `career_id` obligatorio (`required`, debe tener `code`) cuando el código se genera: `insumo` sin `barcode`, y `equipo`/`maqueta` con `quantity > 0` (crean unidades en la fase 6). En el resto de casos sigue opcional. Insumo sin `barcode` → `barcode = CodeSequenceService::forProduct()`. Para `equipo`/`maqueta` sin `barcode`, se mantiene `BarcodeService::generateUniqueCode()`. **Nota:** la parte de `equipo`/`maqueta` con `quantity > 0` se aplica en la fase 6, cuando existan las unidades que consumen el código.
- [x] `products`, columnas nuevas:
  - `category_id` (FK `categories`, `restrictOnDelete`). Migración: crear una categoría por cada valor distinto de `products.area` (y "Sin categoría" para los nulos), rellenar y pasar a `NOT NULL`. `products.area` se mantiene (lo usan filtros y `MetricsService` como `hardware_category`); dejar anotado retirarlo cuando el frontend use `category_id`.
  - `career_id` (FK `careers`, `restrictOnDelete`, nullable por los productos antiguos; obligatorio en la API cuando se genera un código, ver arriba). Define el segmento `CARRERA` de los códigos.
  - `maintenance_priority` (char 1, default `C`, `CHECK IN ('A','B','C')`, indexado).
  - `requires_calibration` (bool, default false), `maintenance_frequency_months` (int, nullable), `reference_price` (decimal 15,2, nullable).
  - `stock_minimo` ya existe: **se mantiene por producto con default 5** para todos los tipos (`MODELS.MD` dice 0; decisión 8). En `insumo` se compara con `quantity`; en `equipo`/`maqueta`, con las unidades `disponible` (que es lo que vale `quantity` derivado), así que `StockAlertService` no cambia.
  - `Product::category()`, `Product::career()`; `StoreProductRequest`/`UpdateProductRequest` aceptan los campos nuevos (`category_id` obligatorio al crear, el resto opcional); `ProductResource` añade `category_id`, `career_id`, `maintenance_priority`, `requires_calibration`, `maintenance_frequency_months`, `reference_price` → `ResourceContractTest` y `front-end.md` (el alta sin `category_id` pasa a `422`).
- [x] Tests: `CodeSequenceTest` (formato `INS-ISD-000001`, correlativo independiente por prefijo + carrera, combinación inexistente → `422`, `CHECK` rechaza una carrera fuera de la lista), alta de insumo genera `INS-{carrera}-…`, alta sin carrera cuando hay que generar código → `422`, tipo `maqueta` aceptado, backfill de categorías y barcodes de cajones, `CHECK` de prioridad. Ajustar `ProductManagementTest` (los tests de alta actuales sin `career_id` deben enviarla o pasar `barcode`) y `LocationAndCajonCrudTest`.

## Fase 3 — Equipos (unidades)

Corresponde a `equipos` de `MODELS.MD`. Solo esquema y modelo; la funcionalidad va en la Parte 2.

- [x] `equipment_units`: `id`, `product_id` (FK `products`, **`restrictOnDelete`**), `cajon_id` (FK `cajones`, `restrictOnDelete`, **NOT NULL**: el estante es la ubicación física; la sala y el pañol se derivan por `cajon → location → storeroom`), `code` (string 100, unique; = `codigo_barras`), `serial_number` (string 100, nullable, unique), `status` (string 50, default `disponible`), `last_maintenance_at` (timestamp nullable), `next_maintenance_at` (timestamp nullable), auditoría, timestamps. Índices: `(product_id, status)` y parcial `next_maintenance_at WHERE next_maintenance_at IS NOT NULL` (`DB::statement`).
- [x] Modelo `EquipmentUnit` con constantes `STATUS_AVAILABLE = 'disponible'`, `STATUS_ON_LOAN = 'prestado'`, `STATUS_IN_REPAIR = 'en_reparacion'`, `STATUS_WRITTEN_OFF = 'dado_de_baja'`, `STATUSES`; relaciones `product`, `cajon`, `creator`, `updater`; `location()` vía `cajon` (`hasOneThrough` o accesor). `Product::units(): HasMany`, `Cajon::equipmentUnits()`.
- [x] **Borrado de productos:** con `restrictOnDelete`, `DELETE /products/{id}` de un producto con unidades lanzaría error de BD. `ProductController::destroy` debe responder `422` ("El producto tiene equipos registrados; dalos de baja en su lugar."). Igual para `DELETE /cajones/{id}` con unidades (la protección actual solo mira productos) y para el borrado masivo.
- [x] Migración de datos (con `DB::table`), para cada producto `type = equipo` (todavía no hay maquetas):
  - Crear `quantity` unidades `disponible`, `quantity_in_repair` `en_reparacion`, `quantity_written_off` `dado_de_baja`.
  - Por cada `loan_item` de ese producto con préstamo `en_proceso` o `procesado` y `returned_at` nulo: `quantity` unidades `prestado` (se vinculan al préstamo en la fase 5).
  - `cajon_id`: el del producto; si no tiene, un cajón `SIN-ASIGNAR` en una ubicación "Sin asignar" del pañol por defecto (crearlos si no existen).
  - `code`: los productos antiguos no tienen carrera, así que no pueden usar el secuenciador. Se usa `{products.barcode}-{nnn}` (correlativo de 3 dígitos por producto). Quedan como códigos heredados (decisión confirmada; no se asigna carrera por defecto). Si después se les asigna carrera, se pueden reetiquetar a mano con `PATCH /equipment-units/{id}` (fase 6). `CodeSequenceService` y `BarcodeService` deben tratar ambos formatos como válidos al buscar por código.
  - `down()`: borrar la tabla.
- [x] `EquipmentUnitFactory` (estados `available()`, `onLoan()`, `inRepair()`, `writtenOff()`).
- [x] Tests `EquipmentUnitSchemaTest`: migración de datos (producto 3/1/1 + préstamo procesado sin devolver de 2 → 7 unidades), productos sin cajón quedan en `SIN-ASIGNAR`, borrado de producto/cajón con unidades → `422`.

## Fase 4 — Finanzas, ingesta OCR y cotizaciones

- [x] `annual_budgets`: `id`, `career_id` (FK `restrictOnDelete`), `year` (int), `initial_amount` y `available_amount` (decimal 15,2), auditoría, timestamps; `unique(career_id, year)`. `Career::annualBudgets()`. Regla en el modelo/servicio (sin endpoint aún): `available_amount` nunca negativo (`CHECK (available_amount >= 0)`).
- [x] `invoice_intakes` (`ingresos_ocr`): `id`, `user_id` (FK `restrictOnDelete`), `annual_budget_id` (FK `restrictOnDelete`), `pdf_path` (nullable, disco privado), `document_number` (100, nullable), `total_deducted` (decimal 15,2, nullable), `raw_ocr_payload` (`jsonb`, nullable, cast `array`), `status` (50, nullable; valores `borrador` | `confirmado` | `anulado`), `received_at` (timestamp, default `now`), timestamps.
- [x] `invoice_intake_items`: `id`, `invoice_intake_id` (FK `cascadeOnDelete`), `product_id` (FK `restrictOnDelete`), `detected_name` (255, nullable), `quantity` (int), `unit_price` y `subtotal` (decimal 15,2, nullable), timestamps.
- [x] Modelos `InvoiceIntake` (`user`, `annualBudget`, `items`) e `InvoiceIntakeItem` (`intake`, `product`). Sin endpoints en esta fase: el flujo (subida, confirmación, descuento de presupuesto e ingreso de stock) es REQ-17 en `Compra.md`. `POST /api/invoices/scan` no se toca.
- [x] `suppliers`: `rut` (string 20, unique, nullable y opcional; si viene, validar formato y dígito verificador de RUT chileno con una regla `App\Rules\ChileanRut`, mensaje en español), `address` (255, nullable). `SupplierResource` y FormRequests → `ResourceContractTest`, `front-end.md`.
- [x] `quotations` (`procesos_cotizacion`): `career_id` (FK `restrictOnDelete`) y `title` (150), ambos nullable y opcionales en `StoreQuotationRequest`. `QuotationResource` → contrato.
- [x] `quotation_suppliers` (`cotizaciones`): `quotation_number` (100, nullable), `backup_file_path` (nullable, disco privado). `QuotationSupplierResource` → contrato. `quotation_suppliers.supplier_id` hoy es `cascadeOnDelete`; `MODELS.MD` pide `RESTRICT`: cambiarlo y comprobar que `DELETE /suppliers/{id}` con cotizaciones responde `422`.
- [x] `quotation_offer_items` (`cotizacion_items`): `id`, `quotation_supplier_id` (FK `cascadeOnDelete`), `product_id` (FK `restrictOnDelete`), `requested_quantity` (int), `offered_unit_price` (decimal 15,2, nullable), timestamps. `QuotationSupplier::offerItems()`. Es el precio ofertado por cada proveedor por ítem; `quotation_items` (cantidades pedidas en el proceso) se mantiene.
- [x] Tests `FinanceModelsSchemaTest`: relaciones, `unique(career_id, year)`, `CHECK` de saldo, cast JSONB, `RESTRICT` de proveedores.

## Fase 5 — Mantención, estudiantes y préstamos

- [x] `incident_reports`: `equipment_unit_id` (FK `equipment_units`, **nullable** por los informes existentes, `restrictOnDelete`; obligatorio en informes nuevos de productos `equipo` desde la fase 8), `request_type` (50, default `falla`; valores `falla` | `reposicion` | `mantencion`), `evidence_photo_path` (nullable, disco privado). `product_id` se mantiene. `IncidentReport::equipmentUnit()`, `EquipmentUnit::incidentReports()`.
- [x] `maintenance_records`: `id`, `equipment_unit_id` (FK `restrictOnDelete`), `responsible_id` (FK `users`, `restrictOnDelete`), `external_supplier_id` (FK `suppliers`, nullable, `nullOnDelete`), `incident_report_id` (FK nullable, `nullOnDelete`), `maintenance_type` (50: `preventiva` | `correctiva` | `calibracion`), `execution_mode` (30, default `medios_propios`; `medios_propios` | `externa`), `rev_a_approved`, `rev_b_approved` (bool, default false), `rev_c_approved` (bool, nullable), `pfg_approved`, `requires_additional_maintenance`, `applies_recf` (bool, default false), `repair_cost` (decimal 15,2, default 0), `spare_parts_used`, `technical_notes` (text, nullable), `status` (50: `en_curso` | `finalizada` | `anulada`), `started_at` (timestamp, default `now`), `finished_at` (nullable), auditoría, timestamps. Índice `(equipment_unit_id, status)`. Regla de modelo: `external_supplier_id` obligatorio si `execution_mode = externa` (validación en el FormRequest cuando exista endpoint; dejar constante y test de modelo).
- [x] `maintenance_documents`: `id`, `equipment_unit_id` (FK `cascadeOnDelete`), `maintenance_record_id` (FK nullable, `nullOnDelete`), `uploaded_by` (FK `users`, `restrictOnDelete`), `document_type` (50), `title` (150), `file_path` (disco privado), `valid_until` (timestamp nullable), timestamps.
- [x] Al finalizar un `MaintenanceRecord`, actualizar `equipment_units.last_maintenance_at` y calcular `next_maintenance_at` con `products.maintenance_frequency_months` (método `MaintenanceRecord::finish()` o servicio; test unitario). Sin endpoint en esta fase.
- [x] `students`: `id`, `rut` (20, unique), `full_name` (150, nullable), `email` (150, nullable), `career_id` (FK nullable, `nullOnDelete`), `registered_at` (timestamp, default `now`), timestamps.
- [x] `loans`: `storeroom_id` (FK `restrictOnDelete`; nullable → rellenar con el pañol por defecto → `NOT NULL`), `student_id` (FK `students`, nullable, `restrictOnDelete`), `expected_return_at` (timestamp nullable; rellenar desde `due_date` al final del día; `due_date` se mantiene por compatibilidad). Índice `(storeroom_id, status)`. `borrower_name`/`borrower_document` se mantienen: en el checkout, si `borrower_document` es un RUT, crear o reutilizar el `Student` y guardar `student_id` (puede quedar para la fase 7).
- [x] `loan_items` (`prestamo_detalles`): `delivered_quantity` y `returned_quantity` (int, default 0); `quantity` = `cantidad_solicitada`. Rellenar: entregados = `quantity` si el préstamo está `procesado`; devueltos = `quantity` si `returned_at` no es nulo.
- [x] `loan_item_units` (pivote préstamo ↔ equipo; ver decisión sobre `prestamo_detalles.equipo_id`): `id`, `loan_item_id` (FK `cascadeOnDelete`), `equipment_unit_id` (FK `restrictOnDelete`), `returned_at` (nullable), `return_condition` (nullable, valores `LoanItem::CONDITION_*`), timestamps; `unique(loan_item_id, equipment_unit_id)`, índice en `equipment_unit_id`. Migración: vincular las unidades `prestado` creadas en la fase 3 con sus `loan_items`. `LoanItem::units(): BelongsToMany` con `withPivot`, `EquipmentUnit::loanItems()`.
- [x] Tests `MaintenanceAndLoanSchemaTest`: relaciones, backfill de `storeroom_id`, cantidades y pivote, cálculo de `next_maintenance_at`.
- [x] Al cerrar la Parte 1: `ResourceContractTest`, `RouteProtectionTest` y `ListQueryPerformanceTest` en verde; `TASKS/front-end.md` con todas las claves nuevas de Resources.

---

# Parte 2 — Funcionalidad de unidades de equipo

## Decisiones tomadas

0. **Productos con unidades** = `equipo` y `maqueta` (`Product::hasUnits()`); donde abajo dice "equipo", aplica a ambos.
1. **Contadores derivados.** Para `equipo`, las unidades son la fuente de verdad: `products.quantity`, `quantity_in_repair` y `quantity_written_off` se recalculan desde las unidades (`disponible`, `en_reparacion`, `dado_de_baja`; las `prestado` no suman, igual que hoy el préstamo descuenta `quantity`). Alertas, dashboards y `ProductResource` mantienen su contrato. Los insumos no cambian.
2. **Préstamos con asignación de unidades:** el docente pide producto + cantidad; al descontar stock se asignan las unidades escaneadas (`unit_ids`) o, si no vienen, unidades `disponible` automáticas. La devolución cambia el estado de cada unidad según su condición.
3. **Hoja de vida por unidad, compatible:** endpoints nuevos por unidad; las rutas `/equipment/{product}/...` se mantienen a nivel de producto.
4. **Rutas en `/equipment-units`:** `/equipment/{id}` ya existe con `{product}` y chocaría.

## Fase 6 — Sincronización y endpoints de unidades

- [ ] `EquipmentUnitStateMachine` (estilo `LoanStateMachine`) + `App\Exceptions\InvalidEquipmentUnitStatusTransition` (registrar su render como las otras):
  - `disponible` → `prestado`, `en_reparacion`, `dado_de_baja`
  - `prestado` → `disponible`, `en_reparacion`, `dado_de_baja` (solo por devolución)
  - `en_reparacion` → `disponible`, `dado_de_baja`
  - `dado_de_baja` → (final)
- [ ] `App\Services\EquipmentUnitService`:
  - `syncProductCounters(Product $product)`: transacción, `lockForUpdate` del producto, `GROUP BY status`, guarda los tres contadores y llama a `StockAlertService::checkStock()`. **Todo cambio de estado o alta/baja de unidades termina aquí.**
  - `createUnits(Product $product, int $count, array $attributes, ?int $userId)`: códigos con `CodeSequenceService::forProduct($product)` (`PRO-{carrera}-…` o `MAQ-{carrera}-…`); sin carrera con código → `422`. Un `code` enviado a mano solo se acepta en el alta individual y debe ser único.
  - `changeStatus(EquipmentUnit $unit, string $to, ?int $userId, bool $fromLoan = false)`: valida con la máquina de estados; `prestado` como origen o destino solo si `$fromLoan` (si no, `422`: "El estado prestado solo se cambia mediante préstamos.").
- [ ] `BarcodeService::generateUniqueCode()` comprueba unicidad en `products.barcode`, `cajones.barcode` y `equipment_units.code` (un escaneo no puede ser ambiguo).
- [ ] Rutas (lectura: todos los autenticados; escritura `AD-01`, `DIR-01`, `PAN-01`; borrado `AD-01`, `DIR-01`, como `ProductPolicy`). Middleware `role:` + `EquipmentUnitPolicy`; estáticas antes de `{equipmentUnit}`:

| Método | Ruta | Descripción |
|---|---|---|
| GET | `/products/{product}/units` | Paginado; filtros `status`, `cajon_id`, `location_id`, `search` (código o serie). |
| POST | `/products/{product}/units` | Una unidad (`code?`, `serial_number?`, `cajon_id` obligatorio) o varias con `count`. `422` si el producto es `insumo`. `201`. |
| GET | `/equipment-units` | Listado global; filtros `product_id`, `status`, `cajon_id`, `location_id`, `storeroom_id`, `search`, `maintenance_due` (`next_maintenance_at <= hoy`). |
| GET | `/equipment-units/by-code/{code}` | Búsqueda exacta por código (escaneo móvil). `404` si no existe. |
| GET | `/equipment-units/{equipmentUnit}` | Detalle con producto, ubicación (cajón → sala → pañol) y préstamo activo. |
| PATCH | `/equipment-units/{equipmentUnit}` | `code`, `serial_number`, `cajon_id`, fechas de mantención. No `status` ni `product_id`. |
| PATCH | `/equipment-units/{equipmentUnit}/status` | `status`; usa `changeStatus`. |
| DELETE | `/equipment-units/{equipmentUnit}` | `204`; `422` si tiene préstamos, mantenciones o informes, o está `prestado` (darla de baja en su lugar). |
| GET | `/equipment-units/{equipmentUnit}/barcode` | Igual que `/products/{id}/barcode` con `code`. |

- [ ] `EquipmentUnitController` delgado, FormRequests (`Store`, `Update`, `UpdateStatus`) con mensajes en español, `EquipmentUnitResource` (`id`, `product_id`, `product`, `code`, `serial_number`, `status`, `cajon_id`, `cajon`, `location`, `storeroom`, `last_maintenance_at`, `next_maintenance_at`, auditoría y fechas) en `ResourceContractTest`. Filtros en el servicio con eager loading (añadir a `ListQueryPerformanceTest`).
- [ ] Reglas de producto:
  - `POST /products` `equipo` con `quantity = N` y `cajon_id` crea N unidades. Opcional `units: [{serial_number?}]`.
  - `PATCH /products/{id}` de un `equipo`: `quantity*` prohibidos → `422` ("La cantidad de un equipo se calcula a partir de sus unidades."). `stock_minimo` editable.
  - `type` `equipo` → `insumo` con unidades → `422`; `insumo` → `equipo` genera unidades desde los contadores.
  - `PATCH /products/{id}/condition` de un `equipo` → `422` indicando `PATCH /equipment-units/{id}/status`.
- [ ] Tests `EquipmentUnitTest` (feliz, `403` por rol, `422`), `StateMachineTest` (mapa nuevo), contadores y alertas tras cada operación. Ajustar `ProductManagementTest` y `ProductConditionTest`.

## Fase 7 — Préstamos con unidades

- [ ] `deductStock()`: `insumo` como hoy; `equipo` asigna `unit_ids` validados (cantidad = `quantity`, mismo producto, `disponible`, `lockForUpdate`) o `quantity` unidades `disponible` automáticas (`orderBy('id')`); las pasa a `prestado`, crea filas en `loan_item_units`, fija `delivered_quantity` y sincroniza. Sin unidades suficientes → el `422` de stock insuficiente actual.
- [ ] `POST /loans/checkout`: `items.*.unit_ids` opcional (`array`, `distinct`, `exists:equipment_units,id`); prohibido para insumos. `storeroom_id` opcional (por defecto el del pañolero o el pañol por defecto).
- [ ] `POST /loans/{loan}/approve`: asignación automática.
- [ ] `POST /loans/{loan}/deliver`: cuerpo opcional `items: [{item_id, unit_ids}]` para reemplazar la asignación por las unidades realmente entregadas (libera las anteriores a `disponible`). `DeliverLoanRequest`.
- [ ] `POST /loans/checkin`: las filas actuales (`item_id`/`product_id` + `condition`) aplican a todas las unidades pendientes del ítem; nuevo `items.*.unit_ids` para devolución parcial por unidad (no repetir unidades; ajustar el control `$seen`, hoy por ítem). `bueno`/`regular` → `disponible`, `danado` → `en_reparacion`, `extraviado` → `dado_de_baja`. Pivote con `returned_at`/`return_condition`; `returned_quantity` del ítem; el `LoanItem` queda devuelto cuando todas sus unidades lo están (`return_condition` = la peor: `extraviado` > `danado` > `regular` > `bueno`). Para `equipo`, `restoreItem()` deja de sumar contadores y sincroniza.
- [ ] `LoanItemResource`: claves `units` (whenLoaded: `id`, `code`, `serial_number`, `status`, `returned_at`, `return_condition`), `delivered_quantity`, `returned_quantity`; eager loading en `show`, `pending`, `index` y respuestas de checkout/checkin/deliver. `ResourceContractTest`.
- [ ] Export CSV/PDF de préstamos: códigos de las unidades junto al nombre.
- [ ] Tests: ampliar `LoanOperationTest`, `LoanReturnTest`, `LoanRequestTest` (asignación automática, `unit_ids` ajenos/no disponibles/de insumo, deliver con reemplazo, devolución total y parcial por unidad, contadores y alertas, dos checkouts por la última unidad: uno falla).

## Fase 8 — Hoja de vida por unidad

- [ ] `POST /equipment/{product}/reports`: `equipment_unit_id` (obligatorio si el producto es `equipo`, debe pertenecer a `{product}`), `request_type`, `evidence_photo` opcional (imagen, 10 MB, disco privado) además del adjunto. `IncidentReportResource` añade `equipment_unit_id`, `equipment_unit`, `request_type`, `has_evidence_photo` → contrato. Descarga `GET /incident-reports/{id}/evidence-photo`.
- [ ] `GET /incident-reports`: filtros `equipment_unit_id`, `request_type`.
- [ ] `GET /equipment-units/{equipmentUnit}/reports` (JSON paginado o `?format=pdf`).
- [ ] `GET /equipment-units/{equipmentUnit}/technical-sheet`: PDF con producto (categoría, prioridad, calibración), código, serie, estado, ubicación (pañol/sala/estante), fechas de mantención, historial de préstamos, mantenciones e informes de la unidad. Método nuevo en `EquipmentPdfService` y vista en `resources/views/equipment/`.
- [ ] La ficha del producto (`/equipment/{product}/technical-sheet`) añade la tabla de sus unidades.
- [ ] Endpoints de mantención y documentos: REQ-18 y REQ-19 en `Fichas tecnicas.md` (se implementan después de esta fase).
- [ ] Tests: ampliar `IncidentReportTest` y `TechnicalSheetTest`.

## Fase 9 — Dashboards y métricas

- [ ] `MetricsService` `EQUIPMENT`: `groupBy: location` cuenta unidades (no productos) por sala del cajón de la unidad, excluyendo `dado_de_baja`; `groupBy: physical_condition` cuenta unidades por `status` (añadir `prestado` solo si el frontend lo admite).
- [ ] Entidad nueva `SCALE_MODELS` en `customMetric` (enum GraphQL en `app/GraphQL`, `MetricsService`): mismas métricas y agrupaciones que `EQUIPMENT` (ranking de más solicitadas, `hardware_category`/`category`, `location`, `physical_condition`), filtrando `products.type = 'maqueta'`. Reutilizar el método de `EQUIPMENT` parametrizando el tipo, sin duplicar consultas. Las tarjetas de `executiveKpis` siguen sumando todos los productos. Documentar la entidad en `front-end.md` (tabla "Qué devuelve cada entidad").
- [ ] `MAINTENANCE` `groupBy: workshop`: sala de la unidad cuando el informe tenga `equipment_unit_id`.
- [ ] `DashboardService::kpis` sin cambios (lee contadores); test que coincide con el conteo de unidades.
- [ ] Ampliar `MetricsGraphQLTest` con unidades de un producto en dos salas, y con `SCALE_MODELS` (cuenta solo maquetas; `EQUIPMENT` no las incluye).

## Fase 10 — Documentación

- [ ] `ENDPOINTS.md`: rutas nuevas y campos nuevos.
- [ ] `TASKS/front-end.md`: todas las claves nuevas de Resources (Parte 1 y 2), los `422` nuevos (alta de producto sin `category_id`, cantidades de equipo, `/condition`, borrados con unidades), pantallas sugeridas (unidades por producto, escaneo por código, selección de unidades al entregar y devolver).
- [ ] `TASKS/Productos.md`, `Prestamos.md`, `Fichas tecnicas.md`, `Informe de novedades.md`: casillas nuevas, marcadas solo con test.
- [ ] `CLAUDE.md` "Estado conocido" y "Arquitectura": tabla de correspondencias resumida (`MODELS.MD` ↔ tablas) y que en `equipo` los contadores se derivan de `equipment_units`.

## Pendiente fuera de este plan

- **Retirar `users.role`** y pasar el middleware a `role_id`: tarea en `Administración de usuarios.md`, después de la fase 1.

## Riesgos

- **Doble fuente de verdad:** solo `syncProductCounters()` escribe `products.quantity*` para equipos; revisar con `grep -rn "quantity" app/` al cerrar la fase 7.
- **`restrictOnDelete` nuevos** (productos, cajones, proveedores): convertir los errores de BD en `422` antes de que lleguen al usuario; buscar todos los `->delete()` afectados.
- **Bloqueos:** bloquear siempre unidades y luego producto, en ese orden.
- **Migraciones con backfill:** probarlas con `php artisan migrate:fresh --seed` y también sobre una copia de la BD de desarrollo con datos, no solo en la BD de tests.
- **Frontend:** casi todas las fases cambian Resources; coordinar con `front-end.md` antes de desplegar.
