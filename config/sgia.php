<?php

return [

    // Contraseña del administrador inicial. En producción no se siembra sin ella.
    'seed_admin_password' => env('SEED_ADMIN_PASSWORD'),

    // Mínimo recomendado de proveedores que responden una cotización antes de comprar (no bloquea: pide un motivo).
    'min_quotations' => (int) env('MIN_QUOTATIONS', 3),

];
