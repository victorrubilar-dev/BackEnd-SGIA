### REQ-02 — Administración de usuarios (FU-01, solo AD-01)
- [x] CRUD `api/users` (nombre completo, correo, rol, contraseña, área).
- [x] Endpoint `PATCH /api/users/{id}/status` para activar/desactivar sin eliminar de la BD.
- [x] Policy que restrinja este CRUD únicamente al rol AD-01.
- [x] Validación: correo único, contraseña con reglas mínimas de seguridad (hash con bcrypt/argon).
