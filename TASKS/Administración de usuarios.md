### REQ-02 — Administración de usuarios (FU-01, solo AD-01)
- [ ] CRUD `api/users` (nombre completo, correo, rol, contraseña, área).
- [ ] Endpoint `PATCH /api/users/{id}/status` para activar/desactivar sin eliminar de la BD.
- [ ] Policy que restrinja este CRUD únicamente al rol AD-01.
- [ ] Validación: correo único, contraseña con reglas mínimas de seguridad (hash con bcrypt/argon).
