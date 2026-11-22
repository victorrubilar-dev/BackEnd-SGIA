# Auditoría de seguridad (noviembre 2026)

Estado: **ejecutada** (2026-11-22). Rama `auditoria-seguridad` en el backend. Alcance: API Laravel (`BackEnd-SGIA`) y panel web + app móvil (`FrondEnd-SGIA`).

Los hallazgos se registran al final, en [Hallazgos](#hallazgos), con severidad (Alta / Media / Baja / Info), evidencia y estado (corregido, pendiente, aceptado).

## Objetivo

1. Encontrar fugas de información: mensajes que la interfaz muestra y no debería (errores SQL, nombres de tablas o clases, rutas de archivos, trazas, mensajes del motor GraphQL, textos técnicos en inglés).
2. Verificar autenticación, autorización por rol y por recurso (IDOR), validación de entrada, manejo de archivos y configuración.
3. Corregir lo de severidad Alta y Media con test; documentar el resto.

## Método

- **Estático:** lectura de código y búsquedas dirigidas (`getMessage()`, `DB::raw`, `whereRaw`, `orderByRaw`, `$guarded = []`, `{!! !!}`, `dangerouslySetInnerHTML`, `error.message`, etc.).
- **Dinámico:** API real de desarrollo (`php artisan serve`) con usuarios de cada rol; el web en un Vite propio (`:5199`) manejado por Chromium headless (CDP) con el login rápido de desarrollo. Se fuerzan errores (IDs no numéricos, parámetros inválidos, recursos de otro usuario, BD/servidor caídos) y se revisa el texto visible de cada página.
- **Dependencias:** `composer audit` y `pnpm audit`.

## Fases

### Backend

- [x] **B1. Configuración.** `APP_DEBUG`/`APP_ENV`/`LOG_LEVEL` y `.env.example`, CORS, expiración de tokens Sanctum, cookies/sesión, cabeceras de seguridad, `/up` y `/api/health`.
- [x] **B2. Manejo de errores y fuga de información.** Render de excepciones no controladas (`QueryException`, `Throwable`) en `api/*`: con `APP_DEBUG=true` Laravel devuelve el SQL, la clase, el archivo y la traza. IDs no numéricos en rutas con model binding (PostgreSQL `22P02`). Errores de GraphQL (`/api/metrics`). Usos de `$e->getMessage()` en respuestas.
- [x] **B3. Autenticación.** Throttle de login, enumeración de usuarios (mensajes distintos para correo inexistente / contraseña / cuenta inactiva), política de contraseñas, expiración y revocación de tokens, usuarios desactivados.
- [x] **B4. Autorización.** Rutas sin middleware de rol (`/locations`, `/cajones`, `/estantes`, `/zonas`, `/products`, `/equipment-units`, `/equipment/*`, `/incident-reports/*`): revisar que la Policy cubra cada acción. IDOR: informes de otro docente, préstamos de otro pañol, gráficos y plantillas de otro director, tokens y dispositivos de otro usuario.
- [x] **B5. Validación e inyección.** SQL crudo, parámetros de orden/filtro, asignación masiva (`$fillable`/`$guarded`), comodines en `LIKE`, tamaños máximos, `per_page` sin tope.
- [x] **B6. Archivos.** Subidas (mimes, tamaño, nombre), disco privado, descargas (path traversal, `Content-Disposition`, `Content-Type`).
- [x] **B7. PDF y correo.** `{!! !!}` en Blade, `isRemoteEnabled` de DomPDF (SSRF), datos del usuario en correos a proveedores.
- [x] **B8. Dependencias.** `composer audit`.

### Frontend

- [x] **F1. Mensajes indebidos en pantalla.** `apiErrorToMessage` muestra `body.message` de cualquier estado (incluidos 5xx) y `error.message` de errores de JavaScript; `metrics.ts` muestra el primer error de GraphQL tal cual; error boundaries y pantallas de error de rutas. Barrido dinámico de todas las páginas por rol buscando textos técnicos (`SQLSTATE`, `select `, `Exception`, `Illuminate`, `vendor/`, `Cannot query field`, `Failed to fetch`, `undefined`, `NaN`, `[object Object]`, trazas).
- [x] **F2. XSS.** `dangerouslySetInnerHTML`, `innerHTML`, `href`/`src` con datos del usuario (`javascript:`), `window.open`, SVG del mapa de salas con textos del usuario.
- [x] **F3. Sesión y token.** Almacenamiento del token (web `localStorage`, móvil), limpieza al cerrar sesión y en 401, datos cacheados de React Query entre usuarios.
- [x] **F4. Secretos en el bundle.** Variables `VITE_*`/`EXPO_PUBLIC_*`; el atajo de login de desarrollo y su contraseña no deben llegar al build de producción.
- [x] **F5. Rutas y redirecciones.** Guardas por rol (solo UX: la autorización real es del backend), redirecciones abiertas tras el login.
- [x] **F6. Dependencias.** `pnpm audit`.

## Criterios

- **Alta:** expone datos de otro usuario/rol, permite escalar privilegios o ejecutar código/SQL, o muestra SQL/trazas al usuario en producción.
- **Media:** fuga de detalles internos solo en desarrollo pero en un camino común, falta de límites que facilita abuso, mensajes técnicos visibles al usuario.
- **Baja / Info:** endurecimiento recomendado sin explotación directa.

Cada corrección lleva test (Feature en el backend, Vitest en el frontend) y commit por fase.

## Fuera de alcance

Infraestructura de producción (servidor, TLS, firewall), pruebas de carga/DoS, ingeniería social, AWS Textract.

## Hallazgos

Ejecutada el 2026-11-22. Sonda automática: todas las rutas de `routes/api.php` × los 4 roles × IDs inválidos (`abc`, `1'`, `1.5`, `-1`, `99999999999999999999`) y 17 variantes de query string (`per_page=abc`, `status[]=a`, `search=%'`, …). Barrido web: 44 rutas × 4 roles en Chromium headless.

| # | Sev. | Hallazgo | Estado |
|---|---|---|---|
| H-01 | **Alta** | Con `APP_DEBUG=true`, todo error no controlado de la API devolvía el mensaje de PostgreSQL con **host, puerto, nombre de la BD y la consulta SQL**, más la clase, el archivo, la línea y la traza. La sonda encontró **1.853 respuestas 500** así: 1.812 por IDs no numéricos o fuera de rango en las 26 rutas con ID (`SQLSTATE[22P02]` / `[22003]`), 25 por parámetros `int` (`/devices/{id}`, `/tokens/{tokenId}`: `TypeError` con la ruta de `vendor/`) y 16 por filtros con arreglo (`status[]=a`: `Array to string conversion`). En producción (`APP_DEBUG=false`) el mismo caso respondía `"Server Error"` en inglés. | Corregido |
| H-02 | **Alta** | El frontend mostraba tal cual el `message` de cualquier respuesta, incluidos los 5xx (`apiErrorToMessage`): el SQL de H-01 llegaba al toast o a la pantalla. Barrido con 500 inyectados (cuerpo como el de H-01) sobre el código anterior: **`/monitoreo` (AD-01), `/mapa-salas` (AD-01, DIR-01) y `/analitica` (DIR-01) mostraban `SQLSTATE[22P02] … SQL: select * from "products" …`** en pantalla. También mostraba el texto de errores de JavaScript y de red (`Failed to fetch`, `Unexpected token '<'…`, `Cannot read properties of undefined`). | Corregido |
| H-03 | **Alta** | XSS almacenado en la impresión de etiqueta del producto: `barcode` aceptaba cualquier texto (máx. 50) y `producto-detail-dialog.tsx` lo escribía sin escapar con `document.write` en una ventana del mismo origen (acceso al token de `localStorage`). P. ej. `<img src=x onerror=…>` creado por PAN-01/DIR-01 se ejecuta al imprimir la etiqueta otro usuario. | Corregido |
| H-04 | Media | `ErrorBoundary` mostraba `error.message` (texto técnico de JavaScript) en producción. | Corregido |
| H-05 | Media | Dashboards: el primer error de GraphQL se mostraba tal cual; los de sintaxis/validación del motor salen en inglés y describen el esquema (`Cannot query field "x" on type "Query"`). | Corregido |
| H-06 | Media | Dependencias PHP con avisos: `symfony/routing` (CVE-2026-118516), `symfony/http-foundation` (CVE-2026-121619, CVE-2026-118514 y 2 sin CVE), `symfony/http-kernel` (CVE-2026-123339), `symfony/filesystem`. | Corregido (7.4.23) |
| H-07 | Baja | 429 (`Too Many Attempts.`) y 405 (`The PUT method is not supported…`) en inglés y con `exception`/`file`/`trace`. | Corregido |
| H-08 | Baja | El model binding corre antes del middleware de rol: un rol sin acceso recibe 404 si el ID no existe y 403 si existe (enumeración de IDs). | Corregido |
| H-09 | Baja | Filtros que esperan texto reciben arreglos (`?status[]=a` en `/equipment-units`, `/incident-reports`, `/loans`, `/purchases`, `/quotations`): respondía 500. | Corregido (422) |
| H-10 | Baja | `pnpm audit --prod`: 26 avisos (1 crítico, 10 altos). Todos menos uno están en herramientas de Expo CLI (`node-forge`, `shell-quote`, `glob`, `minimatch`, `source-map-js`), que no llegan al bundle. `zod@4.6.5` (móvil) sí se empaqueta: DoS de memoria, corregido en ≥4.6.6. | `zod` corregido (^4.6.7); Expo CLI aceptado hasta actualizar el SDK |
| H-11 | Info | Backend sin `.env.example`; el `.env` local tiene `APP_DEBUG=true` y `LOG_LEVEL=debug`. Producción: `APP_ENV=production`, `APP_DEBUG=false`. Con H-01 la API ya no filtra datos aunque quede activo, pero las páginas web de Laravel (`/`) seguirían mostrando la página de error de depuración. | Corregido (`.env.example` de producción) |
| H-12 | Info | La API no envía cabeceras de seguridad (`X-Content-Type-Options: nosniff`, `X-Frame-Options`, `Referrer-Policy`) y el web no tiene CSP. Configurarlas en el servidor web de producción; una CSP mitigaría H-03 y cualquier XSS futuro. | Cabeceras de la API corregidas; CSP del web pendiente (servidor de producción) |
| H-13 | Info | Búsquedas con `ilike` sin escapar `%` y `_` (solo cambia los resultados, los valores van como parámetros). | Aceptado |
| H-14 | Info | Tests no aislados: `LoginRateLimitTest`, `ActiveUserTest` y otros 5 sin `DatabaseTransactions` dejan filas en `failed_logins`, y `MonitoringTest::test_login_activity_summarizes_by_hour_and_flags_accounts_and_ips` falla si la suite corre dos veces en la misma hora. `RealisticDemoSeederTest` requiere `pruebas-facturas/prueba2.jpeg`, que no está en el repo. | Corregido |

### Correcciones

- **H-01** (`bootstrap/app.php`): render final para `api/*` que responde `{"message": "Ocurrió un error inesperado. Intenta nuevamente o contacta al administrador."}` (o el texto de 503) a cualquier excepción no controlada, sin importar `APP_DEBUG`; los `abort(4xx, '…')` propios conservan su mensaje sin campos de depuración; 429 y 405 con texto en español. El detalle sigue en el log. `AppServiceProvider` fija `Route::patterns` (`[0-9]{1,18}`) para los 26 parámetros de ID: un ID inválido es 404 antes de llegar a PostgreSQL. Test: `tests/Feature/ErrorLeakTest.php`. Tras la corrección la sonda deja 0 respuestas con detalles internos.
- **H-02** (`packages/api-client/src/http.ts`): `apiErrorToMessage` nunca muestra el cuerpo de un 5xx, traduce errores de red y oculta los errores de JavaScript (solo muestra `Error` planos con texto propio); `configureApiClient({ debug })` registra en consola lo oculto solo en desarrollo. Móvil: `login-screen.tsx` usa lo mismo. Test: `apps/web/src/lib/api-error.test.ts`.
- **H-03**: `barcode` solo admite `[A-Za-z0-9._-]` (`StoreProductRequest`, `UpdateProductRequest`; los 32 códigos existentes cumplen) y la ventana de impresión escapa el texto. Test: `ProductManagementTest::test_barcode_rejects_html_and_unsafe_characters`.
- **H-04**: `ErrorBoundary` muestra un texto fijo; el detalle técnico solo con `import.meta.env.DEV`.
- **H-05**: `graphQLErrorMessage` (`services/metrics.ts`) solo deja pasar errores de resolver (`path`, los `UserError` en español del backend). Test: `apps/web/src/lib/graphql-error.test.ts`.
- **H-06**: `composer update symfony/{routing,http-foundation,http-kernel,filesystem}` → `composer audit` sin avisos.
- **H-08** (`bootstrap/app.php`): `EnsureUserIsActive` y `CheckRole` van antes de `SubstituteBindings` en la prioridad de middleware: un rol sin acceso recibe 403 exista o no el ID; quien sí tiene acceso sigue viendo 404. `InvoiceOverBudgetTraceTest` se ajustó a ese contrato.
- **H-09** (`RejectArrayQueryParameters`, grupo `api`): ningún endpoint acepta arreglos en la query; `?status[]=a` responde 422 con `errors`.
- **H-11**: `.env.example` con valores de producción (`APP_DEBUG=false`, `LOG_LEVEL=warning`, `SESSION_ENCRYPT=true`) y secretos vacíos.
- **H-12** (`SecurityHeaders`, grupo `api`): `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: no-referrer`, `Cross-Origin-Resource-Policy: same-site`. La CSP del panel web va en el servidor que lo publique (el dev server de Vite usa scripts en línea); propuesta: `default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; connect-src 'self' <URL de la API>; frame-ancestors 'none'; object-src 'none'; base-uri 'self'`.
- **H-14**: `MonitoringTest` borra `failed_logins` dentro de su transacción; `RealisticDemoSeederTest` se omite si falta `pruebas-facturas/`.
- Tests: `tests/Feature/SecurityHardeningTest.php`. Suite: 686 en verde, 1 omitido. La sonda repetida al final da **0** respuestas 5xx o con detalles internos (antes 1.853).
- Frontend: `FrondEnd-SGIA/FIXES/CHANGELOG-FIXES.md` (FIX-054).

### Revisado sin hallazgos

- **Autenticación:** mismo mensaje para correo inexistente y contraseña errónea; "cuenta desactivada" solo tras validar la contraseña; throttle 5/min por correo+IP con registro en `failed_logins`; tokens con vencimiento (8 h web, 60 días móvil); `password` y `remember_token` ocultos; política de contraseña (8, letras y números).
- **Autorización / IDOR:** rutas sin middleware de rol cubiertas por Policy o FormRequest (`products`, `equipment-units`, `incident-reports`, …); `devices` y `tokens` filtrados por el usuario; informes de novedades visibles solo para quien los reportó o para DIR-01/PAN-01; préstamos por pañol (`LoanStoreroomAccessTest`).
- **Inyección SQL:** todo `whereRaw`/`selectRaw`/`DB::raw` usa constantes o enlaces (`?`); `per_page` acotado a 100 (200 en estantes).
- **Archivos:** `mimes` + `max:10240` en todas las subidas, disco privado, descargas como adjunto autenticadas; firma validada como PNG ≤512 KB.
- **PDF / correo:** sin `{!! !!}` en Blade; DomPDF con `isRemoteEnabled=false`.
- **GraphQL:** profundidad 6, complejidad 200, introspección desactivada en producción, errores internos como `Internal server error`.
- **Frontend:** sin `dangerouslySetInnerHTML` con datos del usuario (solo SVG de código de barras generado por el backend); la contraseña del login rápido solo se lee con `import.meta.env.DEV` (Vite la elimina del build); el móvil guarda el token en `SecureStore`.
