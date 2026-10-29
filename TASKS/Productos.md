### REQ-03 — Alta/edición de productos vía escaneo de facturas (FU-02, DIR-01 y AD-01)
- [x] Endpoint `POST /api/invoices/scan` (alias `/products/scan-invoice`) que recibe la factura y devuelve un borrador de productos.
- [ ] Extracción real de la factura: hoy `InvoiceOcrService` devuelve datos fijos (ver `rework.md`, fase 5).
- [x] Endpoint `POST /api/products` para confirmar el borrador y crear el producto (nombre, cantidad, proveedor, área, foto opcional).
- [x] Endpoint `PATCH /api/products/{id}` para editar y `PATCH /api/products/{id}/status` para activar/desactivar.

### REQ-04 — Alta/edición de productos por Pañol (FU-02, PAN-01)
- [x] Mismo CRUD de REQ-03 pero accesible también a PAN-01.
- [x] Generación automática de **código de barras único** al crear el producto (servicio interno, ej. Code128/EAN).
- [x] Validación: nombre sin caracteres especiales, cantidad positiva, proveedor existente.

### REQ-05 — Ubicación física de productos (FU-02)
- [x] Entidad `Ubicación` (salas, pañoles, talleres, bodegas) con auditoría `created_by`, `updated_by` y timestamps.
- [x] Entidad `Cajón` perteneciente a una `Ubicación` (`location_id`, código único por ubicación, descripción, auditoría `created_by`, `updated_by`).
- [x] Producto asociado a un `Cajón` (`cajon_id`), derivando su ubicación física a través de este compartimiento.
- [x] CRUD de ubicaciones (`/api/locations`) y cajones (`/api/cajones`) accesible para roles `AD-01`, `DIR-01` y `PAN-01`.
- [x] Endpoint `GET /api/products/{id}/location` y `PATCH /api/products/{id}/location` para consultar y actualizar el cajón/ubicación física.
- [x] Filtros en `GET /api/products` por `cajon_id`, `location_id`, `sala` y `cajon`.
- [x] Seeder con ubicaciones y cajones precargados para pruebas.

### REQ-06 — Alertas de stock crítico (FU-02)
- [x] Campo `stock_minimo` por producto.
- [x] La alerta se dispara al llegar a `stock_minimo + 5` y al llegar a `stock_minimo`; se resuelve al recuperar stock.
- [x] Destinatarios: usuarios activos `AD-01`, `DIR-01` y `PAN-01`.
- [x] Registro de la alerta en BD y `GET /api/alerts/critical-stock` para el panel.
- [ ] Envío por correo y push móvil: hoy la notificación solo usa el canal `broadcast` (ver `rework.md`, fase 5).

### Estado operativo del stock (FU-05)
- [x] Contadores `quantity_in_repair` y `quantity_written_off` por producto, y `estado` resumido (`disponible`, `en_reparacion`, `dado_de_baja`, `sin_stock`).
- [x] `PATCH /api/products/{id}/condition`: enviar a taller, reintegrar y dar de baja unidades.
