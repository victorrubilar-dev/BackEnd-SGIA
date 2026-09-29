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

Tareas concretas:
- [x] Antes de inicar las tareas el usuario debe tener como campos adicionales:
    - Ultimo inicio de sesión
    - los siguientes campos deben estar en una tabla normaizada: 
    - Navegador / movil
    - Fecha de inicio de sesión
    - IP
- [x] Crear campos necesarios para el usuario como area y rol 
- [x] Instalar y configurar Sanctum solo para **API tokens** (no usar el middleware `EnsureFrontendRequestsAreStateful`, ya que no habrá flujo de cookies/CSRF).
- [x] En `POST /api/login`, validar credenciales y emitir el token con `$user->createToken($deviceName)->plainTextToken`, donde `$deviceName` identifica el cliente (`web`, `mobile`) para poder listar/revocar sesiones por dispositivo si se requiere.
- [x] Definir expiración de tokens en `config/sanctum.php` (`expiration`); considerar tokens más largos para mobile (el docente no debería re-loguearse constantemente) y más cortos para web.
- [x] `POST /api/logout` → `$request->user()->currentAccessToken()->delete()`.
- [x] (Opcional) Endpoint para que un usuario liste y revoque sus tokens activos por dispositivo (útil si un docente pierde el celular).

**Configuración de CORS (`config/cors.php`):**
- [ ] `paths` → `['api/*']`.
- [ ] `allowed_origins` → dominio(s) exacto(s) donde se sirva el panel web (ej. `https://sgia-web.inacap-temuco.cl`). No usar `*` en producción.
- [ ] `allowed_methods` → `['GET','POST','PATCH','PUT','DELETE','OPTIONS']`.
- [ ] `allowed_headers` → incluir `Authorization`, `Content-Type`, `Accept`.
- [ ] `supports_credentials` → `false` (no se usan cookies para auth, así que no hace falta habilitar credenciales cross-origin, lo que simplifica la configuración).
- [ ] Mobile (React Native/Expo) no pasa por el navegador en producción, por lo que **no está sujeto a CORS**; solo puede aplicar en desarrollo si se prueba la app en modo web de Expo — en ese caso agregar también ese origen de desarrollo a `allowed_origins` (idealmente solo en el entorno local, no en producción).


### REQ-01 — Autenticación por rol (FU-01)
- [ ] Endpoint `POST /api/login` (correo + contraseña) que devuelva token Bearer vía Sanctum (ver sección 5.1).
- [ ] Middleware `auth:sanctum` en todas las rutas protegidas; rechazar usuarios desactivados (chequeo adicional en el `User` model o en un middleware propio, Sanctum no lo hace por defecto).
- [ ] Endpoint `POST /api/logout` que revoque el token actual.
- [ ] Devolver en la respuesta de login el rol del usuario para que el cliente adapte su UI/navegación.
