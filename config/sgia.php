<?php

return [

    // Contraseña del administrador inicial. En producción no se siembra sin ella.
    'seed_admin_password' => env('SEED_ADMIN_PASSWORD'),

    // Mínimo recomendado de proveedores que responden una cotización antes de comprar (no bloquea: pide un motivo).
    'min_quotations' => (int) env('MIN_QUOTATIONS', 3),

    // URL del panel web, para los enlaces de los correos (docs/suscripcion-reportes.md).
    'frontend_url' => rtrim((string) env('FRONTEND_URL', env('APP_URL', 'http://localhost:5173')), '/'),

];
