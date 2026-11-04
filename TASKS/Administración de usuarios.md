### REQ-02 — Administración de usuarios (FU-01, solo AD-01)
- [x] CRUD `api/users` (nombre completo, correo, rol, contraseña, área).
- [x] Endpoint `PATCH /api/users/{id}/status` para activar/desactivar sin eliminar de la BD.
- [x] Policy que restrinja este CRUD únicamente al rol AD-01.
- [x] Validación: correo único, contraseña con reglas mínimas de seguridad (hash con bcrypt/argon).

### Catálogo de roles, pañol y carreras del usuario (FU-01, solo AD-01)
Modelos `Role`, `Storeroom` y pivote `career_user` (`TASKS/Equipos.md`, fase 1).
Decisión: `storeroom_id` del usuario es informativo (pañol por defecto del checkout); no filtra lo que ve un `PAN-01`. `career_ids` sirve hoy para los docentes; el cruce de los dashboards sigue usando `users.area`.
- [x] `GET /api/roles`: catálogo para el formulario de usuarios (sin CRUD: los roles son fijos).
- [x] `POST`/`PATCH /api/users` aceptan `storeroom_id?` y `career_ids?` (sincroniza `career_user`); `UserResource` añade `storeroom_id` y `careers` (`ResourceContractTest`, `front-end.md`).
- [x] `GET /api/storerooms` (todos los autenticados) y `POST`/`PATCH /api/storerooms` (`AD-01`); `DELETE` `204`, `422` si tiene salas, usuarios o préstamos.
- [x] Retirar `users.role`: middleware `role:` y `User::hasRole()` pasan a `role_id` vía `Role.code`, la API sigue recibiendo y devolviendo el código (`AD-01`…), y se elimina la columna (migración `2026_11_09_000000_drop_role_column_from_users`; atributo `User::role` y scope `withRole()`; `CoreModelsSchemaTest`).
- [x] Tests en `UserManagementTest` (pañol y carreras del usuario) y `StoreroomTest`.
