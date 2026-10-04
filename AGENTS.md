### REQ-03 — Alta/edición de productos vía escaneo de facturas (FU-02, DIR-01)
- [x] Endpoint que reciba imagen/documento de factura y devuelva un borrador de producto(s) extraído(s) (nombre, cantidad, proveedor) para confirmación posterior. (El OCR/extracción puede ser un servicio externo invocado desde la API; definir en Tecnologías.)
- [x] Endpoint `POST /api/products` para confirmar el borrador y crear el producto (nombre, cantidad, proveedor, área, foto opcional).
- [x] Endpoint `PATCH /api/products/{id}` para editar y `PATCH /api/products/{id}/status` para activar/desactivar.

### REQ-04 — Alta/edición de productos por Pañol (FU-02, PAN-01)
- [x] Mismo CRUD de REQ-03 pero accesible también a PAN-01.
- [x] Generación automática de **código de barras único** al crear el producto (servicio interno, ej. Code128/EAN).
- [x] Validación: nombre sin caracteres especiales, cantidad positiva, proveedor existente.

### REQ-05 — Ubicación física de productos (FU-02)
- [x] Campos `sala` y `cajón` (o modelo `Location`) asociados a cada producto/ítem.
- [x] Endpoint `GET /api/products/{id}/location` y `PATCH /api/products/{id}/location` para consultar y actualizar ubicación física.

### REQ-06 — Alertas de stock crítico (FU-02)
- [x] Campo `stock_minimo` por producto.
- [x] Job programado / trigger en evento de rebaja de stock que detecte si `stock_actual <= stock_minimo`.
- [x] Notificación vía email (y registro en BD para panel web) al Director de Carrera (DIR-01) y Pañolero (PAN-01).
- [x] Endpoint `GET /api/alerts/critical-stock` para el panel web / mobile.
