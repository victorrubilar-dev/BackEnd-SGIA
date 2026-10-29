### REQ-NF-01 / REQ-NF-02 — Seguridad OWASP (API)
- [x] `auth:sanctum` en todas las rutas protegidas (`RouteProtectionTest` recorre todas las rutas `api/*` y exige 401 a invitados).
- [x] Rate limiting en `POST /api/login` (5 intentos por minuto por IP + correo), con 429 y `Retry-After`.
- [x] Mensaje único de credenciales incorrectas (sin enumeración de usuarios).
- [x] Validación con `FormRequest` dedicados.
- [x] `$fillable` explícito en los modelos.
- [x] Contraseñas con `Hash::make()`.
- [ ] Registro de eventos críticos (usuarios, cambios de rol, bajas, aprobación de compras) en una tabla `audit_logs`. No existe.

### REQ-NF-03 — Accesibilidad de la API
- [x] Códigos HTTP semánticos (200, 201, 204, 401, 403, 404, 422, 429). Las eliminaciones responden 204.
- [ ] Error normalizado `{"message": "...", "errors": {...}}` en todos los endpoints (ver `rework.md`, fase 4).

### REQ-NF-04 — Rendimiento (< 2 s en el 90 % de las consultas)
- [x] Paginación en los listados (`per_page` por defecto 15, máximo 100).
- [ ] Eager loading verificado en todos los endpoints con relaciones (ver `rework.md`, fase 4).
- [ ] Índices para filtros frecuentes: `products.barcode`, `loans.status`, `purchases.status`, `products.area` (ver `rework.md`, fase 4).
