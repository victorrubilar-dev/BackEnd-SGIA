<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Tamaño de página de los listados: `per_page` entre 1 y 100 (15 por defecto).
     */
    protected function perPage(Request $request): int
    {
        return max(1, min(100, $request->integer('per_page', 15)));
    }
}
