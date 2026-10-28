### REQ-01 — Autenticación por rol (FU-01)
- [x] `POST /api/login` (correo + contraseña) devuelve token Bearer vía Sanctum; `device_name` = `web` | `mobile`.
- [x] El login devuelve rol y área del usuario.
- [x] Expiración por dispositivo: `web` 8 horas, `mobile` 60 días.
- [x] Rechazo con 403 de usuarios desactivados, en login y con tokens ya emitidos (`EnsureUserIsActive`).
- [x] `POST /api/logout` revoca el token actual; `POST /api/logout-all` y `POST /api/tokens/revoke-others` revocan el resto.
- [x] `GET /api/tokens` y `DELETE /api/tokens/{id}` para listar y revocar sesiones por dispositivo.
- [x] `GET /api/me` devuelve el perfil (`UserResource`).
- [x] `PUT /api/password` cambia la contraseña (reglas `min(8)->letters()->numbers()`) y revoca las demás sesiones.
- [x] RBAC: middleware `role:...` y helpers `hasRole`, `isAdmin`, `isDirector`, `isWarehouse`, `isTeacher` en `User`.
- [x] Registro de sesiones: `last_login_at` y tabla `login_records` (dispositivo, user agent, IP, fecha).
- [x] CORS para el panel web y Expo web (`config/cors.php`, variable `CORS_ALLOWED_ORIGINS`).
- [x] Limpieza programada de tokens vencidos (`sanctum:prune-expired`).

Guía técnica: `docs/autenticacion.md`.
