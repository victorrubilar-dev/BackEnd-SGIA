# Auditoría de seguridad (noviembre 2026)

Estado: **plan, en ejecución** (2026-11-22). Alcance: API Laravel (`BackEnd-SGIA`) y panel web + app móvil (`FrondEnd-SGIA`).

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

- [ ] **B1. Configuración.** `APP_DEBUG`/`APP_ENV`/`LOG_LEVEL` y `.env.example`, CORS, expiración de tokens Sanctum, cookies/sesión, cabeceras de seguridad, `/up` y `/api/health`.
- [ ] **B2. Manejo de errores y fuga de información.** Render de excepciones no controladas (`QueryException`, `Throwable`) en `api/*`: con `APP_DEBUG=true` Laravel devuelve el SQL, la clase, el archivo y la traza. IDs no numéricos en rutas con model binding (PostgreSQL `22P02`). Errores de GraphQL (`/api/metrics`). Usos de `$e->getMessage()` en respuestas.
- [ ] **B3. Autenticación.** Throttle de login, enumeración de usuarios (mensajes distintos para correo inexistente / contraseña / cuenta inactiva), política de contraseñas, expiración y revocación de tokens, usuarios desactivados.
- [ ] **B4. Autorización.** Rutas sin middleware de rol (`/locations`, `/cajones`, `/estantes`, `/zonas`, `/products`, `/equipment-units`, `/equipment/*`, `/incident-reports/*`): revisar que la Policy cubra cada acción. IDOR: informes de otro docente, préstamos de otro pañol, gráficos y plantillas de otro director, tokens y dispositivos de otro usuario.
- [ ] **B5. Validación e inyección.** SQL crudo, parámetros de orden/filtro, asignación masiva (`$fillable`/`$guarded`), comodines en `LIKE`, tamaños máximos, `per_page` sin tope.
- [ ] **B6. Archivos.** Subidas (mimes, tamaño, nombre), disco privado, descargas (path traversal, `Content-Disposition`, `Content-Type`).
- [ ] **B7. PDF y correo.** `{!! !!}` en Blade, `isRemoteEnabled` de DomPDF (SSRF), datos del usuario en correos a proveedores.
- [ ] **B8. Dependencias.** `composer audit`.

### Frontend

- [ ] **F1. Mensajes indebidos en pantalla.** `apiErrorToMessage` muestra `body.message` de cualquier estado (incluidos 5xx) y `error.message` de errores de JavaScript; `metrics.ts` muestra el primer error de GraphQL tal cual; error boundaries y pantallas de error de rutas. Barrido dinámico de todas las páginas por rol buscando textos técnicos (`SQLSTATE`, `select `, `Exception`, `Illuminate`, `vendor/`, `Cannot query field`, `Failed to fetch`, `undefined`, `NaN`, `[object Object]`, trazas).
- [ ] **F2. XSS.** `dangerouslySetInnerHTML`, `innerHTML`, `href`/`src` con datos del usuario (`javascript:`), `window.open`, SVG del mapa de salas con textos del usuario.
- [ ] **F3. Sesión y token.** Almacenamiento del token (web `localStorage`, móvil), limpieza al cerrar sesión y en 401, datos cacheados de React Query entre usuarios.
- [ ] **F4. Secretos en el bundle.** Variables `VITE_*`/`EXPO_PUBLIC_*`; el atajo de login de desarrollo y su contraseña no deben llegar al build de producción.
- [ ] **F5. Rutas y redirecciones.** Guardas por rol (solo UX: la autorización real es del backend), redirecciones abiertas tras el login.
- [ ] **F6. Dependencias.** `pnpm audit`.

## Criterios

- **Alta:** expone datos de otro usuario/rol, permite escalar privilegios o ejecutar código/SQL, o muestra SQL/trazas al usuario en producción.
- **Media:** fuga de detalles internos solo en desarrollo pero en un camino común, falta de límites que facilita abuso, mensajes técnicos visibles al usuario.
- **Baja / Info:** endurecimiento recomendado sin explotación directa.

Cada corrección lleva test (Feature en el backend, Vitest en el frontend) y commit por fase.

## Fuera de alcance

Infraestructura de producción (servidor, TLS, firewall), pruebas de carga/DoS, ingeniería social, AWS Textract.

## Hallazgos

_(se completa al ejecutar)_
