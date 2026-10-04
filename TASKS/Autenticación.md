## 5.1 Autenticación y CORS (SPA web + mobile)

Este backend debe servir de forma consistente a **dos clientes distintos**: el panel web (React/Vite, corre en el navegador, sujeto a CORS) y la app móvil (React Native/Expo, no es un navegador, no aplica CORS). La estrategia elegida evita tener dos mecanismos de auth distintos para no duplicar lógica ni casos borde.

**Paquete: Laravel Sanctum.**

Se descarta Passport (OAuth2 completo) porque no hay necesidad de emitir tokens a terceros ni flujos `authorization_code`/`client_credentials`; Sanctum cubre exactamente lo que se necesita (tokens simples tipo API) con mucho menos overhead de configuración y mantenimiento.

**Modo de uso: tokens Bearer para ambos clientes, no autenticación por cookies de Sanctum ("SPA authentication").**

Aunque Sanctum ofrece un modo especial de cookies/CSRF para SPAs en el mismo dominio (`stateful` domains), aquí se usa el modo de **personal access tokens** (Bearer) para ambos clientes por estas razones:
- El panel web y la API probablemente se despliegan en subdominios/dominios distintos dentro de AWS (o incluso separados por ahora), lo que complica el flujo de cookies + CSRF.
- Mobile no puede usar cookies de sesión de forma práctica; necesita Bearer tokens de todas formas.
- Usar el mismo mecanismo (Bearer) en ambos clientes evita mantener dos flujos de auth en paralelo y simplifica el `api-client` compartido del monorepo frontend.

**CADA TAREA AL FINALIZARLA SE DEBE MARCAR COMO LISTA**

---

### Tareas base completadas
- [x] Antes de iniciar las tareas el usuario debe tener como campos adicionales:
    - Ultimo inicio de sesión (`last_login_at`)
    - los siguientes campos deben estar en una tabla normalizada (`login_records`): 
        - Navegador / móvil (`device_type`, `user_agent`)
        - Fecha de inicio de sesión (`logged_in_at`)
        - IP (`ip_address`)
- [x] Crear campos necesarios para el usuario como área y rol (`role`, `area`)
- [x] Instalar y configurar Sanctum solo para **API tokens** (no usar el middleware `EnsureFrontendRequestsAreStateful`, ya que no habrá flujo de cookies/CSRF).
- [x] En `POST /api/login`, validar credenciales y emitir el token con `$user->createToken($deviceName)->plainTextToken`, donde `$deviceName` identifica el cliente (`web`, `mobile`) para poder listar/revocar sesiones por dispositivo si se requiere.
- [x] Definir expiración de tokens en `config/sanctum.php` (`expiration`); considerar tokens más largos para mobile (el docente no debería re-loguearse constantemente) y más cortos para web.
- [x] `POST /api/logout` → `$request->user()->currentAccessToken()->delete()`.
- [x] (Opcional) Endpoint para que un usuario liste y revoque sus tokens activos por dispositivo (`GET /api/tokens`, `DELETE /api/tokens/{tokenId}`).
- [x] Endpoint `POST /api/login` (correo + contraseña) que devuelva token Bearer vía Sanctum.
- [x] Devolver en la respuesta de login el rol y área del usuario para que el cliente adapte su UI/navegación.

---

### Tareas de Autenticación y Seguridad

#### 1. Configuración de CORS (`config/cors.php`)
- [x] Publicar y configurar `config/cors.php` para la API REST:
    - [x] `paths` → `['api/*']`.
    - [x] `allowed_origins` → orígenes específicos según entorno (`env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173')`). En producción apuntar al dominio exacto del panel web (ej. `https://sgia-web.inacap-temuco.cl`), nunca `*`.
    - [x] `allowed_methods` → `['GET', 'POST', 'PATCH', 'PUT', 'DELETE', 'OPTIONS']`.
    - [x] `allowed_headers` → incluir `['Authorization', 'Content-Type', 'Accept', 'X-Requested-With']`.
    - [x] `supports_credentials` → `false` (no se usan cookies para autenticación Bearer).
    - [x] Permitir origen de desarrollo móvil cuando Expo ejecute en modo web local.

#### 2. Estado de Usuario y Bloqueo de Cuentas Inactivas (REQ-01 / REQ-02)
- [x] Agregar campo `is_active` (boolean, default true) a la tabla `users` mediante migración.
- [x] Actualizar modelo User.php (atributo `$fillable`, `$casts` booleano).
- [x] Validar estado en `POST /api/login`: si `is_active === false`, rechazar la autenticación inmediatamente con código HTTP 403 Forbidden y mensaje de cuenta inactiva / desactivada por el administrador.
- [x] Crear middleware `EnsureUserIsActive` para que solicitudes subsecuentes con token válido de un usuario desactivado sean rechazadas automáticamente (Sanctum valida el token pero no valida por defecto si el usuario fue desactivado después de emitir el token).
- [x] Registrar middleware en `bootstrap/app.php` y aplicarlo a las rutas protegidas bajo `auth:sanctum`.

#### 3. Control de Acceso Basado en Roles (RBAC) (REQ-01 / REQ-NF-01)
- [x] Implementar middleware `CheckRole` (o `EnsureHasRole:AD-01,DIR-01,...`) para restringir rutas protegidas según uno o varios roles autorizados.
- [x] Agregar métodos de conveniencia en User.php:
    - [x] `hasRole(string|array $roles): bool`
    - [x] `isAdmin(): bool` (AD-01)
    - [x] `isDirector(): bool` (DIR-01)
    - [x] `isWarehouse(): bool` (PAN-01)
    - [x] `isTeacher(): bool` (PRO-01)
- [x] Registrar el alias del middleware en `bootstrap/app.php` (ej. `'role' => \App\Http\Middleware\CheckRole::class`).

#### 4. Seguridad OWASP y Mitigación de Fuerza Bruta (REQ-NF-01 / REQ-NF-02)
- [x] Implementar Rate Limiting / Throttling en `POST /api/login` (máximo 5 intentos por minuto por IP + email) para evitar ataques de fuerza bruta.
- [x] Retornar cabecera `Retry-After` y respuesta JSON clara con código 429 Too Many Requests cuando se supere el límite.
- [x] Garantizar que no exista fuga de información en el login: mensaje unificado de credenciales incorrectas tanto si el correo no existe como si la contraseña es errónea (prevención de user enumeration).

#### 5. Gestión del Perfil de Usuario Autenticado (`/api/me`)
- [x] Crear `UserResource` (`app/Http/Resources/UserResource.php`) para serializar limpiamente el usuario (ocultar campos internos y asegurar estructura consistente de roles y fechas).
- [x] Reemplazar la función anónima `Route::get('/user')` por un endpoint formal `GET /api/me` (o `GET /api/user`) que devuelva el recurso del usuario autenticado con su rol, área, último login y estado.

#### 6. Ciclo de Vida y Revocación de Tokens
- [x] Ajustar la configuración de expiración de Sanctum (`config/sanctum.php`): asegurar que `'expiration' => null` si se utiliza el campo `expires_at` por token individual (para que los 60 días de mobile y las 8 horas de web no se sobrescriban con el valor global).
- [x] Endpoint `POST /api/tokens/revoke-others` o `POST /api/logout-all` para revocar todas las sesiones del usuario (útil si se sospecha compromiso de credenciales o pérdida de dispositivo).
- [x] Programar en `routes/console.php` la ejecución periódica del comando `sanctum:prune-expired` para eliminar tokens expirados de la base de datos automáticamente.

#### 7. Seguridad de Credenciales y Cambio de Contraseña
- [x] Endpoint `PUT /api/password` (o `POST /api/change-password`) para que el usuario autenticado cambie su contraseña.
- [x] Crear FormRequest `ChangePasswordRequest` con validación de:
    - `current_password` (validando la contraseña actual).
    - `password` (nueva contraseña con reglas seguras de Laravel: `Password::min(8)->letters()->numbers()`).
    - `password_confirmation`.
- [x] Revocar los tokens anteriores tras un cambio de contraseña por motivos de seguridad.

#### 8. Pruebas Automatizadas de Autenticación (Testing Suite)
- [x] Crear suite de pruebas de integración para autenticación (`tests/Feature/AuthTest.php`):
    - [x] Test login exitoso para cliente `web` (token expira en 8 horas, crea `LoginRecord`, actualiza `last_login_at`).
    - [x] Test login exitoso para cliente `mobile` (token expira en 60 días).
    - [x] Test login con credenciales inválidas (retorna 401).
    - [x] Test bloqueo por Rate Limiting tras múltiples intentos fallidos (retorna 429).
    - [x] Test rechazo de login para usuario con `is_active === false` (retorna 403).
    - [x] Test middleware `EnsureUserIsActive` bloquea peticiones de usuario desactivado aún con Bearer token válido (retorna 403).
    - [x] Test `POST /api/logout` elimina el token actual.
    - [x] Test listado y revocación de tokens por dispositivo (`GET /api/tokens`, `DELETE /api/tokens/{id}`).
    - [x] Test endpoint `GET /api/me` devuelve los datos correctos del usuario autenticado.
    - [x] Test middleware de roles `CheckRole` permite acceso a rol correcto y rechaza con 403 a roles no autorizados.
