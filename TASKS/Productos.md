
### REQ-03 — Alta/edición de productos vía escaneo de facturas (FU-02, DIR-01)
- [ ] Endpoint que reciba imagen/documento de factura y devuelva un borrador de producto(s) extraído(s) (nombre, cantidad, proveedor) para confirmación posterior. (El OCR/extracción puede ser un servicio externo invocado desde la API; definir en Tecnologías.)
- [ ] Endpoint `POST /api/products` para confirmar el borrador y crear el producto (nombre, cantidad, proveedor, área, foto opcional).
- [ ] Endpoint `PATCH /api/products/{id}` para editar y `PATCH /api/products/{id}/status` para activar/desactivar.

### REQ-04 — Alta/edición de productos por Pañol (FU-02, PAN-01)
- [ ] Mismo CRUD de REQ-03 pero accesible también a PAN-01.
- [ ] Generación automática de **código de barras único** al crear el producto (servicio interno, ej. Code128/EAN).
- [ ] Validación: nombre sin caracteres especiales, cantidad positiva, proveedor existente.

### REQ-05 — Ubicación física de productos (FU-02)
- [ ] Campos `sala` y `cajón` (o modelo `Location`) asociados a cada producto/ítem.
- [ ] Endpoint `GET /api/products/{id}/location` y filtro de listado por ubicación.

### REQ-06 — Alertas de stock crítico (FU-02)
- [ ] Definir `stock_minimo` por producto.
- [ ] Job/evento que dispare alerta cuando el stock llegue a `minimo + 5` y cuando llegue al `minimo`.
- [ ] Notificación multicanal (in-app/web + push a móvil) a usuarios AD-01 y PAN-01. (Canal de push a definir en Tecnologías.)
