
### REQ-08 — Estado de compra (FU-03, DIR-01)
- [ ] Máquina de estados para `Quotation`/`Purchase`: `pendiente` → `en_camino` → `completa`.
- [ ] Endpoint para actualizar estado a `completa` al escanear la guía de despacho/factura de llegada.
- [ ] Endpoint `GET /api/purchases` con filtro por estado.
