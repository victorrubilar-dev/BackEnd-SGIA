# SGIA Backend API

API REST en **Laravel** del *Sistema de Gestión de Inventario y Activos* (SGIA) para el Área de Informática y Ciberseguridad, INACAP Sede Temuco.

Este repositorio contiene **solo el backend** (capa de lógica de negocio). El panel web (React/Vite) y la app móvil (React Native/Expo) viven en un monorepo aparte (`sgia-frontend`) y consumen esta API vía HTTPS/JSON.

## Requisitos

- PHP 8.3+ con extensiones `pgsql`, `dom`, `openssl`
- Composer
- Docker (para el contenedor de PostgreSQL en desarrollo)
- PostgreSQL 16

## Puesta en marcha

```bash
# 1. Levantar la base de datos (PostgreSQL en Docker)
docker compose up -d db

# 2. Instalar dependencias
composer install

# 3. Configurar variables de entorno
cp .env.example .env     # ajustar credenciales si cambiaste docker-compose.yml
php artisan key:generate

# 4. Ejecutar migraciones (crea users, login_records, tokens, etc.)
php artisan migrate --seed

# 5. Iniciar el servidor
php artisan serve        # http://localhost:8000
php artisan queue:work   # notificaciones (correo/push) cuando QUEUE_CONNECTION=database
```

La contraseña del administrador sembrado se define con `SEED_ADMIN_PASSWORD` (por defecto `Admin1234!` solo fuera de producción; en producción el seeder no crea nada sin ella).

El contenedor expone PostgreSQL en `127.0.0.1:5432`, base `sgia`, usuario y contraseña tomados de `DB_USERNAME` / `DB_PASSWORD` en `.env` (ver `docker-compose.yml`).

## Autenticación

Se usa **Laravel Sanctum** con tokens Bearer (personal access tokens) para ambos clientes (web y mobile). No se usa el flujo de cookies/CSRF de SPA.

Flujo básico:

```bash
# Login (device_name: web | mobile)
curl -X POST http://localhost:8000/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@sgia.cl","password":"Admin1234!","device_name":"web"}'

# Uso del token
curl http://localhost:8000/api/me \
  -H "Authorization: Bearer <token>"

# Logout (revoca el token actual)
curl -X POST http://localhost:8000/api/logout \
  -H "Authorization: Bearer <token>"

# Listar/revocar tokens activos por dispositivo
curl http://localhost:8000/api/tokens -H "Authorization: Bearer <token>"
curl -X DELETE http://localhost:8000/api/tokens/{tokenId} -H "Authorization: Bearer <token>"
```

Duración de tokens por dispositivo: **mobile** 60 días, **web** 8 horas (configurable en `SANCTUM_EXPIRATION` / `config/sanctum.php`).

## Rutas

Catálogo completo con roles y parámetros en `ENDPOINTS.md`; fuente de verdad: `php artisan route:list --path=api`.

## Estructura del modelo de datos (núcleo)

- `users` — incluye `role` (`AD-01`, `DIR-01`, `PAN-01`, `PRO-01`), `area` y `last_login_at`.
- `login_records` — tabla normalizada de inicios de sesión: dispositivo (`web`/`mobile`), `user_agent`, `ip_address`, `logged_in_at`.
- `personal_access_tokens` — tokens Sanctum de sesión por dispositivo.

## Tecnologías

- **Backend:** Laravel (PHP)
- **Base de datos:** PostgreSQL 16 (Docker en desarrollo, AWS EC2 en producción)
- **Autenticación:** Laravel Sanctum (tokens Bearer)
- **Infraestructura:** AWS EC2

## Referencia del proyecto

Ver `CLAUDE.md` para arquitectura, roles y convenciones, `TASKS/` para el desglose por requisito (REQ-01 a REQ-14) y `TASKS/rework.md` para las correcciones pendientes.